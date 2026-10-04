<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

use App\Models\Check;
use App\Models\Incident;
use App\Models\StatusPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Internal, admin-only analytics for a single status page (Requirement 23).
 *
 * Every metric is derived from rows that actually exist — persisted `checks`
 * (availability observations + response timings) and `incidents` (lifecycle
 * history). Nothing is fabricated or interpolated: a metric with no
 * observations is reported as UNAVAILABLE (`available = false`) and the view
 * renders an explicit "insufficient data" state rather than a placeholder.
 *
 * Uptime definition (defensible, sample-based)
 * --------------------------------------------
 *   uptime % = (checks with `availability_state = 'UP'`)
 *              / (checks with a non-null `availability_state`)
 *              × 100, over the selected period, for the page's websites.
 *
 * This is the fraction of OBSERVED checks that found the site reachable. It is
 * deliberately NOT a time-weighted uptime (which would require assuming a
 * state between samples and is not stored anywhere); the report says so. When
 * the denominator is zero the metric is "insufficient data", never 0%.
 *
 * Retention reality (ADR-016): `checks` are pruned after
 * `sentinel.retention.checks_days` (default 30). A 90-day period therefore has
 * a checks-based series that only covers the retained window; `incidents` are
 * retained 365 days and fully cover every period. Both limits are reported.
 */
final class StatusPageAnalytics
{
    public const PERIOD_24H = '24h';

    public const PERIOD_7D = '7d';

    public const PERIOD_30D = '30d';

    public const PERIOD_90D = '90d';

    public const DEFAULT_PERIOD = self::PERIOD_7D;

    public function __construct(
        private readonly StatusProjector $projector,
    ) {}

    /**
     * Reporting periods offered to the operator (value => label).
     *
     * @return array<string, string>
     */
    public static function periods(): array
    {
        return [
            self::PERIOD_24H => 'Last 24 hours',
            self::PERIOD_7D => 'Last 7 days',
            self::PERIOD_30D => 'Last 30 days',
            self::PERIOD_90D => 'Last 90 days',
        ];
    }

    /**
     * Validate a raw `?period=` value against the allowlist. Never trust input;
     * an unknown or non-string value falls back to the default.
     */
    public static function resolvePeriod(mixed $raw): string
    {
        if (! is_string($raw)) {
            return self::DEFAULT_PERIOD;
        }

        $value = strtolower(trim($raw));

        return array_key_exists($value, self::periods()) ? $value : self::DEFAULT_PERIOD;
    }

    /**
     * Build the full analytics report for one status page.
     *
     * @return array{
     *     period: string,
     *     periodLabel: string,
     *     periodStart: Carbon,
     *     periodEnd: Carbon,
     *     periodDays: int,
     *     checksRetentionDays: int,
     *     checksRetentionLimited: bool,
     *     websiteCount: int,
     *     overallUptime: array{available: bool, percent: float|null, up: int, down: int, total: int},
     *     websites: list<array{id: int, name: string, uptime: array{available: bool, percent: float|null, up: int, down: int, total: int}}>,
     *     incidents: array{
     *         total: int,
     *         open: int,
     *         bySeverity: array<string, int>,
     *         byStatus: array<string, int>,
     *         byType: array<string, int>,
     *         perDay: float,
     *         perWeek: float,
     *         dailySeries: list<array{label: string, value: int}>
     *     },
     *     availabilitySeries: list<array{label: string, value: float, up: int, total: int}>,
     *     responseTime: array{available: bool, averageMs: int|null, samples: int, series: list<array{label: string, value: int, samples: int}>},
     *     checksCoverageStart: Carbon|null,
     *     hasChecks: bool
     * }
     */
    public function report(StatusPage $page, string $period, ?Carbon $now = null): array
    {
        $period = self::resolvePeriod($period);
        $end = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');
        $start = $this->startFor($period, $end);
        $periodDays = $this->periodDays($period);
        $retentionDays = max(1, (int) config('sentinel.retention.checks_days', 30));

        // The page's websites — the exact set the public page is built from
        // (active + published + assigned; the default page also owns the
        // unassigned websites). Reused, never re-implemented (ADR-031).
        $websites = $this->projector->publishedQuery($page)->get(['id', 'name', 'status_alias']);
        $websiteIds = $websites->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $checks = $websiteIds === [] ? collect() : Check::query()
            ->whereIn('website_id', $websiteIds)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<=', $end)
            ->orderBy('started_at')
            ->get(['id', 'website_id', 'started_at', 'availability_state', 'duration_ms']);

        $incidents = $websiteIds === [] ? collect() : Incident::query()
            ->whereIn('website_id', $websiteIds)
            ->where('detected_at', '>=', $start)
            ->where('detected_at', '<=', $end)
            ->orderBy('detected_at')
            ->get(['id', 'website_id', 'type', 'severity', 'status', 'detected_at']);

        $checksByWebsite = $checks->groupBy('website_id');

        $perWebsite = [];
        foreach ($websites as $website) {
            /** @var Collection<int, Check> $rows */
            $rows = $checksByWebsite->get($website->id, collect());

            $perWebsite[] = [
                'id' => (int) $website->id,
                'name' => $this->displayName($website),
                'uptime' => $this->uptime($rows),
            ];
        }

        return [
            'period' => $period,
            'periodLabel' => self::periods()[$period],
            'periodStart' => $start,
            'periodEnd' => $end,
            'periodDays' => $periodDays,
            'checksRetentionDays' => $retentionDays,
            'checksRetentionLimited' => $periodDays > $retentionDays,
            'websiteCount' => $websites->count(),
            'overallUptime' => $this->uptime($checks),
            'websites' => $perWebsite,
            'incidents' => $this->incidentSummary($incidents, $periodDays),
            'availabilitySeries' => $this->availabilitySeries($checks),
            'responseTime' => $this->responseTime($checks),
            'checksCoverageStart' => $checks->first()?->started_at,
            'hasChecks' => $checks->isNotEmpty(),
        ];
    }

    private function startFor(string $period, Carbon $end): Carbon
    {
        return match ($period) {
            self::PERIOD_24H => $end->copy()->subDay(),
            self::PERIOD_30D => $end->copy()->subDays(30),
            self::PERIOD_90D => $end->copy()->subDays(90),
            default => $end->copy()->subDays(7),
        };
    }

    private function periodDays(string $period): int
    {
        return match ($period) {
            self::PERIOD_24H => 1,
            self::PERIOD_30D => 30,
            self::PERIOD_90D => 90,
            default => 7,
        };
    }

    private function displayName(object $website): string
    {
        $alias = trim((string) ($website->status_alias ?? ''));
        if ($alias !== '') {
            return $alias;
        }

        $name = trim((string) ($website->name ?? ''));

        return $name !== '' ? $name : 'Service';
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
     * @param  Collection<int, Incident>  $incidents
     * @return array{
     *     total: int,
     *     open: int,
     *     bySeverity: array<string, int>,
     *     byStatus: array<string, int>,
     *     byType: array<string, int>,
     *     perDay: float,
     *     perWeek: float,
     *     dailySeries: list<array{label: string, value: int}>
     * }
     */
    private function incidentSummary(Collection $incidents, int $periodDays): array
    {
        $bySeverity = ['INFO' => 0, 'WARNING' => 0, 'CRITICAL' => 0];
        $byStatus = ['DETECTED' => 0, 'ACKNOWLEDGED' => 0, 'RESOLVED' => 0];
        $byType = ['availability' => 0, 'security' => 0];
        $daily = [];

        foreach ($incidents as $incident) {
            $severity = (string) $incident->severity;
            if (array_key_exists($severity, $bySeverity)) {
                $bySeverity[$severity]++;
            }

            $status = (string) $incident->status;
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status]++;
            }

            $type = (string) $incident->type;
            if (array_key_exists($type, $byType)) {
                $byType[$type]++;
            }

            $day = $incident->detected_at?->copy()->setTimezone('UTC')->format('Y-m-d');
            if ($day !== null) {
                $daily[$day] = ($daily[$day] ?? 0) + 1;
            }
        }

        ksort($daily);

        $dailySeries = [];
        foreach ($daily as $day => $count) {
            $dailySeries[] = ['label' => (string) $day, 'value' => $count];
        }

        $total = $incidents->count();
        $days = max(1, $periodDays);

        return [
            'total' => $total,
            'open' => $byStatus['DETECTED'] + $byStatus['ACKNOWLEDGED'],
            'bySeverity' => $bySeverity,
            'byStatus' => $byStatus,
            'byType' => $byType,
            'perDay' => round($total / $days, 2),
            'perWeek' => round($total / ($days / 7), 2),
            'dailySeries' => $dailySeries,
        ];
    }

    /**
     * Per-day availability series from real checks. Days with no checks are
     * omitted (never rendered as a fabricated 0%).
     *
     * @param  Collection<int, Check>  $checks
     * @return list<array{label: string, value: float, up: int, total: int}>
     */
    private function availabilitySeries(Collection $checks): array
    {
        $buckets = [];

        foreach ($checks as $check) {
            $state = $check->availability_state;
            if ($state === null) {
                continue;
            }

            $day = $check->started_at?->copy()->setTimezone('UTC')->format('Y-m-d');
            if ($day === null) {
                continue;
            }

            $buckets[$day] ??= ['up' => 0, 'total' => 0];
            $buckets[$day]['total']++;
            if ($state === 'UP') {
                $buckets[$day]['up']++;
            }
        }

        ksort($buckets);

        $series = [];
        foreach ($buckets as $day => $bucket) {
            $series[] = [
                'label' => (string) $day,
                'value' => $buckets[$day]['total'] > 0
                    ? round($buckets[$day]['up'] / $buckets[$day]['total'] * 100, 1)
                    : 0.0,
                'up' => $bucket['up'],
                'total' => $bucket['total'],
            ];
        }

        return $series;
    }

    /**
     * Response-time trend from `checks.duration_ms`. Available only when at
     * least one timed check exists; otherwise the view says "unavailable".
     *
     * @param  Collection<int, Check>  $checks
     * @return array{available: bool, averageMs: int|null, samples: int, series: list<array{label: string, value: int, samples: int}>}
     */
    private function responseTime(Collection $checks): array
    {
        $samples = $checks->filter(static fn (Check $check): bool => $check->duration_ms !== null);

        if ($samples->isEmpty()) {
            return ['available' => false, 'averageMs' => null, 'samples' => 0, 'series' => []];
        }

        $sum = 0;
        $count = 0;
        $buckets = [];

        foreach ($samples as $check) {
            $ms = (int) $check->duration_ms;
            $sum += $ms;
            $count++;

            $day = $check->started_at?->copy()->setTimezone('UTC')->format('Y-m-d');
            if ($day === null) {
                continue;
            }

            $buckets[$day] ??= ['sum' => 0, 'count' => 0];
            $buckets[$day]['sum'] += $ms;
            $buckets[$day]['count']++;
        }

        ksort($buckets);

        $series = [];
        foreach ($buckets as $day => $bucket) {
            $series[] = [
                'label' => (string) $day,
                'value' => (int) round($bucket['sum'] / max(1, $bucket['count'])),
                'samples' => $bucket['count'],
            ];
        }

        return [
            'available' => true,
            'averageMs' => (int) round($sum / max(1, $count)),
            'samples' => $count,
            'series' => $series,
        ];
    }
}
