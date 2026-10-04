<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\AdminNotifications;

use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * In-app notification centre data + mark-read API (Phase G, ADR-038,
 * NOTIFICATIONS.md §15.4): JSON contract, unread counts, ownership (IDOR),
 * and authz (guest / viewer).
 */
final class AdminNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer', 'is_active' => true]);
    }

    // --- Authz --------------------------------------------------------------

    public function test_guest_is_rejected_from_every_endpoint(): void
    {
        $notification = AdminNotification::factory()->create();

        $this->get(route('admin.notifications.in-app.index'))->assertRedirect(route('login'));
        $this->get(route('admin.notifications.in-app.unread-count'))->assertRedirect(route('login'));
        $this->post(route('admin.notifications.read', $notification))->assertRedirect(route('login'));
        $this->post(route('admin.notifications.read-all'))->assertRedirect(route('login'));

        // JSON-expecting guests get a 401, not a redirect (EnsureAdmin contract).
        $this->getJson(route('admin.notifications.in-app.index'))->assertUnauthorized();
    }

    public function test_viewer_is_forbidden(): void
    {
        $notification = AdminNotification::factory()->create();

        $this->actingAs($this->viewer())->get(route('admin.notifications.in-app.index'))->assertForbidden();
        $this->actingAs($this->viewer())->get(route('admin.notifications.in-app.unread-count'))->assertForbidden();
        $this->actingAs($this->viewer())->post(route('admin.notifications.read', $notification))->assertForbidden();
        $this->actingAs($this->viewer())->post(route('admin.notifications.read-all'))->assertForbidden();
    }

    // --- JSON contract ------------------------------------------------------

    public function test_index_returns_the_documented_json_contract(): void
    {
        $admin = $this->admin();
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'UTC'));

        // The read row is created first so the unread row has the higher id and
        // sorts first under the frozen clock (order by created_at desc, id desc).
        AdminNotification::factory()->read()->create(['user_id' => $admin->id]);

        $unread = AdminNotification::factory()->unread()->create([
            'user_id' => $admin->id,
            'type' => AdminNotification::TYPE_INCIDENT_DOWN,
            'title' => 'Website Example is DOWN',
            'body' => 'Repeated availability failures opened an incident.',
            'severity' => AdminNotification::SEVERITY_DANGER,
            'link_url' => '/admin/incidents/4',
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.notifications.in-app.index'));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'type', 'title', 'body', 'severity', 'link_url', 'read_at', 'created_at']],
                'unread_count',
                'meta' => ['per_page', 'returned', 'total'],
            ])
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $unread->id)
            ->assertJsonPath('data.0.type', 'incident.down')
            ->assertJsonPath('data.0.link_url', '/admin/incidents/4');

        // created_at is UTC ISO-8601.
        $createdAt = (string) $response->json('data.0.created_at');
        $this->assertStringContainsString('2026-10-01T08:00:00', $createdAt);
    }

    public function test_index_only_returns_the_authenticated_admins_rows(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        AdminNotification::factory()->count(2)->create(['user_id' => $admin->id]);
        AdminNotification::factory()->count(5)->create(['user_id' => $other->id]);

        $this->actingAs($admin)
            ->getJson(route('admin.notifications.in-app.index'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_unread_count_endpoint_is_cheap_and_scoped(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        AdminNotification::factory()->count(3)->unread()->create(['user_id' => $admin->id]);
        AdminNotification::factory()->count(9)->unread()->create(['user_id' => $other->id]);

        $this->actingAs($admin)
            ->getJson(route('admin.notifications.in-app.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 3]);
    }

    // --- Mark read ----------------------------------------------------------

    public function test_mark_read_updates_the_count_and_persists(): void
    {
        $admin = $this->admin();
        $notification = AdminNotification::factory()->unread()->create(['user_id' => $admin->id]);
        AdminNotification::factory()->unread()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->postJson(route('admin.notifications.read', $notification))
            ->assertOk()
            ->assertJson(['ok' => true, 'unread_count' => 1]);

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_mark_read_degrades_to_a_redirect_for_non_js_posts(): void
    {
        $admin = $this->admin();
        $notification = AdminNotification::factory()->unread()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('admin.notifications.in-app.index'))
            ->post(route('admin.notifications.read', $notification))
            ->assertRedirect(route('admin.notifications.in-app.index'))
            ->assertSessionHas('status');

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_cannot_mark_another_admins_notification_read(): void
    {
        $owner = $this->admin();
        $attacker = $this->admin();
        $notification = AdminNotification::factory()->unread()->create(['user_id' => $owner->id]);

        $this->actingAs($attacker)
            ->post(route('admin.notifications.read', $notification))
            ->assertNotFound();

        $this->assertNull($notification->refresh()->read_at);
    }

    public function test_mark_all_read_only_affects_the_authenticated_admin(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        AdminNotification::factory()->count(3)->unread()->create(['user_id' => $admin->id]);
        AdminNotification::factory()->count(4)->unread()->create(['user_id' => $other->id]);

        $this->actingAs($admin)
            ->postJson(route('admin.notifications.read-all'))
            ->assertOk()
            ->assertJson(['ok' => true, 'unread_count' => 0, 'affected' => 3]);

        $this->assertSame(0, AdminNotification::query()->where('user_id', $admin->id)->whereNull('read_at')->count());
        $this->assertSame(4, AdminNotification::query()->where('user_id', $other->id)->whereNull('read_at')->count());
    }

    public function test_mark_all_read_degrades_to_a_redirect_for_non_js_posts(): void
    {
        $admin = $this->admin();
        AdminNotification::factory()->count(2)->unread()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('admin.notifications.in-app.index'))
            ->post(route('admin.notifications.read-all'))
            ->assertRedirect(route('admin.notifications.in-app.index'))
            ->assertSessionHas('status');

        $this->assertSame(0, AdminNotification::query()->where('user_id', $admin->id)->whereNull('read_at')->count());
    }
}
