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
}
