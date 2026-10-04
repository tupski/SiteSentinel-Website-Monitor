<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\RunWebsiteCheck;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertStringContainsString('aria-label="Disable Guarded"', $html);
        $this->assertStringContainsString('aria-label="Delete Guarded"', $html);

        // Icons, not text labels, carry the action.
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('>Edit</a>', $html);
        $this->assertStringNotContainsString('>Disable</button>', $html);
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
