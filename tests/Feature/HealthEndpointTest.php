<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 1 health endpoint contract (AC-1-05).
 *
 * Verifies the status shape and that the endpoint never leaks secrets or
 * internal configuration values (PLAN.md Phase 1 security note).
 */
final class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_component_status(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database' => ['status'],
                    'redis' => ['status'],
                    'queue' => ['status', 'pending_jobs'],
                ],
            ]);

        $this->assertSame('ok', $response->json('status'));
    }

    public function test_health_endpoint_does_not_leak_secrets_or_configuration(): void
    {
        $response = $this->getJson('/health');

        $body = (string) $response->getContent();

        // No environment keys or credential material may appear
        $this->assertStringNotContainsString('APP_KEY', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('REDIS_PASSWORD', $body);
        $this->assertStringNotContainsString('TELEGRAM_BOT_TOKEN', $body);

        $appKey = (string) config('app.key');
        $this->assertNotSame('', $appKey, 'test bootstrap must have an APP_KEY to leak-check against');
        $this->assertStringNotContainsString($appKey, $body, 'health must never echo APP_KEY');

        $dbPassword = (string) config('database.connections.mysql.password');
        if ($dbPassword !== '') {
            $this->assertStringNotContainsString($dbPassword, $body, 'health must never echo DB_PASSWORD');
        }
    }

    public function test_health_route_is_registered_and_public(): void
    {
        $this->assertTrue(Route::has('health'));

        $this->get('/health')->assertOk();
    }
}
