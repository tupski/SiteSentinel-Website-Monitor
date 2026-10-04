<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\AdminNotifications;

use App\Models\AdminNotification;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Config-change in-app notifications (Phase G, ADR-038, NOTIFICATIONS.md §15.2):
 * monitoring config (website create/update/toggle/delete) and notification
 * config (channel create/update/delete/test, system settings).
 */
final class AdminNotificationConfigEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    private function monitoringCount(): int
    {
        return AdminNotification::query()
            ->where('type', AdminNotification::TYPE_MONITORING_CONFIG_CHANGED)
            ->count();
    }

    private function notificationCount(): int
    {
        return AdminNotification::query()
            ->where('type', AdminNotification::TYPE_NOTIFICATION_CONFIG_CHANGED)
            ->count();
    }

    public function test_website_create_emits_monitoring_config_changed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.websites.store'), [
                'name' => 'Created Site',
                'url' => 'https://example.com',
                'check_interval_seconds' => 300,
                'timeout_seconds' => 10,
                'expected_status' => 200,
            ])
            ->assertRedirect();

        $this->assertSame(1, $this->monitoringCount());
        $this->assertSame(1, AdminNotification::query()->where('user_id', $this->admin->id)->count());
    }

    public function test_website_toggle_and_update_and_delete_each_emit_one(): void
    {
        $website = Website::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)->post(route('admin.websites.toggle', $website))->assertRedirect();
        $this->actingAs($this->admin)->put(route('admin.websites.update', $website), [
            'name' => 'Renamed',
            'url' => 'https://example.org',
            'check_interval_seconds' => 600,
            'timeout_seconds' => 15,
            'expected_status' => 200,
        ])->assertRedirect();
        $this->actingAs($this->admin)->delete(route('admin.websites.destroy', $website))->assertRedirect();

        $this->assertSame(3, $this->monitoringCount());
    }

    public function test_bulk_delete_emits_one_monitoring_config_changed(): void
    {
        $a = Website::factory()->create();
        $b = Website::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.websites.bulk.delete'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSame(1, $this->monitoringCount());
    }

    public function test_channel_create_update_delete_emit_notification_config_changed(): void
    {
        $payload = [
            'type' => 'telegram',
            'name' => 'Ops telegram',
            'enabled' => '1',
            'telegram_chat_id' => '123456',
            'secret_ref' => 'fake-bot-token-secret-value',
        ];

        $this->actingAs($this->admin)->post(route('admin.notifications.store'), $payload)->assertRedirect();
        $this->assertSame(1, $this->notificationCount());

        $channel = NotificationChannel::query()->sole();

        $this->actingAs($this->admin)->put(route('admin.notifications.update', $channel), $payload)->assertRedirect();
        $this->assertSame(2, $this->notificationCount());

        $this->actingAs($this->admin)->post(route('admin.notifications.test-send', $channel))->assertRedirect();
        $this->assertSame(3, $this->notificationCount());

        $this->actingAs($this->admin)->delete(route('admin.notifications.destroy', $channel))->assertRedirect();
        $this->assertSame(4, $this->notificationCount());
    }

    public function test_system_settings_update_emits_notification_config_changed(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
        ])->assertRedirect(route('admin.settings.edit'));

        $this->assertSame(1, $this->notificationCount());
    }
}
