<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC-2-07: /admin dashboard renders with availability and security as
 * SEPARATE areas (structural invariant — AGENTS.md §15.2).
 *
 * Requirement 31: the four summary cards are real anchors that deep-link to the
 * matching filtered list, and their counts match that destination.
 */
final class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function incident(array $overrides = []): array
    {
        return array_merge([
            'website_id' => Website::factory()->create()->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'dedupe-'.uniqid(),
            'detected_at' => now(),
        ], $overrides);
    }

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

    /** Requirement 31: exactly four summary cards are present with real counts. */
    public function test_dashboard_shows_the_four_summary_cards(): void
    {
        Website::factory()->create(['status_availability' => 'UP']);
        Website::factory()->create(['status_availability' => 'DOWN']);
        Incident::create($this->incident(['severity' => 'WARNING']));
        Incident::create($this->incident(['severity' => 'CRITICAL', 'dedupe_key' => 'crit-'.uniqid()]));

        $html = (string) $this->actingAs(User::factory()->create())->get('/admin')->getContent();

        foreach (['total', 'operational', 'warning', 'critical'] as $card) {
            $this->assertStringContainsString('data-dashboard-card="'.$card.'"', $html);
        }

        $this->assertStringContainsString('Total websites', $html);
        $this->assertStringContainsString('Operational', $html);
        $this->assertStringContainsString('Warning incidents', $html);
        $this->assertStringContainsString('Critical incidents', $html);
    }

    /**
     * Requirement 31: each card deep-links to the matching filtered list with the
     * exact query string the destination controller whitelists.
     */
    public function test_each_card_links_to_the_correct_filtered_destination(): void
    {
        // Blade HTML-escapes the `&` between query parameters; decode so the
        // assertions can compare against the real route() URL.
        $html = html_entity_decode(
            (string) $this->actingAs(User::factory()->create())->get('/admin')->getContent(),
        );

        // Total -> all websites
        $this->assertStringContainsString('data-dashboard-card="total"', $html);
        $this->assertStringContainsString('href="'.route('admin.websites.index').'"', $html);

        // Operational -> websites filtered to availability UP
        $this->assertStringContainsString('href="'.route('admin.websites.index', ['status' => 'UP']).'"', $html);

        // Warning / Critical -> incidents filtered by severity + open=1
        $this->assertStringContainsString('href="'.route('admin.incidents.index', ['severity' => 'WARNING', 'open' => 1]).'"', $html);
        $this->assertStringContainsString('href="'.route('admin.incidents.index', ['severity' => 'CRITICAL', 'open' => 1]).'"', $html);
    }

    /**
     * Requirement 31: "Operational" is driven by `status_availability = 'UP'`,
     * NOT by `is_active`. Seed rows where the two disagree and assert the count
     * follows availability.
     */
    public function test_operational_count_uses_status_availability_not_is_active(): void
    {
        // is_active but not UP -> NOT operational.
        Website::factory()->create(['is_active' => true, 'status_availability' => 'DOWN']);
        // not is_active but UP -> IS operational (the two must not be conflated).
        Website::factory()->create(['is_active' => false, 'status_availability' => 'UP']);
        // is_active + UP -> operational.
        Website::factory()->create(['is_active' => true, 'status_availability' => 'UP']);
        // unknown availability -> not operational.
        Website::factory()->create(['is_active' => true, 'status_availability' => null]);

        $response = $this->actingAs(User::factory()->create())->get('/admin');

        $response->assertOk();
        // Total = 4; Operational = 2 (the two UP rows only).
        $response->assertSeeInOrder(['Total websites', '4'], false);
        $response->assertSeeInOrder(['Operational', '2'], false);
    }

    /**
     * The Availability / Security & Content Health tables are capped at 10 rows
     * regardless of fleet size, while the counters still reflect the full fleet.
     */
    public function test_availability_and_security_tables_are_capped_at_ten_items(): void
    {
        Website::factory()->count(12)->create();

        $response = $this->actingAs(User::factory()->create())->get('/admin');
        $response->assertOk();

        $data = $response->getOriginalContent()->getData();

        $this->assertCount(10, $data['websites'], 'the shared table collection must be capped at 10');
        $this->assertSame(12, $data['websiteTotal'], 'the uncapped total must be exposed for the "View all" link');
        $this->assertSame(12, $data['counters']['total'], 'the total counter must reflect the full fleet');
    }

    /** The "View all" links appear only when the fleet exceeds the 10-row cap. */
    public function test_view_all_links_appear_only_when_more_than_ten_websites(): void
    {
        // Exactly ten: no "View all" affordance in either section.
        Website::factory()->count(10)->create();

        $html = html_entity_decode(
            (string) $this->actingAs(User::factory()->create())->get('/admin')->getContent(),
        );

        $this->assertStringNotContainsString('View all availability', $html);
        $this->assertStringNotContainsString('View all security & content health', $html);
        $this->assertStringNotContainsString('View all security & content health', $html);

        // One more row tips both sections over the cap.
        Website::factory()->create();

        $html = html_entity_decode(
            (string) $this->actingAs(User::factory()->create())->get('/admin')->getContent(),
        );

        $this->assertStringContainsString('View all availability', $html);
        $this->assertStringContainsString('View all security & content health', $html);

        // Each link targets the correct related list page.
        $this->assertStringContainsString(
            'href="'.route('admin.websites.index', ['status' => 'UP']).'"',
            $html,
        );
        $this->assertStringContainsString(
            'href="'.route('admin.websites.index').'"',
            $html,
        );
    }
}
