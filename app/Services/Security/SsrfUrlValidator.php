<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Validation\ValidationException;

/**
 * Write-time SSRF/destination validation for monitored URLs (PLAN.md Phase 3).
 *
 * This is the FIRST line of SSRF defence only. It validates the candidate URL
 * before it is stored. Runtime per-hop validation, redirect following, DNS
 * rebinding protection, and actual HTTP requests belong to later phases
 * (Phase 4/Phase 9). See SECURITY.md §5.
 *
 * The validator is fail-closed: if any aspect cannot be validated as safe,
 * the URL is rejected with a generic, non-disclosing message.
 */
final class SsrfUrlValidator
{
    /**
     * Allowed URL schemes (SECURITY.md §5.3).
     *
     * @var list<string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Hostnames that are always considered internal regardless of resolution.
     *
     * @var list<string>
     */
    private const BLOCKED_HOST_SUFFIXES = ['.localhost', '.local', '.internal'];

    /**
     * Blocked ports (well-known internal-only services). Ports are blocked
     * only when explicitly present in the URL (the default ports for http/https
     * are obviously allowed).
     *
     * @var list<int>
     */
    private const BLOCKED_PORTS = [22, 25, 3306, 6379];

    /**
     * Resolver used for hostname → IP lookups. Replaceable in tests.
     */
    private static ?\Closure $resolver = null;

    /**
     * Validate a URL string for safe storage.
     *
     * @return array{scheme: string, host: string, url: string} Normalized parts.
     *
     * @throws ValidationException
     */
    public static function validate(string $url): array
    {
        // 1. Parse
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        $parsed = parse_url($url);
        if (! is_array($parsed) || ! isset($parsed['scheme'], $parsed['host'])) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        $scheme = mb_strtolower((string) $parsed['scheme']);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        // 2. Reject embedded credentials (SECURITY.md §5.4)
        if (isset($parsed['user']) && $parsed['user'] !== '') {
            self::reject('url', 'The destination URL is not allowed.');
        }
        if (isset($parsed['pass'])) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        // 3. Port checks
        $port = isset($parsed['port']) ? (int) $parsed['port'] : null;
        if ($port !== null && in_array($port, self::BLOCKED_PORTS, true)) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        // 4. Host normalization
        $host = mb_strtolower((string) $parsed['host']);
        $host = self::normalizeHost($host);

        // 5. Host validation (literal IP or hostname)
        if (self::isLiteralIp($host)) {
            self::validateLiteralIp($host);
        } else {
            self::validateHostname($host);
        }

        // 6. Rebuild the normalized URL (without credentials, fragment, or query).
        $normalized = self::rebuildUrl($scheme, $host, $port, $parsed['path'] ?? '', $parsed['query'] ?? '');

        return [
            'scheme' => $scheme,
            'host' => $host,
            'url' => $normalized,
        ];
    }

    /**
     * Set a custom DNS resolver for testing.
     *
     * @param  callable|null  $resolver  fn(string $hostname): array<string>
     */
    public static function setResolver(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    private static function reject(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private static function normalizeHost(string $host): string
    {
        // Strip trailing root dot.
        if (str_ends_with($host, '.')) {
            $host = mb_substr($host, 0, -1);
        }

        // IDN to punycode if the intl extension is available.
        if (function_exists('idn_to_ascii') && str_contains($host, '\u{fffd}')) {
            $converted = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($converted !== false) {
                $host = $converted;
            }
        }

        return $host;
    }

    private static function isLiteralIp(string $host): bool
    {
        // Bracketed IPv6
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private static function validateLiteralIp(string $host): void
    {
        // IPv6 bracketed form
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $inner = mb_substr($host, 1, -1);
            self::validateIp($inner, true);

            return;
        }

        self::validateIp($host, false);
    }

    private static function validateHostname(string $host): void
    {
        // Internal hostnames (SECURITY.md §5.5)
        if ($host === 'localhost' || $host === '') {
            self::reject('url', 'The destination URL is not allowed.');
        }

        // Non-canonical numeric hosts (SECURITY.md §5.4): reject alternate
        // IPv4 spellings (`127.1`, `2130706433`, `0177.0.0.1`, `0x7f000001`)
        // before they can be expanded by the resolver.
        if (self::looksLikeNumericHost($host)) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) || str_ends_with($host, $suffix.'.')) {
                self::reject('url', 'The destination URL is not allowed.');
            }
        }

        // Bare hostname with no public suffix / dot separator.
        if (! str_contains($host, '.')) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        // Validate label characters — hostnames should only contain a-z, 0-9, hyphen, dot.
        if (! preg_match('/^[a-z0-9\\-.]+$/', $host)) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        // DNS resolution (SECURITY.md §5.5 / §5.6). Fail-closed: if we cannot
        // resolve the hostname, we cannot prove it is safe.
        $ips = self::resolve($host);
        foreach ($ips as $ip) {
            self::validateIp($ip, str_contains($ip, ':'));
        }
    }

    /**
     * True when the host is an alternate (non-canonical) numeric IPv4 spelling.
     */
    private static function looksLikeNumericHost(string $host): bool
    {
        if (preg_match('/^[0-9.]+$/', $host) === 1
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return true;
        }

        return preg_match('/^0x[0-9a-f]+$/i', $host) === 1;
    }

    private static function validateIp(string $ip, bool $ipv6): void
    {
        if ($ipv6) {
            // IPv4-mapped IPv6: extract the embedded IPv4 and classify it.
            if (str_starts_with($ip, '::ffff:')) {
                $embedded = substr($ip, 7);
                self::validateIp($embedded, false);

                return;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                self::reject('url', 'The destination URL is not allowed.');
            }

            self::checkBlockedRanges($ip, true);

            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        self::checkBlockedRanges($ip, false);
    }

    private static function checkBlockedRanges(string $ip, bool $ipv6): void
    {
        // Loopback
        if (! $ipv6 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        if ($ipv6 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        // Additional explicit ranges not covered by FILTER_FLAG constants.
        $blocked = $ipv6 ? self::blockedIpv6Ranges() : self::blockedIpv4Ranges();
        foreach ($blocked as $range) {
            if (self::ipInCidr($ip, $range)) {
                self::reject('url', 'The destination URL is not allowed.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function blockedIpv4Ranges(): array
    {
        return [
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '169.254.0.0/16',
            '0.0.0.0/8',
            '224.0.0.0/4',
            '192.0.2.0/24',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '198.18.0.0/15',
            '240.0.0.0/4',
        ];
    }

    /**
     * @return list<string>
     */
    private static function blockedIpv6Ranges(): array
    {
        return [
            '::1/128',
            'fc00::/7',
            'fe80::/10',
            '::/128',
            'ff00::/8',
        ];
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr), 2, '');
        if ($bits === '') {
            return false;
        }

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $ipLen = strlen($ipBin) * 8;
        $bits = (int) $bits;
        if ($bits < 0 || $bits > $ipLen) {
            return false;
        }

        $mask = str_repeat("\xff", (int) floor($bits / 8));
        $remaining = $bits % 8;
        if ($remaining > 0) {
            $mask .= chr((0xFF << (8 - $remaining)) & 0xFF);
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $hostname): array
    {
        if (self::$resolver) {
            return (self::$resolver)($hostname);
        }

        $records = @dns_get_record($hostname, DNS_A + DNS_AAAA);
        if ($records === false || $records === []) {
            // Fail-closed: cannot verify safety.
            self::reject('url', 'The destination URL is not allowed.');
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            self::reject('url', 'The destination URL is not allowed.');
        }

        return $ips;
    }

    private static function rebuildUrl(string $scheme, string $host, ?int $port, string $path, string $query): string
    {
        $url = $scheme.'://'.$host;
        if ($port !== null) {
            $url .= ':'.$port;
        }
        if ($path === '' || $path[0] !== '/') {
            $url .= '/';
        }
        $url .= $path;
        if ($query !== '') {
            $url .= '?'.$query;
        }

        return $url;
    }
}
