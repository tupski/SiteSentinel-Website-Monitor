<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Check;
use App\Models\StatusPage;
use Illuminate\Support\Carbon;

/**
 * Requirement 23 — internal analytics report (admin-only).
 *
 * Asserts the authorized report, the allowlisted period param, authorization
 * for guests + non-admins, and that the PUBLIC status routes never expose the
 * analytics surface.
 */
final class StatusPageAnalyticsHttpTest extends StatusPageTestCase
{
    private function makeCheck(int $websiteId, string $state, Carbon $startedAt, ?int $durationMs = null): Check
    {
        return Check::create([
            'website_id' => $websiteId,
            'check_key' => 'k-'.uniqid('', true),
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addSecond(),
            'duration_ms' => $durationMs,
            'availability_state' => $state,
            'security_state' => 'OK',
            'score' => 0,
        ]);
    }

    public function test_authorized_admin_sees_summary_values_from_seeded_data(): void
    {
        $page = $this->defaultPage();
        $website = $this->makeWebsite(['status_page_id' => $page->id, 'status_alias' => 'Alpha']);

        $now = Carbon::now('UTC');

        // 3 UP + 1 DOWN within the last 7 days → 75% sample-based uptime.
        $this->makeCheck($website->id, 'UP', $now->copy()->subDays(1), 120);
        $this->makeCheck($website->id, 'UP', $now->copy()->subDays(2), 140);
        $this->makeCheck($website->id, 'UP', $now->copy()->subDays(3), 160);
        $this->makeCheck($website->id, 'DOWN', $now->copy()->subDays(4), 500);

        $this->makeIncident($website, ['severity' => 'CRITICAL', 'status' => 'DETECTED', 'detected_at' => $now->copy()->subDay()]);
        $this->makeIncident($website, ['severity' => 'WARNING', 'status' => 'RESOLVED', 'detected_at' => $now->copy()->subDays(2)]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.status-pages.analytics', $page))
            ->assertOk();

        $response->assertSee('Analytics');
        $response->assertSee('/'.$page->slug, false);
        $response->assertSee('75.00%', false);
        $response->assertSee('Per-website uptime');

        // Incident counters (2 incidents, 1 open).
        $response->assertSee('Incidents by severity');
    }

    public function test_report_renders_svg_chart_and_data_table(): void
    {
        $page = $this->defaultPage();
        $website = $this->makeWebsite(['status_page_id' => $page->id]);
        $now = Carbon::now('UTC');

        foreach (range(1, 5) as $i) {
            $this->makeCheck($website->id, 'UP', $now->copy()->subDays($i), 100 + $i);
        }

        $html = (string) $this->actingAs($this->admin())
            ->get(route('admin.status-pages.analytics', $page))
            ->assertOk()
            ->getContent();

        // Server-rendered inline SVG (ADR-037), with an accessible <title>.
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('preserveAspectRatio', $html);
        $this->assertStringContainsString('viewBox', $html);
        $this->assertStringContainsString('<title>', $html);
        // The visible fallback table carries the underlying numbers.
        $this->assertStringContainsString('<caption', $html);
        $this->assertStringContainsString('Daily availability', $html);
    }

    public function test_unknown_period_falls_back_to_default_without_error(): void
    {
        $page = $this->defaultPage();

        $this->actingAs($this->admin())
            ->get(route('admin.status-pages.analytics', ['statusPage' => $page, 'period' => 'bogus']))
            ->assertOk()
            ->assertSee('Last 7 days');
    }

    public function test_each_allowlisted_period_is_accepted(): void
    {
        $page = $this->defaultPage();

        foreach (['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $value => $label) {
            $this->actingAs($this->admin())
                ->get(route('admin.status-pages.analytics', ['statusPage' => $page, 'period' => $value]))
                ->assertOk()
                ->assertSee($label);
        }
    }

    public function test_ninety_day_period_communicates_the_checks_retention_limit(): void
    {
        $page = $this->defaultPage();

        $this->actingAs($this->admin())
            ->get(route('admin.status-pages.analytics', ['statusPage' => $page, 'period' => '90d']))
            ->assertOk()
            ->assertSee('retained', false);
    }

    public function test_guest_and_non_admin_cannot_access_analytics(): void
    {
        $page = $this->makePage();

        $this->get(route('admin.status-pages.analytics', $page))->assertRedirect(route('login'));

        $this->actingAs($this->viewer())
            ->get(route('admin.status-pages.analytics', $page))
            ->assertForbidden();
    }

    public function test_public_status_routes_do_not_expose_analytics(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->setMode(StatusPage::MODE_PUBLIC);

        $html = (string) $this->get(route('status.show', ['statusPage' => $page->slug]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Analytics', $html);
        $this->assertStringNotContainsString('Per-website uptime', $html);
        $this->assertStringNotContainsString('/admin', $html);

        $json = $this->getJson(route('status.json', ['statusPage' => $page->slug]))->assertOk();
        $json->assertJsonMissingPath('uptime');
        $json->assertJsonMissingPath('analytics');
    }
}
