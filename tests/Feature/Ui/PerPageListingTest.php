<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `x-per-page` wiring across the paginated admin listings (ADR-034 / FR-105).
 * A valid per_page is honoured, an invalid one falls back to the default, and
 * the control preserves every other query filter.
 */
final class PerPageListingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function makeWebsites(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Website::create([
                'name' => 'Site '.$i,
                'url' => 'https://site-'.$i.'.test/',
                'scheme' => 'https',
                'host' => 'site-'.$i.'.test',
                'is_active' => true,
                'check_interval_seconds' => 300,
                'timeout_seconds' => 10,
                'expected_status' => 200,
            ]);
        }
    }

    public function test_websites_index_is_now_paginated_and_honours_per_page(): void
    {
        $this->makeWebsites(25);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.websites.index', ['per_page' => 10]));

        $response->assertOk();
        $response->assertSee('per_page', false);

        $websites = $response->viewData('websites');
        $this->assertSame(10, $websites->perPage());
        $this->assertSame(25, $websites->total());
    }

    public function test_invalid_per_page_falls_back_to_default(): void
    {
        $this->makeWebsites(3);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.websites.index', ['per_page' => 'nonsense']));

        $response->assertOk();

        $websites = $response->viewData('websites');
        $this->assertSame(20, $websites->perPage());
    }

    public function test_all_disables_pagination(): void
    {
        $this->makeWebsites(3);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.websites.index', ['per_page' => 'all']));

        $response->assertOk();

        $websites = $response->viewData('websites');
        $this->assertSame(3, $websites->count());
    }

    public function test_incidents_index_accepts_per_page_and_preserves_filters(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.incidents.index', ['per_page' => 50, 'status' => 'DETECTED']));

        $response->assertOk();

        $incidents = $response->viewData('incidents');
        $this->assertSame(50, $incidents->perPage());

        $html = (string) $response->getContent();
        // The x-per-page control preserves the active filter as a hidden input.
        $this->assertStringContainsString('name="status" value="DETECTED"', $html);
        $this->assertStringContainsString('name="per_page"', $html);
    }

    public function test_delivery_log_index_accepts_per_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.notification-logs.index', ['per_page' => 100]));

        $response->assertOk();

        $logs = $response->viewData('logs');
        $this->assertSame(100, $logs->perPage());
    }

    public function test_delivery_log_invalid_per_page_defaults(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.notification-logs.index', ['per_page' => 7]));

        $response->assertOk();

        $logs = $response->viewData('logs');
        $this->assertSame(20, $logs->perPage());
    }
}
