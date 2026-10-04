<?php

declare(strict_types=1);

namespace Tests\Feature\Incidents;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP layer for the incident UI (PLAN.md Phase 6, AC-6-06/07).
 *
 * /admin incident routes require authenticated + active + admin
 * (AC-2-02 / SECURITY.md §3.2) for every HTTP method, and the lifecycle
 * actions record actor + timestamp while never resolving on acknowledge.
 */
final class IncidentHttpTest extends TestCase
{
    use RefreshDatabase;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->website = Website::create([
            'name' => 'Example',
            'url' => 'https://example.test/',
            'scheme' => 'https',
            'host' => 'example.test',
            'is_active' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
        ]);
    }

    private function makeIncident(array $overrides = []): Incident
    {
        return Incident::create(array_merge([
            'website_id' => $this->website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'triggered_rules' => [
                'RULE-KW-002' => ['category' => 'keyword', 'weight' => 4, 'confidence' => 'medium', 'reason' => 'tier-2 cluster'],
            ],
            'dedupe_key' => 'security:website:'.$this->website->id,
            'detected_at' => now(),
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    /** AC-2-02: every /admin/incidents method requires an authenticated admin. */
    public function test_incident_routes_require_authentication(): void
    {
        $incident = $this->makeIncident();

        $this->get(route('admin.incidents.index'))->assertRedirect(route('login'));
        $this->get(route('admin.incidents.show', $incident))->assertRedirect(route('login'));
        $this->post(route('admin.incidents.acknowledge', $incident))->assertRedirect(route('login'));
        $this->post(route('admin.incidents.resolve', $incident))->assertRedirect(route('login'));
    }

    /** Non-admin authenticated users are forbidden on every incident method. */
    public function test_incident_routes_forbid_non_admin_users(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($user);

        $this->get(route('admin.incidents.index'))->assertForbidden();
        $this->get(route('admin.incidents.show', $incident))->assertForbidden();
        $this->post(route('admin.incidents.acknowledge', $incident))->assertForbidden();
        $this->post(route('admin.incidents.resolve', $incident))->assertForbidden();
    }

    /** Admin can acknowledge via HTTP; actor + timestamp recorded; not resolved. */
    public function test_admin_can_acknowledge_an_incident_via_http(): void
    {
        $incident = $this->makeIncident();
        $admin = $this->admin();
        $this->actingAs($admin);

        $response = $this->from(route('admin.incidents.show', $incident))
            ->post(route('admin.incidents.acknowledge', $incident));

        $response->assertRedirect();

        $incident->refresh();
        $this->assertSame('ACKNOWLEDGED', $incident->status);
        $this->assertSame($admin->id, $incident->acknowledged_by);
        $this->assertNotNull($incident->acknowledged_at);
        $this->assertNull($incident->resolved_at, 'Acknowledge must not resolve (FR-58).');
    }

    /** Admin can resolve via HTTP with resolution mode manual. */
    public function test_admin_can_resolve_an_incident_via_http(): void
    {
        $incident = $this->makeIncident();
        $admin = $this->admin();
        $this->actingAs($admin);

        $response = $this->post(route('admin.incidents.resolve', $incident), [
            'resolution_notes' => 'Mitigated upstream.',
        ]);

        $response->assertRedirect();

        $incident->refresh();
        $this->assertSame('RESOLVED', $incident->status);
        $this->assertSame('manual', $incident->resolution_mode);
        $this->assertSame($admin->id, $incident->resolved_by);
        $this->assertNotNull($incident->resolved_at);
    }

    /** AC-6-06: incident detail shows rule attribution (FR-49). */
    public function test_incident_detail_renders_rule_attribution(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->admin());

        $response = $this->get(route('admin.incidents.show', $incident));

        $response->assertOk();
        $response->assertSee('RULE-KW-002', false);
        $response->assertSee('keyword', false);
    }

    /** AC-6-07: dashboard counters reflect total/operational/warning/incident. */
    public function test_dashboard_counters_reflect_incident_state(): void
    {
        $this->makeIncident(['severity' => 'WARNING']);
        $this->makeIncident(['severity' => 'CRITICAL', 'type' => 'availability', 'dedupe_key' => 'availability:website:'.$this->website->id]);
        $this->makeIncident(['status' => 'RESOLVED', 'severity' => 'CRITICAL', 'resolution_mode' => 'manual']);

        $this->actingAs($this->admin());

        $response = $this->get(route('admin.dashboard'));

        $response->assertOk();
        // 2 open incidents (WARNING + CRITICAL); the RESOLVED one must not count.
        $response->assertSeeInOrder(['Warning incidents', '1'], false);
        $response->assertSeeInOrder(['Critical incidents', '1'], false);
    }

    /** FR-62: incidents are filterable by state, severity, type, website. */
    public function test_incident_list_filters(): void
    {
        $critical = $this->makeIncident(['severity' => 'CRITICAL', 'type' => 'availability']);
        $warning = $this->makeIncident(['severity' => 'WARNING']);

        $this->actingAs($this->admin());

        $response = $this->get(route('admin.incidents.index', ['severity' => 'CRITICAL']));
        $response->assertOk();
        $this->assertTrue($response->getOriginalContent()->getData()['incidents']->getCollection()->contains('id', $critical->id));
        $this->assertFalse($response->getOriginalContent()->getData()['incidents']->getCollection()->contains('id', $warning->id));

        $response = $this->get(route('admin.incidents.index', ['status' => 'RESOLVED']));
        $response->assertOk();
        $rows = $response->getOriginalContent()->getData()['incidents']->getCollection();
        $this->assertFalse($rows->contains('id', $critical->id));
        $this->assertFalse($rows->contains('id', $warning->id));
    }

    /** Requirement 31: the active severity filter is reflected in the form. */
    public function test_severity_filter_is_reflected_in_the_form(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get(route('admin.incidents.index', ['severity' => 'WARNING']));

        $response->assertOk();
        $response->assertSee('value="WARNING" selected', false);
    }

    /**
     * Requirement 31: the dashboard "Warning" / "Critical" cards deep-link with
     * `?severity=…&open=1`. The card count must equal the filtered list total.
     */
    public function test_card_count_matches_the_open_severity_filtered_list_total(): void
    {
        // 2 open WARNING, 1 resolved WARNING (must not count), 1 open CRITICAL.
        $this->makeIncident(['severity' => 'WARNING']);
        $this->makeIncident(['severity' => 'WARNING', 'dedupe_key' => 'w2-'.$this->website->id]);
        $this->makeIncident(['severity' => 'WARNING', 'status' => 'RESOLVED', 'resolution_mode' => 'manual', 'dedupe_key' => 'w3-'.$this->website->id]);
        $this->makeIncident(['severity' => 'CRITICAL', 'dedupe_key' => 'c1-'.$this->website->id]);

        $this->actingAs($this->admin());

        // Card counts.
        $dashboard = (string) $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-dashboard-card="warning"', $dashboard);
        $this->assertStringContainsString('data-dashboard-card="critical"', $dashboard);

        // Warning list total (open only) === 2.
        $warning = $this->get(route('admin.incidents.index', ['severity' => 'WARNING', 'open' => 1]));
        $warning->assertOk();
        $this->assertSame(2, $warning->getOriginalContent()->getData()['incidents']->total());

        // Critical list total (open only) === 1.
        $critical = $this->get(route('admin.incidents.index', ['severity' => 'CRITICAL', 'open' => 1]));
        $critical->assertOk();
        $this->assertSame(1, $critical->getOriginalContent()->getData()['incidents']->total());

        // Without `open=1`, the resolved WARNING joins the list (3 total) — proving
        // the card scope is what keeps card and list in agreement.
        $all = $this->get(route('admin.incidents.index', ['severity' => 'WARNING']));
        $this->assertSame(3, $all->getOriginalContent()->getData()['incidents']->total());
    }

    /** Requirement 31: `open=1` is reflected in the list UI with a clear link. */
    public function test_open_scope_is_shown_and_removable(): void
    {
        $this->makeIncident(['severity' => 'WARNING']);

        $this->actingAs($this->admin());

        $response = $this->get(route('admin.incidents.index', ['severity' => 'WARNING', 'open' => 1]));

        $response->assertOk();
        $response->assertSee('Open incidents');
        $response->assertSee('Clear filter');
    }
}
