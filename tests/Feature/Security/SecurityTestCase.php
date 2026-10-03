<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared helpers for the Phase 9 security regression suite (SECURITY.md).
 *
 * These tests are exploit-oriented: they assert observable behaviour
 * (no outbound transport reached, no secret persisted/rendered, prune
 * outcomes) rather than only that a validation method returned false.
 */
abstract class SecurityTestCase extends TestCase
{
    use RefreshDatabase;

    private static int $hostSeq = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeWebsite(array $overrides = []): Website
    {
        self::$hostSeq++;
        $host = 'sec-'.self::$hostSeq.'.example.test';

        return Website::create(array_merge([
            'name' => 'Security Fixture',
            'url' => "https://{$host}/",
            'scheme' => 'https',
            'host' => $host,
            'is_active' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
        ], $overrides));
    }
}
