<?php

declare(strict_types=1);

namespace Tests\Feature\Incidents;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\NotificationLog;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    /** Persist a snapshot row + its on-disk HTML artefact. */
    private function makeSnapshot(Incident $incident, string $html = '<html><body>evidence</body></html>'): Snapshot
    {
        Storage::fake('local');
        $path = "snapshots/{$this->website->id}/".uniqid().'.html';
        Storage::disk('local')->put($path, $html);

        return Snapshot::create([
            'website_id' => $this->website->id,
            'incident_id' => $incident->id,
            'html_path' => $path,
            'captured_at' => now(),
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

    /** Evidence snapshots are listed with a "View" affordance (modal trigger). */
    public function test_incident_detail_lists_snapshots_with_a_view_trigger(): void
    {
        $incident = $this->makeIncident();
        $snapshot = $this->makeSnapshot($incident);
        $this->actingAs($this->admin());

        $response = $this->get(route('admin.incidents.show', $incident));

        $response->assertOk();
        $response->assertSee('Evidence snapshots');
        $response->assertSee('snapshot-'.$snapshot->id, false);
        // The captured HTML is only ever framed from the admin endpoint, never
        // injected inline (AGENTS.md §11).
        $response->assertSee('sandbox=""', false);
    }

    /** The snapshot endpoint is admin-only like every other incident route. */
    public function test_snapshot_endpoint_requires_authentication_and_admin(): void
    {
        $incident = $this->makeIncident();
        $snapshot = $this->makeSnapshot($incident);

        $this->get(route('admin.incidents.snapshots.show', [$incident, $snapshot]))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]));
        $this->get(route('admin.incidents.snapshots.show', [$incident, $snapshot]))
            ->assertForbidden();
    }

    /** Admin receives the captured bytes as an inert, sandboxed document. */
    public function test_admin_can_open_a_snapshot_rendered_as_a_sandboxed_document(): void
    {
        $incident = $this->makeIncident();
        $snapshot = $this->makeSnapshot($incident, '<html><body>captured-evidence</body></html>');
        $this->actingAs($this->admin());

        $response = $this->get(route('admin.incidents.snapshots.show', [$incident, $snapshot]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        $this->assertStringContainsString('captured-evidence', (string) $response->getContent());
    }

    /** A snapshot can never be read through a different incident (IDOR). */
    public function test_snapshot_cannot_be_read_through_another_incident(): void
    {
        $incident = $this->makeIncident();
        $snapshot = $this->makeSnapshot($incident);
        $other = $this->makeIncident(['dedupe_key' => 'other-'.$this->website->id]);
        $this->actingAs($this->admin());

        $this->get(route('admin.incidents.snapshots.show', [$other, $snapshot]))
            ->assertNotFound();
    }

    /** A missing on-disk artefact is a 404, never a fatal error. */
    public function test_snapshot_missing_file_returns_not_found(): void
    {
        $incident = $this->makeIncident();
        $snapshot = $this->makeSnapshot($incident);
        Storage::disk('local')->delete($snapshot->html_path);
        $this->actingAs($this->admin());

        $this->get(route('admin.incidents.snapshots.show', [$incident, $snapshot]))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Incident detail UI — pagination, badges, modals, back button.
    // -----------------------------------------------------------------------

    /** Rule attribution paginates server-side at 10 rows/page, keyed by rule id. */
    public function test_rule_attribution_paginates_at_ten_rows_per_page(): void
    {
        $rules = [];
        for ($i = 1; $i <= 12; $i++) {
            $rules[sprintf('RULE-CNT-%03d', $i)] = [
                'category' => 'content-fingerprint',
                'weight' => 1,
                'confidence' => 'low',
                'reason' => 'reason '.$i,
            ];
        }
        $incident = $this->makeIncident(['triggered_rules' => $rules]);
        $this->actingAs($this->admin());

        $response = $this->get(route('admin.incidents.show', $incident));
        $response->assertOk();

        $attribution = $response->viewData('ruleAttribution');
        $this->assertSame(10, $attribution->perPage());
        $this->assertSame(12, $attribution->total());
        $this->assertCount(10, $attribution->items());
        // Keys are preserved so each row can address its own modal.
        $this->assertSame('RULE-CNT-001', array_key_first($attribution->items()));

        $page2 = $this->get(route('admin.incidents.show', ['incident' => $incident, 'rule_page' => 2]));
        $page2->assertOk();
        $this->assertSame(2, $page2->viewData('ruleAttribution')->count());
    }

    /** Delivery history, timeline and snapshots each paginate at 10 rows/page. */
    public function test_detail_sections_paginate_at_ten_rows_per_page(): void
    {
        $incident = $this->makeIncident();

        for ($i = 0; $i < 12; $i++) {
            NotificationLog::create([
                'incident_id' => $incident->id,
                'status' => 'sent',
                'attempt' => 1,
                'sent_at' => now(),
            ]);

            IncidentEvent::create([
                'incident_id' => $incident->id,
                'event_type' => 'evidence_appended',
                'note' => 'event '.$i,
                'created_at' => now(),
            ]);

            Snapshot::create([
                'website_id' => $this->website->id,
                'incident_id' => $incident->id,
                'html_path' => "snapshots/{$this->website->id}/{$i}.html",
                'captured_at' => now(),
            ]);
        }

        $this->actingAs($this->admin());

        $response = $this->get(route('admin.incidents.show', $incident));
        $response->assertOk();

        $this->assertSame(10, $response->viewData('deliveryLogs')->perPage());
        $this->assertSame(12, $response->viewData('deliveryLogs')->total());
        $this->assertSame(10, $response->viewData('timeline')->perPage());
        $this->assertSame(12, $response->viewData('timeline')->total());
        $this->assertSame(10, $response->viewData('snapshots')->perPage());
        $this->assertSame(12, $response->viewData('snapshots')->total());
    }

    /** Severity and Status render as colour badges on the detail page. */
    public function test_incident_detail_renders_severity_and_status_badges(): void
    {
        $incident = $this->makeIncident(['severity' => 'CRITICAL', 'status' => 'DETECTED']);
        $this->actingAs($this->admin());

        $html = (string) $this->get(route('admin.incidents.show', $incident))->assertOk()->getContent();

        // CRITICAL -> danger token, DETECTED -> danger token (app conventions).
        $this->assertStringContainsString('bg-danger-muted', $html);
        $this->assertStringContainsString('text-danger', $html);
    }

    /** Severity and Status render as colour badges on the incidents list. */
    public function test_incident_index_renders_severity_and_status_badges(): void
    {
        $this->makeIncident(['severity' => 'WARNING', 'status' => 'DETECTED']);
        $this->actingAs($this->admin());

        $html = (string) $this->get(route('admin.incidents.index'))->assertOk()->getContent();

        // WARNING -> warning token.
        $this->assertStringContainsString('bg-warning-muted', $html);
        $this->assertStringContainsString('text-warning', $html);
    }

    /** Rule code opens a rule-details modal; reason opens the affected-checks modal. */
    public function test_rule_code_and_reason_open_their_modals(): void
    {
        $this->seed(DetectionRuleSeeder::class);

        $incident = $this->makeIncident([
            'triggered_rules' => [
                'RULE-CNT-001' => ['category' => 'content-fingerprint', 'weight' => 1, 'confidence' => 'low', 'reason' => 'Content hash changed'],
            ],
        ]);
        $this->actingAs($this->admin());

        $html = (string) $this->get(route('admin.incidents.show', $incident))->assertOk()->getContent();

        // Rule code + reason dispatch the two modals.
        $this->assertStringContainsString("open-modal', { name: 'rule-RULE-CNT-001' }", $html);
        $this->assertStringContainsString("open-modal', { name: 'reason-RULE-CNT-001' }", $html);
        // Rule registry metadata (name) is available for the modal body.
        $this->assertStringContainsString('Homepage fingerprint / hash changed', $html);
        // Confidence colour badge.
        $this->assertStringContainsString('bg-surface-muted', $html);
    }

    /** "Back to incidents" is rendered as a shared ui/button, not a plain link. */
    public function test_back_to_incidents_is_a_button(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->admin());

        $html = (string) $this->get(route('admin.incidents.show', $incident))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('admin.incidents.index').'"', $html);
        $this->assertStringContainsString('inline-flex items-center justify-center gap-2 font-medium', $html);
    }
}
