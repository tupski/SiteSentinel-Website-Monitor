<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\AdminNotifications;

use App\Models\AdminNotification;
use App\Models\User;
use App\Services\Notifications\AdminNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In-app notification generation service (Phase G, ADR-038, NOTIFICATIONS.md §15).
 *
 * Covers: idempotent creation, per-recipient dedupe scoping, recipient fan-out,
 * severity normalisation, unknown-type rejection, redaction of sensitive text,
 * and the internal-relative-only link rule.
 */
final class AdminNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AdminNotificationService
    {
        return app(AdminNotificationService::class);
    }

    public function test_notify_creates_a_row_with_the_supplied_fields(): void
    {
        $user = User::factory()->create();

        $notification = $this->service()->notify(
            (int) $user->id,
            AdminNotification::TYPE_INCIDENT_DOWN,
            'Website Example is DOWN',
            'Repeated availability failures opened an incident.',
            AdminNotification::SEVERITY_DANGER,
            '/admin/incidents/9',
            'incident.down:9',
        );

        $this->assertInstanceOf(AdminNotification::class, $notification);
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame(AdminNotification::TYPE_INCIDENT_DOWN, $notification->type);
        $this->assertSame(AdminNotification::SEVERITY_DANGER, $notification->severity);
        $this->assertSame('/admin/incidents/9', $notification->link_url);
        $this->assertNull($notification->read_at);
    }

    public function test_repeat_generation_with_the_same_dedupe_key_does_not_duplicate(): void
    {
        $user = User::factory()->create();

        $first = $this->service()->notify(
            (int) $user->id,
            AdminNotification::TYPE_INCIDENT_DOWN,
            'Website is DOWN',
            null,
            AdminNotification::SEVERITY_DANGER,
            '/admin/incidents/3',
            'incident.down:3',
        );

        $second = $this->service()->notify(
            (int) $user->id,
            AdminNotification::TYPE_INCIDENT_DOWN,
            'Website is DOWN (retry)',
            null,
            AdminNotification::SEVERITY_DANGER,
            '/admin/incidents/3',
            'incident.down:3',
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, AdminNotification::query()->count());
        $this->assertSame('Website is DOWN', $second->title);
    }

    public function test_dedupe_key_is_scoped_per_recipient_so_a_fan_out_never_collides(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $created = $this->service()->notifyRecipients(
            [$a->id, $b->id],
            AdminNotification::TYPE_SECURITY_INCIDENT_DETECTED,
            'Security incident detected',
            null,
            AdminNotification::SEVERITY_DANGER,
            '/admin/incidents/5',
            'security.incident.detected:5',
        );

        $this->assertSame(2, $created);
        $this->assertSame(1, AdminNotification::query()->where('user_id', $a->id)->count());
        $this->assertSame(1, AdminNotification::query()->where('user_id', $b->id)->count());

        // A repeated fan-out is fully idempotent (no new rows for either admin).
        $this->assertSame(0, $this->service()->notifyRecipients(
            [$a->id, $b->id],
            AdminNotification::TYPE_SECURITY_INCIDENT_DETECTED,
            'Security incident detected',
            null,
            AdminNotification::SEVERITY_DANGER,
            '/admin/incidents/5',
            'security.incident.detected:5',
        ));
        $this->assertSame(2, AdminNotification::query()->count());
    }

    public function test_notify_active_admins_targets_only_active_admins(): void
    {
        $activeAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);

        $this->service()->notifyActiveAdmins(
            AdminNotification::TYPE_MONITORING_CONFIG_CHANGED,
            'Monitoring configuration changed',
            'Website added.',
            AdminNotification::SEVERITY_INFO,
            '/admin/websites',
            'monitoring.config.changed:created:x:1:a0',
        );

        $this->assertSame(1, AdminNotification::query()->where('user_id', $activeAdmin->id)->count());
        $this->assertSame(0, AdminNotification::query()->where('user_id', $inactiveAdmin->id)->count());
        $this->assertSame(0, AdminNotification::query()->where('user_id', $viewer->id)->count());
    }

    public function test_unknown_type_is_rejected_without_writing(): void
    {
        $user = User::factory()->create();

        $result = $this->service()->notify(
            (int) $user->id,
            'made.up.type',
            'Nope',
            null,
            AdminNotification::SEVERITY_INFO,
            '/admin',
            'made.up:1',
        );

        $this->assertNull($result);
        $this->assertSame(0, AdminNotification::query()->count());
    }

    public function test_unknown_severity_is_stored_as_null(): void
    {
        $user = User::factory()->create();

        $notification = $this->service()->notify(
            (int) $user->id,
            AdminNotification::TYPE_MONITORING_CONFIG_CHANGED,
            'Config changed',
            null,
            'rainbow',
            '/admin/websites',
            'monitoring.config.changed:x:1:1:a0',
        );

        $this->assertNull($notification->severity);
    }

    public function test_absolute_and_external_links_are_dropped(): void
    {
        $user = User::factory()->create();

        foreach ([
            'https://evil.example/phish',
            'http://evil.example',
            '//evil.example/path',
            'javascript:alert(1)',
            'evil.example/relative',
            '/\evil.example',
            "/admin\r\nSet-Cookie: x=1",
        ] as $hostile) {
            $notification = $this->service()->notify(
                (int) $user->id,
                AdminNotification::TYPE_MONITORING_CONFIG_CHANGED,
                'Config changed',
                null,
                AdminNotification::SEVERITY_INFO,
                $hostile,
                null,
            );

            $this->assertNull(
                $notification->link_url,
                "External/absolute link must be dropped: {$hostile}",
            );
        }
    }

    public function test_internal_relative_paths_are_accepted(): void
    {
        foreach (['/admin/incidents', '/admin/incidents/12', '/admin/websites?page=2', '/admin/settings#branding'] as $safe) {
            $this->assertTrue(
                AdminNotificationService::isValidInternalPath($safe),
                "Internal path must be accepted: {$safe}",
            );
        }
    }

    public function test_sensitive_fragments_in_content_are_redacted(): void
    {
        $user = User::factory()->create();

        $notification = $this->service()->notify(
            (int) $user->id,
            AdminNotification::TYPE_NOTIFICATION_CONFIG_CHANGED,
            'Token: abc123supersecret',
            'password=hunter2secret smtp://user:smtp-super-secret@mail.example.test',
            AdminNotification::SEVERITY_INFO,
            '/admin/notifications',
            null,
        );

        $blob = $notification->title.' '.$notification->body;
        $this->assertStringNotContainsString('abc123supersecret', $blob);
        $this->assertStringNotContainsString('hunter2secret', $blob);
        $this->assertStringNotContainsString('smtp-super-secret', $blob);
    }

    public function test_unread_count_reflects_only_unread_rows(): void
    {
        $user = User::factory()->create();
        AdminNotification::factory()->count(2)->unread()->create(['user_id' => $user->id]);
        AdminNotification::factory()->read()->create(['user_id' => $user->id]);

        $this->assertSame(2, $this->service()->unreadCount((int) $user->id));
        $this->assertSame(2, $user->unreadAdminNotificationsCount());
    }
}
