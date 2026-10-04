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
 * Reads website snapshots + open incident severity only. Never touches
 * URLs, IPs, keywords, domains, rules, scores, snapshots, headers.
 *
 * Projects a SINGLE {@see StatusPage}: a page never exposes another page's
 * websites (ADR-031). A website with `status_page_id IS NULL` belongs to the
 * default page only.
 */
final class StatusProjector
{
    public function project(StatusPage $page, ?Carbon $now = null): PublicStatusDTO
    {
        $now ??= Carbon::now('UTC');

        /** @var Collection<int, Website> $websites */
        $websites = $this->publishedQuery($page)->get();

        $openSeverities = $this->openSeverities($websites);

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

            $services[] = $row;
            $sortIndex++;
        }

        return new PublicStatusDTO(
            banner: $this->banner($services),
            services: $services,
            updatedDayBucket: $now->copy()->setTimezone('UTC')->format('Y-m-d'),
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
        $slow = (int) config('sentinel.status_page.band_slow', 2500);

        if ($ms <= $normal) {
            return 'normal';
        }

        if ($ms <= $slow) {
            return 'slow';
        }

        return 'slow';
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
