<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical expected-HTTP-status catalogue (Phase 11 UI task).
 *
 * THE authoritative allowed set for `websites.expected_status`. The DB column
 * is NOT NULL, so "Any" is not offered — a monitored site always declares the
 * status it is expected to return. The detection engine compares the observed
 * status against exactly this value (RULE-AV-002).
 */
final class HttpStatusCodes
{
    /** @var array<int, string> code => human label */
    public const CATALOGUE = [
        200 => '200 OK',
        201 => '201 Created',
        204 => '204 No Content',
        301 => '301 Moved Permanently',
        302 => '302 Found',
        307 => '307 Temporary Redirect',
        308 => '308 Permanent Redirect',
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        410 => '410 Gone',
        500 => '500 Internal Server Error',
        502 => '502 Bad Gateway',
        503 => '503 Service Unavailable',
    ];

    /**
     * @return list<int>
     */
    public static function allowed(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /**
     * @return string Comma-joined allowed codes for validation `in:` rules.
     */
    public static function allowedRule(): string
    {
        return implode(',', self::allowed());
    }
}
