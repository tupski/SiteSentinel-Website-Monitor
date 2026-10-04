<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Health\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRD AC-21: the admin dashboard exposes health/readiness for the database,
 * Redis, and the queue worker, so a stalled monitoring pipeline is visible to
 * Admin (PLAN.md Phase 10, AC-10-04).
 *
 * Entry point: GET /admin (AdminDashboardController -> admin.dashboard view).
 */
final class DashboardHealthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_dashboard_exposes_database_redis_and_queue_readiness(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk();

        $html = (string) $response->getContent();

        // The three components AC-21 names must be individually visible.
        $this->assertStringContainsString('system-health-heading', $html);
        $this->assertStringContainsString('Database', $html);
        $this->assertStringContainsString('Redis', $html);
        $this->assertStringContainsString('Queue worker', $html);
    }

    public function test_dashboard_shows_healthy_state_when_all_components_are_ready(): void
    {
        // Deterministic readiness: pin every component to ok.
        $this->app->instance(SystemHealth::class, new class extends SystemHealth
        {
            public function checks(): array
            {
                return [
                    'database' => ['status' => 'ok'],
                    'redis' => ['status' => 'ok'],
                    'queue' => ['status' => 'ok', 'pending_jobs' => 0],
                ];
            }
        });

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Ready')
            ->assertSee('All systems ready');
    }

    public function test_dashboard_surfaces_degraded_pipeline_when_a_component_is_down(): void
    {
        // A stalled pipeline: Redis and the queue worker are unavailable.
        $this->app->instance(SystemHealth::class, new class extends SystemHealth
        {
            public function checkRedis(): array
            {
                return ['status' => 'fail'];
            }

            public function checkQueue(): array
            {
                return ['status' => 'fail', 'pending_jobs' => -1];
            }
        });

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Unavailable');
        $response->assertSee('Pipeline degraded');
    }

    public function test_dashboard_health_widget_does_not_leak_secrets_or_connection_details(): void
    {
        $html = (string) $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->getContent();

        $this->assertStringNotContainsString((string) config('database.connections.mysql.password') ?: 'DB_PASSWORD', $html);
        $this->assertStringNotContainsString('REDIS_PASSWORD', $html);
        $appKey = (string) config('app.key');
        if ($appKey !== '') {
            $this->assertStringNotContainsString($appKey, $html, 'dashboard must never echo APP_KEY');
        }
    }
}
