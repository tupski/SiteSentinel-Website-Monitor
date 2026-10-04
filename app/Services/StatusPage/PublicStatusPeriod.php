<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

use Illuminate\Support\Carbon;

/**
 * Public-safe reporting period for the status-page availability history
 * (STATUS-PAGE.md §4.3, §7.2; PRD.md FR-79 "recent availability history").
 *
 * The period is an **allowlisted enum** — never free-form input — so the public
 * page can never be coerced into an unbounded aggregation or a
 * resource-exhaustion query. Only the availability dimension is bucketed here;
 * response time, security state, rules, IPs and evidence are never involved.
 *
 * Bucketing:
 *   - `24h` → one bucket per UTC hour (24 buckets);
 *   - `7d` / `30d` / `90d` → one bucket per UTC day.
 *
 * Buckets with no availability observation are omitted from the rendered
 * series (never fabricated as a 0% bar), mirroring the internal analytics
 * contract (`StatusPageAnalytics::availabilitySeries`).
 */
final class PublicStatusPeriod
{
    public const P24H = '24h';

    public const P7D = '7d';

    public const P30D = '30d';

    public const P90D = '90d';

    /**
     * Default view: a single day, matching the reference status-page layout.
     */
    public const DEFAULT = self::P24H;

    /**
     * Allowlisted periods offered to the visitor (value => label).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::P24H => 'Last 24 hours',
            self::P7D => 'Last 7 days',
            self::P30D => 'Last 30 days',
            self::P90D => 'Last 90 days',
        ];
    }

    /**
     * Validate a raw `?period=` value against the allowlist. An unknown or
     * non-string value falls back to the default — never trusted.
     */
    public static function resolve(mixed $raw): string
    {
        if (! is_string($raw)) {
            return self::DEFAULT;
        }

        $value = strtolower(trim($raw));

        return array_key_exists($value, self::all()) ? $value : self::DEFAULT;
    }

    public static function label(string $period): string
    {
        return self::all()[self::resolve($period)] ?? self::all()[self::DEFAULT];
    }

    /**
     * UTC start boundary for the period, exclusive of `$end`.
     */
    public static function start(string $period, Carbon $end): Carbon
    {
        $utcEnd = $end->copy()->setTimezone('UTC');

        return match (self::resolve($period)) {
            self::P7D => $utcEnd->copy()->subDays(7),
            self::P30D => $utcEnd->copy()->subDays(30),
            self::P90D => $utcEnd->copy()->subDays(90),
            default => $utcEnd->copy()->subDay(),
        };
    }

    /**
     * Stable bucket key for a check timestamp, in UTC.
     */
    public static function bucketKey(Carbon $at, string $period): string
    {
        $utc = $at->copy()->setTimezone('UTC');

        return self::resolve($period) === self::P24H
            ? $utc->format('Y-m-d H:00')
            : $utc->format('Y-m-d');
    }
}
