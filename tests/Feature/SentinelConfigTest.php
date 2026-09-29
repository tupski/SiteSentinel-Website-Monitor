<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Sentinel configuration contract (Phase 0, carried into Phase 1 test suite).
 *
 * Verifies config/sentinel.php loads through the booted framework and that
 * every documented default (PRD.md, SECURITY.md, NOTIFICATIONS.md, DATABASE.md)
 * resolves to its canonical value.
 */
final class SentinelConfigTest extends TestCase
{
    private function config(): array
    {
        return (array) config('sentinel');
    }

    public function test_sentinel_config_loads_with_all_documented_sections(): void
    {
        $config = $this->config();

        foreach (['monitoring', 'probe_limits', 'scoring', 'notifications', 'retention', 'auth'] as $section) {
            $this->assertArrayHasKey($section, $config, "sentinel config section {$section} missing");
        }
    }

    public function test_documented_defaults_resolve_to_canonical_values(): void
    {
        $expected = [
            'monitoring.default_interval_seconds' => 300,
            'monitoring.default_timeout_seconds' => 10,
            'probe_limits.max_redirect_hops' => 5,
            'scoring.threshold_info' => 1,
            'scoring.threshold_warning' => 8,
            'scoring.threshold_critical' => 15,
            'scoring.correlation_guard_min_categories' => 2,
            'notifications.default_cooldown_minutes' => 15,
            'retention.checks_days' => 30,
            'retention.incidents_days' => 365,
            'retention.notification_logs_days' => 90,
            'retention.snapshots_days' => 14,
            'auth.max_login_attempts' => 5,
        ];

        foreach ($expected as $path => $value) {
            $this->assertSame($value, config("sentinel.{$path}"), "sentinel.{$path} must default to {$value}");
        }
    }
}
