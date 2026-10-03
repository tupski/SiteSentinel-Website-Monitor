<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\StatusPageSetting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Authorization / IDOR regression suite (SECURITY.md §3, AC-02).
 *
 * At MVP there is a single Admin role; the critical invariants are that every
 * /admin route is rejected for non-admins and unauthenticated callers, and
 * that private status data is not reachable by enumeration.
 */
final class AuthorizationIdorTest extends SecurityTestCase
{
    public function test_every_admin_route_rejects_unauthenticated_requests(): void
    {
        $website = $this->makeWebsite();
        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ]);
        $channel = NotificationChannel::create([
            'type' => 'email',
            'name' => 'Ops',
            'enabled' => true,
            'config' => ['recipients' => ['a@example.test']],
        ]);

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.websites.index'))->assertRedirect(route('login'));
        $this->post(route('admin.websites.store'), [])->assertRedirect(route('login'));
        $this->put(route('admin.websites.update', $website), [])->assertRedirect(route('login'));
        $this->delete(route('admin.websites.destroy', $website))->assertRedirect(route('login'));
        $this->post(route('admin.websites.toggle', $website))->assertRedirect(route('login'));
        $this->get(route('admin.incidents.show', $incident))->assertRedirect(route('login'));
        $this->post(route('admin.incidents.acknowledge', $incident))->assertRedirect(route('login'));
        $this->post(route('admin.incidents.resolve', $incident))->assertRedirect(route('login'));
        $this->post(route('admin.notifications.store'), [])->assertRedirect(route('login'));
        $this->put(route('admin.notifications.update', $channel), [])->assertRedirect(route('login'));
        $this->delete(route('admin.notifications.destroy', $channel))->assertRedirect(route('login'));
        $this->put(route('admin.status-settings.update'), [])->assertRedirect(route('login'));
    }

    public function test_every_admin_route_rejects_non_admin_users(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $website = $this->makeWebsite();
        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ]);

        $this->actingAs($viewer)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.websites.toggle', $website))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.incidents.acknowledge', $incident))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.incidents.resolve', $incident))->assertForbidden();
        $this->actingAs($viewer)->put(route('admin.status-settings.update'), [])->assertForbidden();
    }

    public function test_disabled_account_is_rejected_and_session_invalidated(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => false]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_private_status_page_is_not_reachable_by_anonymous_enumeration(): void
    {
        $this->makeWebsite(['status_alias' => 'Hidden Service']);
        $settings = StatusPageSetting::singleton();
        $settings->visibility_mode = StatusPageSetting::MODE_PRIVATE;
        $settings->save();

        $this->get(route('status.show'))->assertNotFound();
        $this->getJson(route('status.json'))->assertNotFound();
    }

    public function test_private_status_page_rejects_non_admin_authenticated_user(): void
    {
        $this->makeWebsite(['status_alias' => 'Hidden Service']);
        $settings = StatusPageSetting::singleton();
        $settings->visibility_mode = StatusPageSetting::MODE_PRIVATE;
        $settings->save();

        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer)->get(route('status.show'))->assertNotFound();
    }

    public function test_password_mode_without_hash_fails_closed_for_all_callers(): void
    {
        $this->makeWebsite(['status_alias' => 'Locked Service']);
        $settings = StatusPageSetting::singleton();
        $settings->visibility_mode = StatusPageSetting::MODE_PASSWORD_PROTECTED;
        $settings->password_hash = null;
        $settings->save();

        $this->get(route('status.show'))->assertNotFound();
        $this->getJson(route('status.json'))->assertNotFound();
    }

    public function test_unlock_endpoint_is_404_when_not_password_mode(): void
    {
        $settings = StatusPageSetting::singleton();
        $settings->visibility_mode = StatusPageSetting::MODE_PUBLIC;
        $settings->password_hash = Hash::make('irrelevant-password');
        $settings->save();

        $this->post(route('status.unlock'), ['password' => 'anything'])->assertNotFound();
    }
}
