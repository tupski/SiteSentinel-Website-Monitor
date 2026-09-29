<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Validation\ValidationException;

/**
 * Runtime SSRF/destination guard for outbound monitoring requests (PLAN.md Phase 4).
 *
 * This is the SECOND line of SSRF defence. It validates the actual destination
 * immediately before a connection is made, and re-validates every redirect hop.
 * It accepts an optional resolver/transport override for deterministic testing.
 */
final class SsrfGuard
{
    /** @var callable|null */
    private static $resolver = null;

    private const ALLOWED_SCHEMES = ['http', 'https'];

    private const BLOCKED_HOST_SUFFIXES = ['.localhost', '.local', '.internal'];

    private const BLOCKED_PORTS = [22, 25, 3306, 6379];

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Validate a candidate URL before connecting.
     *
     * @return array{scheme:string, host:string, url:string}
     *
     * @throws ValidationException
     */
    public static function validate(string $url): array
    {
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            self::reject('Invalid destination URL.');
        }

        $parsed = parse_url($url);
        if (! is_array($parsed) || ! isset($parsed['scheme'], $parsed['host'])) {
            self::reject('Invalid destination URL.');
        }

        $scheme = mb_strtolower((string) $parsed['scheme']);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            self::reject('Scheme not allowed.');
        }

        if (isset($parsed['user']) && $parsed['user'] !== '') {
            self::reject('Embedded credentials are not allowed.');
        }
        if (isset($parsed['pass'])) {
            self::reject('Embedded credentials are not allowed.');
        }

        $port = isset($parsed['port']) ? (int) $parsed['port'] : null;
        if ($port !== null && in_array($port, self::BLOCKED_PORTS, true)) {
            self::reject('Destination port is not allowed.');
        }

        $host = self::normalizeHost((string) $parsed['host']);

        if (self::isLiteralIp($host)) {
            self::validateLiteralIp($host);
        } else {
            self::validateHostname($host);
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'url' => self::rebuildUrl($scheme, $host, $port, $parsed['path'] ?? '', $parsed['query'] ?? ''),
        ];
    }

    public static function validateIp(string $ip): void
    {
        $ipv6 = str_contains($ip, ':');
        self::checkBlockedRanges($ip, $ipv6);
    }

    private static function reject(string $message): void
    {
        throw ValidationException::withMessages(['url' => $message]);
    }

    private static function normalizeHost(string $host): string
    {
        if (str_ends_with($host, '.')) {
            $host = mb_substr($host, 0, -1);
        }

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
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private static function validateLiteralIp(string $host): void
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $inner = mb_substr($host, 1, -1);
            self::validateIp($inner);

            return;
        }

        self::validateIp($host);
    }

    private static function validateHostname(string $host): void
    {
        if ($host === 'localhost' || $host === '') {
            self::reject('Internal hostname not allowed.');
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) || str_ends_with($host, $suffix.'.')) {
                self::reject('Internal hostname not allowed.');
            }
        }

        if (! str_contains($host, '.')) {
            self::reject('Bare hostname not allowed.');
        }

        if (! preg_match('/^[a-z0-9\\-.]+$/', $host)) {
            self::reject('Invalid hostname characters.');
        }

        $ips = self::resolve($host);
        foreach ($ips as $ip) {
            self::validateIp($ip);
        }
    }

    private static function checkBlockedRanges(string $ip, bool $ipv6): void
    {
        if (! $ipv6 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            self::reject('Destination IP is not allowed.');
        }

        if ($ipv6 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            self::reject('Destination IP is not allowed.');
        }

        $blocked = $ipv6 ? self::blockedIpv6Ranges() : self::blockedIpv4Ranges();
        foreach ($blocked as $range) {
            if (self::ipInCidr($ip, $range)) {
                self::reject('Destination IP is not allowed.');
            }
        }
    }

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

        $bits = (int) $bits;
        $mask = str_repeat("\xff", (int) floor($bits / 8));
        $remaining = $bits % 8;
        if ($remaining > 0) {
            $mask .= chr((0xFF << (8 - $remaining)) & 0xFF);
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    private static function resolve(string $hostname): array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($hostname);
        }

        $records = @dns_get_record($hostname, DNS_A + DNS_AAAA);
        if ($records === false || $records === []) {
            self::reject('Could not resolve destination hostname.');
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
            self::reject('Could not resolve destination hostname.');
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
