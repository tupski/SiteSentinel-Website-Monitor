<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

use App\Models\Check;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Public-safe projection chokepoint (STATUS-PAGE.md §4-§6).
 *
 * Reads website snapshots + open incident severity + bucketed availability
 * only. Never touches URLs, IPs, keywords, domains, rules, scores, snapshots,
 * headers, or the exact response time.
 *
 * Projects a SINGLE {@see StatusPage}: a page never exposes another page's
 * websites (ADR-031). A website with `status_page_id IS NULL` belongs to the
 * default page only.
 *
 * The availability history (§4.3, FR-79) is expressed ONLY as per-bucket
 * availability counts/percentages — the same public-safe shape the internal
 * analytics uses. The exact `checks.duration_ms` figure is NEVER emitted
 * (§4.3: "The exact millisecond value is never emitted"); the response-time
 * signal is a coarse band plus a rounded millisecond figure (§4.3).
 */
final class StatusProjector
{
    public function project(StatusPage $page, ?Carbon $now = null, string $period = PublicStatusPeriod::DEFAULT): PublicStatusDTO
    {
        $now ??= Carbon::now('UTC');
        $period = PublicStatusPeriod::resolve($period);

        /** @var Collection<int, Website> $websites */
        $websites = $this->publishedQuery($page)->get();

        $openSeverities = $this->openSeverities($websites);
        $history = $this->availabilityHistory($websites, $period, $now);
        // The response-time metrics are read over the SAME window as the
        // availability history, in one batched query (never per-website).
        $responses = $this->responseMetrics($websites, $period, $now);

        $services = [];
        $sortIndex = 0;
        foreach ($websites as $website) {
            $label = $this->deriveLabel($website, $openSeverities[$website->id] ?? null, $now);
            $display = trim((string) ($website->status_alias ?: $website->name));
            if ($display === '') {
                $display = 'Service';
            }

            $row = [
                'opaqueIndex' => 's-'.($sortIndex + 1),
                'displayName' => $display,
                'publicLabel' => $label,
                'dayBucket' => $this->dayBucket($website->last_checked_at ?? $now),
                'sortIndex' => $sortIndex,
            ];

            $band = $this->responseBand($website);
            if ($band !== null) {
                $row['responseBand'] = $band;
            }

            // Coarse response-time signal for the public chart: a rounded
            // millisecond figure (never the exact value) and a coarse time
            // bucket. Omitted entirely when no timed check exists.
            $response = $responses[$website->id] ?? null;
            if ($response !== null) {
                $row['responseMs'] = $response['responseMs'];
                $row['checkedAt'] = $response['checkedAt'];
            }

            $perWebsite = $history[$website->id] ?? null;
            if ($perWebsite !== null) {
                $row['uptime'] = $perWebsite['uptime'];
                $row['history'] = $perWebsite['history'];
            }

            $services[] = $row;
            $sortIndex++;
        }

        $utcNow = $now->copy()->setTimezone('UTC');

        return new PublicStatusDTO(
            banner: $this->banner($services),
            services: $services,
            // The coarse day bucket and the precise ISO-8601 stamp are derived
            // from the SAME `$now` (the projection generation time) so they can
            // never disagree (STATUS-PAGE.md §8.1, ADR-040). The stamp is the
            // projection time only — never an incident or check timestamp.
            updatedDayBucket: $utcNow->format('Y-m-d'),
            updatedAt: $utcNow->format('Y-m-d\TH:i:s\Z'),
            period: $period,
            periodLabel: PublicStatusPeriod::label($period),
        );
    }

    /**
     * Websites that belong to exactly this page. The default page also owns
     * every website without an explicit assignment; a non-default page only
     * owns its explicitly-assigned websites.
     *
     * @return Builder<Website>
     */
    public function publishedQuery(StatusPage $page): Builder
    {
        $query = Website::query()
            ->where('is_active', true)
            ->where('is_visible_on_status', true);

        if ($page->is_default) {
            $query->where(function (Builder $q) use ($page): void {
                $q->where('status_page_id', $page->id)->orWhereNull('status_page_id');
            });
        } else {
            $query->where('status_page_id', $page->id);
        }

        return $query
            ->orderBy('status_alias')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, Website>  $websites
     * @return array<int, string|null>
     */
    private function openSeverities(Collection $websites): array
    {
        if ($websites->isEmpty()) {
            return [];
        }

        $ids = $websites->pluck('id')->all();

        /** @var Collection<int, Incident> $open */
        $open = Incident::query()
            ->whereIn('website_id', $ids)
            ->whereIn('status', ['DETECTED', 'ACKNOWLEDGED'])
            ->orderByDesc('id')
            ->get();

        $rank = ['INFO' => 0, 'WARNING' => 1, 'CRITICAL' => 2];
        $worst = [];
        foreach ($open as $incident) {
            $id = (int) $incident->website_id;
            $sev = (string) $incident->severity;
            if (! isset($worst[$id]) || ($rank[$sev] ?? 0) > ($rank[$worst[$id]] ?? -1)) {
                $worst[$id] = $sev;
            }
        }

        return $worst;
    }

    /**
     * Per-website public-safe availability history + uptime over the period.
     *
     * Only `availability_state` (UP/DOWN) is read from `checks`; the exact
     * duration is never selected. Buckets with no observation are omitted so a
     * gap is never fabricated as a 0% bar. When a website has no availability
     * observation at all the uptime is reported as unavailable (`available =
     * false`) and the history is empty — the view renders an explicit
     * "no data" state rather than a misleading 0%.
     *
     * @param  Collection<int, Website>  $websites
     * @return array<int, array{uptime: array{available: bool, percent: float|null, up: int, down: int, total: int}, history: list<array{label: string, value: float, up: int, total: int}>}>
     */
    private function availabilityHistory(Collection $websites, string $period, Carbon $now): array
    {
        if ($websites->isEmpty()) {
            return [];
        }

        $start = PublicStatusPeriod::start($period, $now);

        /** @var Collection<int, Check> $checks */
        $checks = Check::query()
            ->whereIn('website_id', $websites->pluck('id')->all())
            ->where('started_at', '>=', $start)
            ->where('started_at', '<=', $now->copy()->setTimezone('UTC'))
            ->orderBy('started_at')
            ->get(['id', 'website_id', 'started_at', 'availability_state']);

        $byWebsite = $checks->groupBy('website_id');

        $result = [];
        foreach ($websites as $website) {
            /** @var Collection<int, Check> $rows */
            $rows = $byWebsite->get($website->id, collect());

            $result[$website->id] = [
                'uptime' => $this->uptime($rows),
                'history' => $this->bucketedSeries($rows, $period),
            ];
        }

        return $result;
    }

    /**
     * Per-website coarse response-time metrics over the SAME window as the
     * availability history. Returns, per website, a COARSENED millisecond
     * figure — rounded to the nearest `response_round_ms` (default 50 ms) so
     * the exact `checks.duration_ms` is NEVER emitted (§4.3) — plus a coarse
     * UTC time bucket for the most recent timed check.
     *
     * Only websites with at least one timed check in the window appear. When a
     * website has no timed check the key is absent, so the chart omits it
     * rather than fabricating a bar. One batched query — never per-website.
     *
     * @param  Collection<int, Website>  $websites
     * @return array<int, array{responseMs: int, checkedAt: string}>
     */
    private function responseMetrics(Collection $websites, string $period, Carbon $now): array
    {
        if ($websites->isEmpty()) {
            return [];
        }

        $start = PublicStatusPeriod::start($period, $now);

        /** @var Collection<int, Check> $checks */
        $checks = Check::query()
            ->whereIn('website_id', $websites->pluck('id')->all())
            ->whereNotNull('duration_ms')
            ->where('started_at', '>=', $start)
            ->where('started_at', '<=', $now->copy()->setTimezone('UTC'))
            ->orderBy('started_at')
            ->get(['id', 'website_id', 'started_at', 'duration_ms']);

        $round = max(1, (int) config('sentinel.status_page.response_round_ms', 50));

        $result = [];
        foreach ($checks as $check) {
            $at = $check->started_at;
            if ($at === null || $check->duration_ms === null) {
                continue;
            }

            // `orderBy('started_at')` ascending means the last write per website
            // is the most recent timed check. The duration is rounded to the
            // nearest `response_round_ms` so the exact figure is never emitted.
            $result[(int) $check->website_id] = [
                'responseMs' => (int) (round((float) $check->duration_ms / $round) * $round),
                'checkedAt' => $this->compactAt($at, $period),
            ];
        }

        return $result;
    }

    /**
     * Coarse, public-safe UTC time bucket for a check timestamp — the chart's
     * bottom axis. Hour-granular for the 24h view, day-granular otherwise
     * (matching {@see PublicStatusPeriod::bucketKey}). A check time can never
     * fingerprint the detection cadence more precisely than this.
     */
    private function compactAt(Carbon $at, string $period): string
    {
        return $at->copy()->setTimezone('UTC')->format(
            PublicStatusPeriod::resolve($period) === PublicStatusPeriod::P24H ? 'H:00' : 'Y-m-d'
        );
    }

    /**
     * Sample-based uptime over a set of checks. Returns `available = false`
     * when there is no availability observation to divide by — never 0%.
     *
     * @param  Collection<int, Check>  $checks
     * @return array{available: bool, percent: float|null, up: int, down: int, total: int}
     */
    private function uptime(Collection $checks): array
    {
        $total = 0;
        $up = 0;

        foreach ($checks as $check) {
            $state = $check->availability_state;
            if ($state === null) {
                continue;
            }

            $total++;
            if ($state === 'UP') {
                $up++;
            }
        }

        if ($total === 0) {
            return ['available' => false, 'percent' => null, 'up' => 0, 'down' => 0, 'total' => 0];
        }

        return [
            'available' => true,
            'percent' => round($up / $total * 100, 2),
            'up' => $up,
            'down' => $total - $up,
            'total' => $total,
        ];
    }

    /**
     * Bucketed availability series for one website. Buckets with no
     * observation are omitted (never a fabricated 0% bar).
     *
     * @param  Collection<int, Check>  $checks
     * @return list<array{label: string, value: float, up: int, total: int}>
     */
    private function bucketedSeries(Collection $checks, string $period): array
    {
        $buckets = [];

        foreach ($checks as $check) {
            $state = $check->availability_state;
            $at = $check->started_at;
            if ($state === null || $at === null) {
                continue;
            }

            $key = PublicStatusPeriod::bucketKey($at, $period);
            $buckets[$key] ??= ['up' => 0, 'total' => 0];
            $buckets[$key]['total']++;
            if ($state === 'UP') {
                $buckets[$key]['up']++;
            }
        }

        ksort($buckets);

        $series = [];
        foreach ($buckets as $label => $bucket) {
            $series[] = [
                'label' => (string) $label,
                'value' => $bucket['total'] > 0
                    ? round($bucket['up'] / $bucket['total'] * 100, 1)
                    : 0.0,
                'up' => $bucket['up'],
                'total' => $bucket['total'],
            ];
        }

        return $series;
    }

    private function deriveLabel(Website $website, ?string $openSeverity, Carbon $now): string
    {
        if ($this->isStale($website, $now)) {
            return 'Unknown';
        }

        $avail = (string) ($website->status_availability ?? '');
        $sec = (string) ($website->status_security ?? '');

        if ($avail === 'DOWN') {
            return $openSeverity === 'CRITICAL' ? 'Major Outage' : 'Partial Outage';
        }

        // UP branch: INFO never lifts; SUSPECT -> Degraded; INCIDENT -> Incident.
        if ($sec === 'INCIDENT' || $openSeverity === 'CRITICAL') {
            return 'Incident';
        }

        if ($sec === 'SUSPECT' || $openSeverity === 'WARNING') {
            return 'Degraded';
        }

        return 'Operational';
    }

    private function isStale(Website $website, Carbon $now): bool
    {
        $last = $website->last_checked_at;
        if ($last === null) {
            return true;
        }

        $interval = max(1, (int) $website->check_interval_seconds);
        $mult = (int) config('sentinel.status_page.stale_multiplier', 2);
        $floor = (int) config('sentinel.status_page.stale_floor', 300);
        $threshold = max($mult * $interval, $floor);

        return $last->copy()->setTimezone('UTC')->diffInSeconds($now->copy()->setTimezone('UTC')) > $threshold;
    }

    private function dayBucket(?Carbon $when): string
    {
        if ($when === null) {
            return Carbon::now('UTC')->format('Y-m-d');
        }

        return $when->copy()->setTimezone('UTC')->format('Y-m-d');
    }

    private function responseBand(Website $website): ?string
    {
        $last = $website->last_checked_at;
        if ($last === null) {
            return null;
        }

        $latest = Check::query()
            ->where('website_id', $website->id)
            ->orderByDesc('started_at')
            ->first(['duration_ms']);

        if ($latest === null || $latest->duration_ms === null) {
            return null;
        }

        $ms = (int) $latest->duration_ms;
        $normal = (int) config('sentinel.status_page.band_normal', 800);

        return $ms <= $normal ? 'normal' : 'slow';
    }

    /**
     * @param  array<int, array{publicLabel: string}>  $services
     */
    private function banner(array $services): string
    {
        $rank = [
            'Operational' => 0,
            'Unknown' => 1,
            'Degraded' => 2,
            'Incident' => 3,
            'Partial Outage' => 4,
            'Major Outage' => 5,
        ];

        // No published services => no derivable status. STATUS-PAGE.md §6.3
        // forbids fabricating a label: an empty projection is `Unknown`, never
        // `Operational`.
        if ($services === []) {
            return 'Unknown';
        }

        $worst = 'Operational';
        $worstRank = 0;
        foreach ($services as $row) {
            $r = $rank[$row['publicLabel']] ?? 0;
            if ($r > $worstRank) {
                $worstRank = $r;
                $worst = (string) $row['publicLabel'];
            }
        }

        return $worst;
    }
}
