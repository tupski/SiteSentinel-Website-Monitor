<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC-2-07: /admin dashboard renders with availability and security as
 * SEPARATE areas (structural invariant — AGENTS.md §15.2).
 */
final class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_both_areas_separately(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();

        $html = (string) $response->getContent();

        // Both areas present
        $this->assertStringContainsString('Availability', $html);
        $this->assertStringContainsString('Security', $html);

        // Structurally separate sections (aria-labelledby anchors)
        $this->assertStringContainsString('availability-heading', $html);
        $this->assertStringContainsString('security-heading', $html);
    }

    public function test_dashboard_is_turbo_friendly(): void
    {
        $user = User::factory()->create();

        $html = (string) $this->actingAs($user)->get('/admin')->getContent();

        // Turbo needs the CSRF meta tag on every page it may mutate from
        $this->assertStringContainsString('name="csrf-token"', $html);
        // Vite assets (Turbo + Tailwind) are wired
        $this->assertStringContainsString('assets/app', $html);
    }
}
