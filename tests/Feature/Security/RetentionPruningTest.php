<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Check;
use App\Models\Incident;
use App\Models\NotificationCooldown;
use App\Models\NotificationLog;
use App\Models\Snapshot;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schedule;

/**
 * Retention pruning regression suite (PLAN.md Phase 9 AC-9-05 / AC-19).
 *
 * Verifies that `model:prune` actually deletes rows per the frozen retention
 * windows (checks 30d, snapshots 14d, notification_logs 90d, incidents 365d)
 * and that recent rows survive.
 */
final class RetentionPruningTest extends SecurityTestCase
{
    /**
     * Insert a row with an explicit `created_at` (not mass-assignable).
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    private function aged(string $model, array $attributes, int $daysAgo): object
    {
        /** @var Model $row */
        $row = new $model($attributes);
        $row->created_at = now()->subDays($daysAgo);

        $updatedColumn = $row->getUpdatedAtColumn();
        if ($updatedColumn !== null) {
            $row->{$updatedColumn} = now()->subDays($daysAgo);
        }

        $row->save();

        return $row;
    }

    public function test_checks_are_pruned_after_retention_window(): void
    {
        $website = $this->makeWebsite();

        $old = $this->aged(Check::class, [
            'website_id' => $website->id,
            'check_key' => 'old-check',
            'started_at' => now()->subDays(31),
        ], 31);

        $recent = Check::create([
            'website_id' => $website->id,
            'check_key' => 'recent-check',
            'started_at' => now(),
        ]);

        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('checks', ['id' => $old->id]);
        $this->assertDatabaseHas('checks', ['id' => $recent->id]);
    }

    public function test_snapshots_are_pruned_after_fourteen_days(): void
    {
        $website = $this->makeWebsite();

        $old = $this->aged(Snapshot::class, [
            'website_id' => $website->id,
            'html_path' => 'snapshots/old.html',
            'captured_at' => now()->subDays(15),
        ], 15);

        $recent = Snapshot::create([
            'website_id' => $website->id,
            'html_path' => 'snapshots/recent.html',
            'captured_at' => now(),
        ]);

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('snapshots', ['id' => $old->id]);
        $this->assertDatabaseHas('snapshots', ['id' => $recent->id]);
    }

    public function test_notification_logs_are_pruned_after_ninety_days(): void
    {
        $old = $this->aged(NotificationLog::class, [
            'status' => 'sent',
            'attempt' => 1,
            'dedupe_key' => 'old-log',
        ], 91);

        $recent = NotificationLog::create([
            'status' => 'sent',
            'attempt' => 1,
            'dedupe_key' => 'recent-log',
        ]);

        $this->artisan('model:prune', ['--model' => [NotificationLog::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('notification_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $recent->id]);
    }

    public function test_incidents_are_retained_for_a_year(): void
    {
        $website = $this->makeWebsite();

        $old = $this->aged(Incident::class, [
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'RESOLVED',
            'score' => 9,
            'dedupe_key' => 'old-incident',
            'detected_at' => now()->subDays(366),
        ], 366);

        // A 300-day-old incident must NOT be pruned (well within 365d).
        $recent = $this->aged(Incident::class, [
            'website_id' => $website->id,
            'type' => 'availability',
            'severity' => 'WARNING',
            'status' => 'RESOLVED',
            'score' => 0,
            'dedupe_key' => 'recent-incident',
            'detected_at' => now()->subDays(300),
        ], 300);

        $this->artisan('model:prune', ['--model' => [Incident::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('incidents', ['id' => $old->id]);
        $this->assertDatabaseHas('incidents', ['id' => $recent->id]);
    }

    public function test_expired_notification_cooldowns_are_pruned(): void
    {
        $expired = NotificationCooldown::create([
            'event_kind' => 'incident.opened',
            'cooldown_key' => '1:1',
            'window_started_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'suppressed_count' => 0,
        ]);
        $active = NotificationCooldown::create([
            'event_kind' => 'incident.opened',
            'cooldown_key' => '2:2',
            'window_started_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'suppressed_count' => 0,
        ]);

        $this->artisan('model:prune', ['--model' => [NotificationCooldown::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('notification_cooldowns', ['id' => $expired->id]);
        $this->assertDatabaseHas('notification_cooldowns', ['id' => $active->id]);
    }

    public function test_retention_honours_configured_window(): void
    {
        config(['sentinel.retention.checks_days' => 60]);
        $website = $this->makeWebsite();

        // 45 days old survives under a 60-day window.
        $survivor = $this->aged(Check::class, [
            'website_id' => $website->id,
            'check_key' => 'survivor',
            'started_at' => now()->subDays(45),
        ], 45);

        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);

        $this->assertDatabaseHas('checks', ['id' => $survivor->id]);
    }

    public function test_retention_pruning_is_scheduled_daily(): void
    {
        // FR-90: pruning is a scheduled job. `routes/console.php` registers
        // `Schedule::command('model:prune')->daily()`. Assert it is present and
        // still running on a daily cadence (not silently removed/mis-timed).
        $events = Schedule::events($this->app);

        $pruning = collect($events)->first(
            fn (Event $event): bool => str_contains((string) ($event->command ?? ''), 'model:prune')
        );

        $this->assertNotNull($pruning, 'model:prune must be registered on the scheduler (AC-19).');
        $this->assertSame('0 0 * * *', $pruning->getExpression(), 'Retention pruning must run daily (FR-90).');
    }
}
