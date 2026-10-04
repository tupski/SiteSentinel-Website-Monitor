<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\RunWebsiteCheck;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Plan S2 — websites table actions + bulk operations.
 *
 * Covers the manual "run check" queue boundary, bulk enable/disable/delete,
 * modal-gated row actions, and the authorization/IDOR guarantees that must
 * hold for every one of these entry points.
 */
final class WebsiteActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    // ---------------------------------------------------------------------
    // 1. Manual run-check
    // ---------------------------------------------------------------------

    public function test_run_check_enqueues_the_job_and_performs_no_http_probe(): void
    {
        Queue::fake();
        Http::fake(); // any outbound request would be recorded and can be asserted empty

        $website = Website::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.websites.check', $website));

        $response->assertRedirect(route('admin.websites.index'));
        $response->assertSessionHas('status');

        Queue::assertPushed(RunWebsiteCheck::class, function (RunWebsiteCheck $job) use ($website): bool {
            return $job->website->is($website);
        });
        Queue::assertPushed(RunWebsiteCheck::class, 1);

        // No probe may run inline in the request path (AGENTS.md §9).
        Http::assertNothingSent();
    }

    public function test_run_check_records_an_audit_event(): void
    {
        Queue::fake();

        $website = Website::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.websites.check', $website));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'website.check_queued',
            'subject_id' => $website->id,
        ]);
    }

    public function test_run_check_rejects_unauthenticated_requests(): void
    {
        $website = Website::factory()->create();

        $this->post(route('admin.websites.check', $website))->assertRedirect(route('login'));
    }

    public function test_run_check_rejects_non_admin_users(): void
    {
        Queue::fake();

        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $website = Website::factory()->create();

        $this->actingAs($viewer)
            ->post(route('admin.websites.check', $website))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------------
    // 2. Icon-only row actions + confirmation modal presence
    // ---------------------------------------------------------------------

    public function test_row_actions_are_icon_only_with_accessible_labels(): void
    {
        Website::factory()->create(['name' => 'Guarded', 'is_active' => true]);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.websites.index'))
            ->assertOk()
            ->getContent();

        // Accessible labels for every row action (ADR-034 / FR-107).
        $this->assertStringContainsString('aria-label="Run check for Guarded"', $html);
        $this->assertStringContainsString('aria-label="Edit Guarded"', $html);
        $this->assertStringContainsString('aria-label="Disable monitoring for Guarded"', $html);
        $this->assertStringContainsString('aria-label="Delete Guarded"', $html);

        // Icons, not text labels, carry the action.
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('>Edit</a>', $html);
        $this->assertStringNotContainsString('>Disable</button>', $html);
    }

    // ---------------------------------------------------------------------
    // 2b. B1 — enable/disable monitoring clarity
    // ---------------------------------------------------------------------

    public function test_toggle_action_shows_distinct_icons_tints_and_labels_per_state(): void
    {
        Website::factory()->create(['name' => 'Running', 'is_active' => true]);
        Website::factory()->create(['name' => 'Stopped', 'is_active' => false]);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.websites.index'))
            ->assertOk()
            ->getContent();

        // The toggle offers the ACTION to take, per state.
        $this->assertStringContainsString('aria-label="Disable monitoring for Running"', $html);
        $this->assertStringContainsString('aria-label="Enable monitoring for Stopped"', $html);
        $this->assertStringContainsString('title="Disable monitoring"', $html);
        $this->assertStringContainsString('title="Enable monitoring"', $html);

        // Distinct glyphs: pause-circle (disable) vs play-circle (enable).
        $this->assertStringContainsString('M14.25 9v6', $html, 'pause-circle path expected for an active row');
        $this->assertStringContainsString('M15.91 11.672', $html, 'play-circle path expected for an inactive row');

        // Distinct semantic tints (danger for disable, success for enable).
        $this->assertStringContainsString('!text-danger', $html);
        $this->assertStringContainsString('!text-success', $html);
    }

    // ---------------------------------------------------------------------
    // 2c. B2 — URL column is a safe, new-tab hyperlink
    // ---------------------------------------------------------------------

    public function test_url_column_renders_a_safe_new_tab_anchor(): void
    {
        Website::factory()->create([
            'name' => 'Linkable',
            'url' => 'https://example.com/some/very/long/path?ref=admin',
            'scheme' => 'https',
            'host' => 'example.com',
        ]);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.websites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="https://example.com/some/very/long/path?ref=admin"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        // The full URL stays recoverable via `title` even when visually truncated.
        $this->assertStringContainsString('title="https://example.com/some/very/long/path?ref=admin"', $html);
    }

    // ---------------------------------------------------------------------
    // 2d. B3 — status-page association column
    // ---------------------------------------------------------------------

    public function test_status_page_column_shows_not_linked_when_unassigned(): void
    {
        Website::factory()->create(['name' => 'Orphan']);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.websites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Not linked', $html);
    }

    public function test_status_page_column_links_a_public_page_by_its_real_route(): void
    {
        $page = StatusPage::create([
            'name' => 'Public page',
            'slug' => 'public-page',
            'is_default' => false,
            'visibility_mode' => StatusPage::MODE_PUBLIC,
        ]);

        Website::factory()->create(['name' => 'Published', 'status_page_id' => $page->id]);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.websites.index'))
            ->assertOk()
            ->getContent();

        // Resolved through the real status page route, not a hand-built string.
        $this->assertStringContainsString('href="'.route('status.show', $page).'"', $html);
        $this->assertMatchesRegularExpression('/>\s*'.preg_quote($page->slug, '/').'\s*</', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_status_page_column_does_not_link_a_non_public_page(): void
    {
        $page = StatusPage::create([
            'name' => 'Private page',
            'slug' => 'private-page',
            'is_default' => false,
            'visibility_mode' => StatusPage::MODE_PRIVATE,
        ]);

        Website::factory()->create(['name' => 'Hidden', 'status_page_id' => $page->id]);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.websites.index'))
            ->assertOk()
            ->getContent();

        // The slug is shown, but a Private page is never linkable (no 404 destination).
        $this->assertStringContainsString($page->slug, $html);
        $this->assertStringNotContainsString('href="'.route('status.show', $page).'"', $html);
    }

    public function test_status_page_column_is_eager_loaded_without_an_n_plus_one(): void
    {
        $page = StatusPage::create([
            'name' => 'Shared page',
            'slug' => 'shared-page',
            'is_default' => false,
            'visibility_mode' => StatusPage::MODE_PUBLIC,
        ]);

        foreach (range(1, 5) as $i) {
            Website::factory()->create(['name' => "Site {$i}", 'status_page_id' => $page->id]);
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->get(route('admin.websites.index'))->assertOk();

        $statusPageQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from "status_pages"'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $statusPageQueries, 'the assigned status page must be eager-loaded exactly once');
    }

    public function test_delete_action_is_gated_by_a_confirmation_modal(): void
    {
        Website::factory()->create(['name' => 'Doomed']);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.websites.index'))
            ->assertOk()
            ->getContent();

        // The delete trigger opens the modal rather than submitting directly.
        $this->assertStringContainsString("open-modal', { name: 'delete-website-", $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);

        // Warning text states the real retention semantics (not a false cascade claim).
        $this->assertStringContainsString('incident history is append-only', $html);
        $this->assertStringContainsString('retention windows', $html);
    }

    // ---------------------------------------------------------------------
    // 3. Bulk actions
    // ---------------------------------------------------------------------

    public function test_bulk_enable_updates_only_selected_inactive_rows(): void
    {
        $target = Website::factory()->create(['is_active' => false]);
        $other = Website::factory()->create(['is_active' => false]);

        $response = $this->actingAs($this->admin)->post(route('admin.websites.bulk.enable'), [
            'ids' => [$target->id],
        ]);

        $response->assertRedirect(route('admin.websites.index'));
        $response->assertSessionHas('status');

        $this->assertTrue($target->fresh()->is_active);
        $this->assertFalse($other->fresh()->is_active, 'unselected rows must not change');
    }

    public function test_bulk_disable_updates_only_selected_active_rows(): void
    {
        $target = Website::factory()->create(['is_active' => true]);
        $other = Website::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)->post(route('admin.websites.bulk.disable'), [
            'ids' => [$target->id],
        ])->assertRedirect(route('admin.websites.index'));

        $this->assertFalse($target->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active, 'unselected rows must not change');
    }

    public function test_bulk_disable_is_idempotent(): void
    {
        $website = Website::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)->post(route('admin.websites.bulk.disable'), ['ids' => [$website->id]]);
        $this->actingAs($this->admin)->post(route('admin.websites.bulk.disable'), ['ids' => [$website->id]]);

        $this->assertFalse($website->fresh()->is_active);
    }

    public function test_bulk_delete_soft_deletes_selected_rows_only(): void
    {
        $target = Website::factory()->create();
        $other = Website::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.websites.bulk.delete'), [
            'ids' => [$target->id],
        ])->assertRedirect(route('admin.websites.index'));

        $this->assertSoftDeleted($target);
        $this->assertNotSoftDeleted($other);
    }

    public function test_bulk_delete_keeps_append_only_incident_history(): void
    {
        $website = Website::factory()->create();

        Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ]);

        $this->actingAs($this->admin)->post(route('admin.websites.bulk.delete'), [
            'ids' => [$website->id],
        ]);

        // Soft delete: the website row survives and incident history is not cascaded away.
        $this->assertSoftDeleted($website);
        $this->assertDatabaseHas('incidents', ['website_id' => $website->id]);
    }

    public function test_bulk_action_requires_a_non_empty_selection(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.websites.bulk.enable'), ['ids' => []]);

        $response->assertSessionHasErrors('ids');
    }

    public function test_bulk_action_rejects_non_integer_ids(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.websites.bulk.disable'), [
            'ids' => ['not-an-id'],
        ]);

        $response->assertSessionHasErrors('ids.0');
    }

    public function test_bulk_action_is_safe_when_selected_ids_do_not_exist(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.websites.bulk.delete'), [
            'ids' => [999999],
        ]);

        $response->assertRedirect(route('admin.websites.index'));
        $response->assertSessionHas('status');
    }

    public function test_bulk_actions_cannot_affect_soft_deleted_rows(): void
    {
        $website = Website::factory()->create();
        $website->delete();

        $this->actingAs($this->admin)->post(route('admin.websites.bulk.enable'), [
            'ids' => [$website->id],
        ])->assertRedirect(route('admin.websites.index'));

        $this->assertSoftDeleted($website);
    }

    // ---------------------------------------------------------------------
    // 4. Authorization / IDOR
    // ---------------------------------------------------------------------

    public function test_all_new_routes_reject_unauthenticated_requests(): void
    {
        $website = Website::factory()->create();

        $this->post(route('admin.websites.check', $website))->assertRedirect(route('login'));
        $this->post(route('admin.websites.bulk.enable'), ['ids' => [$website->id]])->assertRedirect(route('login'));
        $this->post(route('admin.websites.bulk.disable'), ['ids' => [$website->id]])->assertRedirect(route('login'));
        $this->post(route('admin.websites.bulk.delete'), ['ids' => [$website->id]])->assertRedirect(route('login'));
    }

    public function test_all_new_routes_reject_non_admin_users(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $website = Website::factory()->create();

        $this->actingAs($viewer)->post(route('admin.websites.check', $website))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.websites.bulk.enable'), ['ids' => [$website->id]])->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.websites.bulk.disable'), ['ids' => [$website->id]])->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.websites.bulk.delete'), ['ids' => [$website->id]])->assertForbidden();
    }

    public function test_bulk_action_rejects_a_selection_larger_than_the_bound(): void
    {
        $ids = range(1, 101);

        $this->actingAs($this->admin)
            ->post(route('admin.websites.bulk.delete'), ['ids' => $ids])
            ->assertSessionHasErrors('ids');
    }
}
