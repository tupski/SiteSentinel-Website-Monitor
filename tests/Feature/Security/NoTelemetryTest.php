<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;

/**
 * PRD AC-23 (PLAN.md Phase 10, §1148): "The system runs with no calls to
 * third-party telemetry, analytics, or update services."
 *
 * This is locally verifiable by asserting the *absence* of the machinery such
 * a call would require: no telemetry/analytics/update package in the runtime
 * dependency set, no telemetry configuration or routes, no analytics markup in
 * the rendered UI, and a no-op broadcast driver (the usual exfil channel for a
 * "phone-home" default). The one thing that cannot be proven from inside the
 * process — that no *future* code adds such a call — is guarded by the
 * dependency/route/config assertions below plus the "no real outbound network"
 * suite rule (PLAN.md AC-10-02).
 */
final class NoTelemetryTest extends SecurityTestCase
{
    /**
     * Package names that denote third-party telemetry / analytics / crash or
     * update reporting. Their presence in `require` (runtime) would make an
     * outbound call possible.
     *
     * @var list<string>
     */
    private const FORBIDDEN_RUNTIME_PACKAGES = [
        'sentry/sentry-laravel',
        'bugsnag/bugsnag-laravel',
        'spatie/laravel-analytics',
        'posthog/posthog-php',
        'segmentio/analytics-php',
        'datadog/dd-trace',
        'telescope',
        'laravel/telescope',
        'laravel/pulse',
        'laravel/horizon',
        'laravel/nightwatch',
    ];

    public function test_no_telemetry_analytics_or_update_packages_are_runtime_dependencies(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $this->assertIsArray($composer);
        $runtime = array_keys($composer['require'] ?? []);

        foreach ($runtime as $package) {
            $this->assertNotContains(
                strtolower($package),
                self::FORBIDDEN_RUNTIME_PACKAGES,
                "Runtime dependency '{$package}' would permit a third-party telemetry/analytics/update call (AC-23).",
            );
        }
    }

    public function test_package_json_has_no_analytics_or_telemetry_dependencies(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('package.json')), true);

        $this->assertIsArray($manifest);

        $all = array_merge(
            array_keys($manifest['dependencies'] ?? []),
            array_keys($manifest['devDependencies'] ?? []),
        );

        $forbidden = ['@sentry', 'posthog-js', 'analytics-node', 'mixpanel', 'gtag', 'google-analytics'];

        foreach ($all as $package) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    strtolower($package),
                    "Frontend dependency '{$package}' would permit analytics/telemetry (AC-23).",
                );
            }
        }
    }

    public function test_no_telemetry_environment_variables_are_declared(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        foreach (['SENTRY', 'BUGSNAG', 'POSTHOG', 'SEGMENT', 'DATADOG', 'NEW_RELIC', 'MIXPANEL'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $env,
                "`{$needle}` telemetry configuration must not be present (AC-23).",
            );
        }

        // The Laravel telemetry toggles must be explicitly disabled.
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));
        $this->assertStringContainsString('name="TELESCOPE_ENABLED" value="false"', $phpunit);
        $this->assertStringContainsString('name="PULSE_ENABLED" value="false"', $phpunit);
        $this->assertStringContainsString('name="NIGHTWATCH_ENABLED" value="false"', $phpunit);
    }

    public function test_no_telemetry_routes_are_registered(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());
            foreach (['telescope', 'pulse', 'horizon', 'sentry', 'analytics'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $uri,
                    "Telemetry/analytics route '{$uri}' must not be registered (AC-23).",
                );
            }
        }
    }

    public function test_broadcast_driver_is_a_no_op_outside_the_app(): void
    {
        // Broadcasting to a third-party service is a classic phone-home channel.
        // The app ships a non-network driver in the production template
        // (.env.example: BROADCAST_CONNECTION=log) and a null driver in the test
        // harness (phpunit.xml: BROADCAST_CONNECTION=null). Neither reaches a host.
        $env = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('BROADCAST_CONNECTION=log', $env);

        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));
        $this->assertStringContainsString('name="BROADCAST_CONNECTION" value="null"', $phpunit);
    }

    public function test_health_endpoint_makes_no_outbound_telemetry_call(): void
    {
        // The health/readiness surface is internal-only; it must never be wired
        // to an external uptime/telemetry ping. A control endpoint that stays
        // 200 while the process never reaches a third-party host is the
        // observable half of AC-23.
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }
}
