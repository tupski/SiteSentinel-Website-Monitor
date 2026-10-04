<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\AdminNotifications;

use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Full-page in-app notification centre (Requirement 28 / Phase H, ADR-038).
 *
 * Authz mirrors {@see AdminNotificationApiTest}: guest redirect, viewer 403,
 * admin 200. The page is distinct from the outbound-channel
 * `admin.notifications.index` route.
 */
final class AdminNotificationPageTest extends TestCase
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.notifications.in-app.page'))->assertRedirect(route('login'));
    }

    public function test_viewer_is_forbidden(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('admin.notifications.in-app.page'))
            ->assertForbidden();
    }

    public function test_admin_sees_the_page(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.notifications.in-app.page'))
            ->assertOk()
            ->assertSee('Notifications');
    }

    // --- Server-rendered content -------------------------------------------

    public function test_page_is_server_rendered_with_rows_and_unread_count(): void
    {
        $admin = $this->admin();

        AdminNotification::factory()->read()->create([
            'user_id' => $admin->id,
            'title' => 'Recovered: Example',
        ]);
        AdminNotification::factory()->unread()->create([
            'user_id' => $admin->id,
            'title' => 'Down: Example',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.notifications.in-app.page'));

        $response->assertOk()
            ->assertSee('Recovered: Example')
            ->assertSee('Down: Example')
            ->assertSee('Mark all as read');

        // The page works without JS: it binds the enhancement component and
        // exposes a stable error container.
        $response->assertSee('data-notification-list-error', false);
        $response->assertSee('x-data="notificationList(', false);
    }

    public function test_page_only_shows_the_authenticated_admins_rows(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        AdminNotification::factory()->create(['user_id' => $admin->id, 'title' => 'Mine']);
        AdminNotification::factory()->create(['user_id' => $other->id, 'title' => 'Theirs']);

        $this->actingAs($admin)
            ->get(route('admin.notifications.in-app.page'))
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Theirs');
    }

    // --- Filtering ----------------------------------------------------------

    public function test_status_filter_restricts_to_unread(): void
    {
        $admin = $this->admin();
        AdminNotification::factory()->read()->create(['user_id' => $admin->id, 'title' => 'Already Read']);
        AdminNotification::factory()->unread()->create(['user_id' => $admin->id, 'title' => 'Still Unread']);

        $this->actingAs($admin)
            ->get(route('admin.notifications.in-app.page', ['status' => 'unread']))
            ->assertOk()
            ->assertSee('Still Unread')
            ->assertDontSee('Already Read');
    }

    public function test_status_filter_restricts_to_read(): void
    {
        $admin = $this->admin();
        AdminNotification::factory()->read()->create(['user_id' => $admin->id, 'title' => 'Already Read']);
        AdminNotification::factory()->unread()->create(['user_id' => $admin->id, 'title' => 'Still Unread']);

        $this->actingAs($admin)
            ->get(route('admin.notifications.in-app.page', ['status' => 'read']))
            ->assertOk()
            ->assertSee('Already Read')
            ->assertDontSee('Still Unread');
    }

    public function test_type_filter_is_whitelisted(): void
    {
        $admin = $this->admin();
        AdminNotification::factory()->create([
            'user_id' => $admin->id,
            'type' => AdminNotification::TYPE_INCIDENT_DOWN,
            'title' => 'Availability event',
        ]);
        AdminNotification::factory()->create([
            'user_id' => $admin->id,
            'type' => AdminNotification::TYPE_NOTIFICATION_CONFIG_CHANGED,
            'title' => 'Config event',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.notifications.in-app.page', ['type' => AdminNotification::TYPE_INCIDENT_DOWN]))
            ->assertOk()
            ->assertSee('Availability event')
            ->assertDontSee('Config event');

        // An unknown type is ignored (falls back to "all"), never an error.
        $this->actingAs($admin)
            ->get(route('admin.notifications.in-app.page', ['type' => 'not.a.real.type']))
            ->assertOk()
            ->assertSee('Availability event')
            ->assertSee('Config event');
    }

    // --- Link safety --------------------------------------------------------

    public function test_only_valid_internal_links_are_rendered_as_anchors(): void
    {
        $admin = $this->admin();

        AdminNotification::factory()->create([
            'user_id' => $admin->id,
            'title' => 'Safe link row',
            'link_url' => '/admin/incidents/4',
        ]);
        AdminNotification::factory()->create([
            'user_id' => $admin->id,
            'title' => 'Unsafe link row',
            // A malicious payload that bypassed the write-time guard.
            'link_url' => 'https://evil.example.com/phish',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.notifications.in-app.page'));

        $response->assertOk();
        $response->assertSee('href="/admin/incidents/4"', false);
        $response->assertDontSee('evil.example.com');
    }
}
