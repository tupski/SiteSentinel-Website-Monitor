<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Server-side per-page whitelist helper (ADR-034, PLAN.md Phase 11).
 *
 * The `x-per-page` selector offers 10 / 20 / 50 / 100 / All. The raw query
 * value is never trusted: only a value in the whitelist is honoured, anything
 * else falls back to the default. "all" maps to the sentinel {@see self::ALL}.
 */
final class PerPage
{
    /** Sentinel returned for the "All" option. */
    public const ALL = 0;

    public const DEFAULT = 20;

    /** @var list<int> Accepted numeric values (plus "all"). */
    public const ALLOWED = [10, 20, 50, 100];

    /**
     * The selectable options for the per-page control, "all" last.
     *
     * @return list<int|string>
     */
    public static function options(): array
    {
        return [10, 20, 50, 100, 'all'];
    }

    /**
     * Resolve a validated per-page value from the request.
     *
     * @return int The numeric page size, or {@see self::ALL} for "All".
     */
    public static function resolve(?Request $request = null): int
    {
        $request ??= request();

        if ($request === null) {
            return self::DEFAULT;
        }

        return self::fromRaw($request->query('per_page'));
    }

    /**
     * Whitelist check for a raw value. Never trust raw input.
     */
    public static function fromRaw(mixed $raw): int
    {
        if (is_int($raw)) {
            return in_array($raw, self::ALLOWED, true) ? $raw : self::DEFAULT;
        }

        if (! is_string($raw)) {
            return self::DEFAULT;
        }

        $value = strtolower(trim($raw));

        if ($value === 'all') {
            return self::ALL;
        }

        if ($value === '' || ! ctype_digit($value)) {
            return self::DEFAULT;
        }

        $int = (int) $value;

        return in_array($int, self::ALLOWED, true) ? $int : self::DEFAULT;
    }

    /**
     * Resolve the page size to hand to `paginate()`. For "All" this is the
     * unpaginated row count (a negative size is not portable across drivers).
     */
    public static function sizeFor(Builder $query, ?Request $request = null): int
    {
        $resolved = self::resolve($request);

        if ($resolved !== self::ALL) {
            return $resolved;
        }

        return max((clone $query)->count(), 1);
    }

    /**
     * Apply the resolved value to a paginator page size, mapping "All" to the
     * sentinel (kept for callers that map it themselves).
     */
    public static function pageSize(int $resolved): int
    {
        return $resolved === self::ALL ? -1 : $resolved;
    }
}
