<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\AdminNotifications;

use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Per-admin in-app notification centre model (DATABASE.md §3.24, ADR-038).
 *
 * Covers creation, the `unread()` scope, `markAsRead()`, DB-level dedupe on
 * `dedupe_key`, `createDeduped()`, and ownership scoping.
 */
final class AdminNotificationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_row_with_expected_defaults(): void
    {
        $user = User::factory()->create();

        $notification = AdminNotification::create([
            'user_id' => $user->id,
            'type' => AdminNotification::TYPE_INCIDENT_DOWN,
            'title' => 'Website down',
            'body' => 'example.test is not responding',
            'severity' => AdminNotification::SEVERITY_DANGER,
            'link_url' => '/admin/incidents',
            'dedupe_key' => 'incident.down:1',
        ]);

        $this->assertDatabaseHas('admin_notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
            'type' => 'incident.down',
            'read_at' => null,
        ]);
        $this->assertNull($notification->read_at);
    }

    public function test_unread_scope_returns_only_rows_without_read_at(): void
    {
        $user = User::factory()->create();

        AdminNotification::factory()->count(2)->unread()->create(['user_id' => $user->id]);
        AdminNotification::factory()->read()->create(['user_id' => $user->id]);

        $this->assertSame(2, AdminNotification::query()->unread()->count());
        $this->assertSame(3, AdminNotification::query()->count());
    }

    public function test_mark_as_read_stamps_read_at_and_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00', 'UTC'));

        $notification = AdminNotification::factory()->unread()->create();

        $this->assertTrue($notification->markAsRead());
        $notification->refresh();
        $this->assertNotNull($notification->read_at);
        $this->assertSame('2026-10-01 08:00:00', $notification->read_at->toDateTimeString());

        // Second call is a no-op and must not move the timestamp.
        $stampedAt = $notification->read_at->toDateTimeString();
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'UTC'));
        $this->assertFalse($notification->markAsRead());
        $notification->refresh();
        $this->assertSame($stampedAt, $notification->read_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_dedupe_key_is_unique_at_the_database_level(): void
    {
        $user = User::factory()->create();

        AdminNotification::create([
            'user_id' => $user->id,
            'type' => AdminNotification::TYPE_INCIDENT_DOWN,
            'title' => 'First',
            'dedupe_key' => 'incident.down:42',
        ]);

        $this->expectException(QueryException::class);

        AdminNotification::create([
            'user_id' => $user->id,
            'type' => AdminNotification::TYPE_INCIDENT_DOWN,
            'title' => 'Duplicate',
            'dedupe_key' => 'incident.down:42',
        ]);
    }

    public function test_create_deduped_returns_the_existing_row_on_repeat(): void
    {
        $user = User::factory()->create();

        $first = AdminNotification::createDeduped($user->id, 'incident.down:7', [
            'type' => AdminNotification::TYPE_INCIDENT_DOWN,
            'title' => 'Website down',
        ]);

        $second = AdminNotification::createDeduped($user->id, 'incident.down:7', [
            'type' => AdminNotification::TYPE_INCIDENT_DOWN,
            'title' => 'Website down (retry)',
        ]);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, AdminNotification::query()->count());
        $this->assertSame('Website down', $second->title);
    }

    public function test_notifications_are_scoped_to_the_owning_admin(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        AdminNotification::factory()->count(2)->create(['user_id' => $owner->id]);
        AdminNotification::factory()->create(['user_id' => $other->id]);

        $this->assertCount(2, $owner->adminNotifications);
        $this->assertCount(1, $other->adminNotifications);
        $this->assertSame($owner->id, $owner->adminNotifications->first()->user_id);
    }

    public function test_rows_cascade_delete_with_the_owning_admin(): void
    {
        $user = User::factory()->create();
        AdminNotification::factory()->count(2)->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertSame(0, AdminNotification::query()->count());
    }

    public function test_types_catalogue_is_exactly_the_documented_set(): void
    {
        $this->assertSame([
            'incident.down',
            'incident.recovered',
            'security.incident.detected',
            'security.incident.resolved',
            'monitoring.config.changed',
            'notification.config.changed',
        ], AdminNotification::types());
    }
}
