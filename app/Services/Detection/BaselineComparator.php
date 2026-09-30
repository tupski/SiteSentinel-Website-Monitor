<?php

declare(strict_types=1);

namespace App\Services\Detection;

use App\Models\WebsiteBaseline;

/**
 * Baseline-relative comparison helpers (DETECTION-RULES.md §9.4).
 *
 * All comparisons are deterministic and normalised so that repeated runs on the
 * same evidence produce identical results (idempotency, §6.4).
 */
final class BaselineComparator
{
    /**
     * Normalise a hostname to its canonical registrable form for comparison.
     *
     * Lowercases, strips a leading `www.`, and trims a trailing dot. This is the
     * single normalisation used for both baseline membership and current-check
     * domains so membership tests are symmetric (DETECTION-RULES §8.7).
     */
    public static function normalizeDomain(?string $host): string
    {
        if ($host === null) {
            return '';
        }

        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Extract and normalise the registrable domain from a URL or bare host.
     */
    public static function domainFromUrl(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);

        if ($host === false || $host === null || $host === '') {
            // Not a URL; treat the raw value as a host if it looks like one.
            $host = preg_match('/^[a-z0-9.\-]+$/i', $url) === 1 ? $url : '';
        }

        return self::normalizeDomain($host);
    }

    /**
     * Normalise a list of URLs/hosts into a unique, sorted, normalised domain set.
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    public static function normalizeDomainSet(array $values): array
    {
        $domains = [];
        foreach ($values as $value) {
            $domain = self::domainFromUrl((string) $value);
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        $result = array_keys($domains);
        sort($result);

        return $result;
    }

    /**
     * Baseline domain membership set (canonical column `external_domains`).
     *
     * @return array<int, string>
     */
    public static function baselineDomains(?WebsiteBaseline $baseline): array
    {
        if ($baseline === null) {
            return [];
        }

        $domains = $baseline->external_domains;

        return is_array($domains) ? self::normalizeDomainSet($domains) : [];
    }

    /**
     * Domains present in the current check that were not in the baseline.
     *
     * @param  array<int, string>  $current
     * @param  array<int, string>  $ignored
     * @return array<int, string>
     */
    public static function newDomains(array $current, ?WebsiteBaseline $baseline, array $ignored = []): array
    {
        $currentSet = self::normalizeDomainSet($current);
        $baselineSet = self::baselineDomains($baseline);

        $ignoredSet = [];
        foreach ($ignored as $domain) {
            $normalized = self::normalizeDomain(trim((string) $domain));
            if ($normalized !== '') {
                $ignoredSet[$normalized] = true;
            }
        }

        $new = [];
        foreach ($currentSet as $domain) {
            if (in_array($domain, $baselineSet, true)) {
                continue;
            }
            if (isset($ignoredSet[$domain])) {
                continue;
            }
            $new[] = $domain;
        }

        sort($new);

        return $new;
    }
}
