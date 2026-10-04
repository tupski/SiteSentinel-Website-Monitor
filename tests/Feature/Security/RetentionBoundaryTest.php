<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\NotificationCooldown;
use App\Models\NotificationLog;
use App\Models\Snapshot;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Retention pruning BOUNDARY + relationship regression suite (Phase 10, AC-19).
 *
 * The companion `RetentionPruningTest` proves rows *past* the window are pruned
 * and rows *inside* it survive. This suite pins the exact boundary semantics
 * (before / at / after) with a frozen clock, and proves the relationship
 * behaviour that a bulk delete must not corrupt:
 *
 *  - FK cascades (`checks` → `check_extractions`, `incidents` → `incident_events`);
 *  - `nullOnDelete` FKs (`snapshots.incident_id`, `notification_logs.incident_id`);
 *  - `Snapshot::pruning()` deletes the on-disk HTML artefact with the row;
 *  - pruning is idempotent (safe to re-run);
 *  - pruning is batched, not `->get()->each()` (memory-safe on large datasets);
 *  - `incidents` remain append-only history: a RESOLVED incident is retained
 *    inside the 365d window even though its snapshots have been pruned.
 *
 * Companion `SnapshotTtlPruningTest` covers the documented snapshot
 * `expires_at` contract.
 */
final class RetentionBoundaryTest extends SecurityTestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen, deterministic clock: no test may depend on wall-clock drift.
        $this->now = Carbon::parse('2026-06-15 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Insert a row whose `created_at` is exactly `$days` before the frozen now.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    private function aged(string $model, array $attributes, int $days): Model
    {
        /** @var Model $row */
        $row = new $model($attributes);
        $row->created_at = $this->now->copy()->subDays($days);

        $updatedColumn = $row->getUpdatedAtColumn();
        if ($updatedColumn !== null) {
            $row->{$updatedColumn} = $this->now->copy()->subDays($days);
        }

        $row->save();

        return $row;
    }

    // ---------------------------------------------------------------------
    // Boundary semantics (before / at / after), per retained data type.
    // ---------------------------------------------------------------------

    public function test_checks_boundary_is_exact(): void
    {
        $website = $this->makeWebsite();

        // 31 days old → outside the 30d window → pruned.
        $before = $this->aged(Check::class, [
            'website_id' => $website->id,
            'check_key' => 'boundary-before',
            'started_at' => $this->now->copy()->subDays(31),
        ], 31);

        // Exactly 30 days old → the `<=` boundary → pruned.
        $at = $this->aged(Check::class, [
            'website_id' => $website->id,
            'check_key' => 'boundary-at',
            'started_at' => $this->now->copy()->subDays(30),
        ], 30);

        // One second inside the window → preserved.
        /** @var Check $inside */
        $inside = new Check([
            'website_id' => $website->id,
            'check_key' => 'boundary-inside',
            'started_at' => $this->now->copy()->subDays(30)->addSecond(),
        ]);
        $inside->created_at = $this->now->copy()->subDays(30)->addSecond();
        $inside->save();

        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('checks', ['id' => $before->id]);
        $this->assertDatabaseMissing('checks', ['id' => $at->id]);
        $this->assertDatabaseHas('checks', ['id' => $inside->id]);
    }

    public function test_snapshots_boundary_is_exact(): void
    {
        $website = $this->makeWebsite();

        $before = $this->aged(Snapshot::class, [
            'website_id' => $website->id,
            'captured_at' => $this->now->copy()->subDays(15),
        ], 15);

        $at = $this->aged(Snapshot::class, [
            'website_id' => $website->id,
            'captured_at' => $this->now->copy()->subDays(14),
        ], 14);

        /** @var Snapshot $inside */
        $inside = new Snapshot([
            'website_id' => $website->id,
            'captured_at' => $this->now->copy()->subDays(14)->addSecond(),
        ]);
        $inside->created_at = $this->now->copy()->subDays(14)->addSecond();
        $inside->updated_at = $inside->created_at;
        $inside->save();

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('snapshots', ['id' => $before->id]);
        $this->assertDatabaseMissing('snapshots', ['id' => $at->id]);
        $this->assertDatabaseHas('snapshots', ['id' => $inside->id]);
    }

    public function test_notification_logs_boundary_is_exact(): void
    {
        $before = $this->aged(NotificationLog::class, [
            'status' => 'sent',
            'attempt' => 1,
            'dedupe_key' => 'log-before',
        ], 91);

        $at = $this->aged(NotificationLog::class, [
            'status' => 'sent',
            'attempt' => 1,
            'dedupe_key' => 'log-at',
        ], 90);

        /** @var NotificationLog $inside */
        $inside = new NotificationLog([
            'status' => 'sent',
            'attempt' => 1,
            'dedupe_key' => 'log-inside',
        ]);
        $inside->created_at = $this->now->copy()->subDays(90)->addSecond();
        $inside->save();

        $this->artisan('model:prune', ['--model' => [NotificationLog::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('notification_logs', ['id' => $before->id]);
        $this->assertDatabaseMissing('notification_logs', ['id' => $at->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $inside->id]);
    }

    public function test_incidents_boundary_is_exact(): void
    {
        $website = $this->makeWebsite();

        $before = $this->aged(Incident::class, [
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'RESOLVED',
            'score' => 9,
            'dedupe_key' => 'incident-before',
            'detected_at' => $this->now->copy()->subDays(366),
        ], 366);

        $at = $this->aged(Incident::class, [
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'RESOLVED',
            'score' => 9,
            'dedupe_key' => 'incident-at',
            'detected_at' => $this->now->copy()->subDays(365),
        ], 365);

        /** @var Incident $inside */
        $inside = new Incident([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'RESOLVED',
            'score' => 9,
            'dedupe_key' => 'incident-inside',
            'detected_at' => $this->now->copy()->subDays(365)->addSecond(),
        ]);
        $inside->created_at = $this->now->copy()->subDays(365)->addSecond();
        $inside->updated_at = $inside->created_at;
        $inside->save();

        $this->artisan('model:prune', ['--model' => [Incident::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('incidents', ['id' => $before->id]);
        // An incident exactly at the 365d boundary must be pruned; one second
        // inside the window must be preserved.
        $this->assertDatabaseMissing('incidents', ['id' => $at->id]);
        $this->assertDatabaseHas('incidents', ['id' => $inside->id]);
    }

    public function test_notification_cooldown_boundary_is_exact(): void
    {
        $expiredLastSecond = NotificationCooldown::create([
            'event_kind' => 'incident.opened',
            'cooldown_key' => 'cd-before',
            'window_started_at' => $this->now->copy()->subHours(2),
            'expires_at' => $this->now->copy()->subSecond(),
            'suppressed_count' => 0,
        ]);

        // Exactly at expiry → `<=` boundary → pruned.
        $atExpiry = NotificationCooldown::create([
            'event_kind' => 'incident.opened',
            'cooldown_key' => 'cd-at',
            'window_started_at' => $this->now->copy()->subHours(2),
            'expires_at' => $this->now->copy(),
            'suppressed_count' => 0,
        ]);

        // One second before expiry → still active → preserved.
        $active = NotificationCooldown::create([
            'event_kind' => 'incident.opened',
            'cooldown_key' => 'cd-active',
            'window_started_at' => $this->now->copy()->subHours(2),
            'expires_at' => $this->now->copy()->addSecond(),
            'suppressed_count' => 0,
        ]);

        $this->artisan('model:prune', ['--model' => [NotificationCooldown::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('notification_cooldowns', ['id' => $expiredLastSecond->id]);
        $this->assertDatabaseMissing('notification_cooldowns', ['id' => $atExpiry->id]);
        // An unexpired cooldown must be preserved.
        $this->assertDatabaseHas('notification_cooldowns', ['id' => $active->id]);
    }

    // ---------------------------------------------------------------------
    // Relationship / FK behaviour under pruning.
    // ---------------------------------------------------------------------

    public function test_pruning_checks_cascades_check_extractions(): void
    {
        $website = $this->makeWebsite();

        $oldCheck = $this->aged(Check::class, [
            'website_id' => $website->id,
            'check_key' => 'cascade-old',
            'started_at' => $this->now->copy()->subDays(31),
        ], 31);

        $extraction = CheckExtraction::create([
            'check_id' => $oldCheck->id,
            'website_id' => $website->id,
            'keywords' => ['a' => 1],
        ]);

        $keptCheck = Check::create([
            'website_id' => $website->id,
            'check_key' => 'cascade-kept',
            'started_at' => $this->now->copy(),
        ]);
        $keptExtraction = CheckExtraction::create([
            'check_id' => $keptCheck->id,
            'website_id' => $website->id,
            'keywords' => ['b' => 1],
        ]);

        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('checks', ['id' => $oldCheck->id]);
        $this->assertDatabaseMissing('check_extractions', ['id' => $extraction->id]);
        $this->assertDatabaseHas('check_extractions', ['id' => $keptExtraction->id]);
    }

    public function test_pruning_incidents_cascades_incident_events(): void
    {
        $website = $this->makeWebsite();

        $oldIncident = $this->aged(Incident::class, [
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'RESOLVED',
            'score' => 9,
            'dedupe_key' => 'cascade-incident',
            'detected_at' => $this->now->copy()->subDays(366),
        ], 366);

        $event = IncidentEvent::create([
            'incident_id' => $oldIncident->id,
            'event_type' => 'opened',
            'to_status' => 'DETECTED',
            'created_at' => $this->now->copy()->subDays(366),
        ]);

        $keptIncident = $this->aged(Incident::class, [
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'RESOLVED',
            'score' => 9,
            'dedupe_key' => 'cascade-incident-kept',
            'detected_at' => $this->now->copy()->subDays(300),
        ], 300);

        $keptEvent = IncidentEvent::create([
            'incident_id' => $keptIncident->id,
            'event_type' => 'opened',
            'to_status' => 'DETECTED',
            'created_at' => $this->now->copy()->subDays(300),
        ]);

        $this->artisan('model:prune', ['--model' => [Incident::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('incidents', ['id' => $oldIncident->id]);
        $this->assertDatabaseMissing('incident_events', ['id' => $event->id]);
        $this->assertDatabaseHas('incident_events', ['id' => $keptEvent->id]);
    }

    public function test_pruning_incidents_nullifies_snapshot_and_log_incident_fk(): void
    {
        $website = $this->makeWebsite();

        $oldIncident = $this->aged(Incident::class, [
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'RESOLVED',
            'score' => 9,
            'dedupe_key' => 'fk-incident',
            'detected_at' => $this->now->copy()->subDays(366),
        ], 366);

        // A snapshot that is INSIDE its own 14d window but references an incident
        // older than 365d: pruning the incident must null the FK, never delete
        // the snapshot (the snapshot window is governed independently).
        $snapshot = Snapshot::create([
            'website_id' => $website->id,
            'incident_id' => $oldIncident->id,
            'captured_at' => $this->now->copy()->subDay(),
            'created_at' => $this->now->copy()->subDay(),
        ]);

        $log = NotificationLog::create([
            'incident_id' => $oldIncident->id,
            'status' => 'sent',
            'attempt' => 1,
            'dedupe_key' => 'fk-log',
            'created_at' => $this->now->copy()->subDay(),
        ]);

        $this->artisan('model:prune', ['--model' => [Incident::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('incidents', ['id' => $oldIncident->id]);
        // Pruning an old incident must null the FK (ON DELETE SET NULL), never
        // delete the referencing snapshot/log row.
        $this->assertDatabaseHas('snapshots', [
            'id' => $snapshot->id,
            'incident_id' => null,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'id' => $log->id,
            'incident_id' => null,
        ]);
    }

    public function test_pruning_snapshot_deletes_on_disk_html_artefact(): void
    {
        Storage::fake('local');
        $website = $this->makeWebsite();

        $path = "snapshots/{$website->id}/old.html";
        Storage::disk('local')->put($path, '<html>evidence</html>');

        $old = $this->aged(Snapshot::class, [
            'website_id' => $website->id,
            'html_path' => $path,
            'captured_at' => $this->now->copy()->subDays(15),
        ], 15);

        $keptPath = "snapshots/{$website->id}/kept.html";
        Storage::disk('local')->put($keptPath, '<html>fresh</html>');
        $kept = Snapshot::create([
            'website_id' => $website->id,
            'html_path' => $keptPath,
            'captured_at' => $this->now->copy(),
        ]);

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('snapshots', ['id' => $old->id]);
        Storage::disk('local')->assertMissing($path);

        $this->assertDatabaseHas('snapshots', ['id' => $kept->id]);
        Storage::disk('local')->assertExists($keptPath);
    }

    public function test_resolved_incident_outlives_its_snapshots_without_corruption(): void
    {
        Storage::fake('local');
        $website = $this->makeWebsite();

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'CRITICAL',
            'status' => 'RESOLVED',
            'score' => 20,
            'dedupe_key' => 'long-lived-incident',
            'detected_at' => $this->now->copy()->subDays(30),
            'resolved_at' => $this->now->copy()->subDays(29),
            'resolution_mode' => 'auto',
            'triggered_rules' => [['id' => 'RULE-RED-001', 'weight' => 20]],
            'message' => 'Redirect hijack detected',
        ]);

        $path = "snapshots/{$website->id}/evidence.html";
        Storage::disk('local')->put($path, '<html>evidence</html>');
        $snapshot = $this->aged(Snapshot::class, [
            'website_id' => $website->id,
            'incident_id' => $incident->id,
            'html_path' => $path,
            'captured_at' => $this->now->copy()->subDays(20),
        ], 20);

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        // Evidence is pruned at 14d ...
        $this->assertDatabaseMissing('snapshots', ['id' => $snapshot->id]);
        Storage::disk('local')->assertMissing($path);

        // ... but the incident (append-only history, 365d) survives intact and its
        // small rule-attribution summary remains independently readable (PRD §16.3).
        $fresh = Incident::query()->findOrFail($incident->id);
        $this->assertSame('RESOLVED', $fresh->status);
        $this->assertSame([['id' => 'RULE-RED-001', 'weight' => 20]], $fresh->triggered_rules);
    }

    // ---------------------------------------------------------------------
    // Idempotency + memory-safety (contract, not implementation bake-in).
    // ---------------------------------------------------------------------

    public function test_pruning_is_idempotent_and_rerunnable(): void
    {
        $website = $this->makeWebsite();

        $this->aged(Check::class, [
            'website_id' => $website->id,
            'check_key' => 'idempotent-old',
            'started_at' => $this->now->copy()->subDays(45),
        ], 45);
        $kept = Check::create([
            'website_id' => $website->id,
            'check_key' => 'idempotent-kept',
            'started_at' => $this->now->copy(),
        ]);

        // Run twice; the second run must be a harmless no-op (not an error) and
        // must not touch in-window rows.
        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);
        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);

        $this->assertDatabaseHas('checks', ['id' => $kept->id]);
        $this->assertSame(0, Check::query()->where('check_key', 'idempotent-old')->count());
    }

    public function test_prune_query_stays_bounded_and_does_not_hydrate_all_rows(): void
    {
        // High-volume telemetry uses MassPrunable: `model:prune` issues a bulk
        // `DELETE` per 1000-row batch instead of hydrating models, so a large
        // table is never loaded into memory (DATABASE.md §4).
        $this->assertArrayHasKey(
            MassPrunable::class,
            class_uses_recursive(Check::class),
            'Checks must use MassPrunable (bulk delete), never ->get()->each().'
        );

        // The prunable query itself must carry an age predicate (bounded result
        // set), so a large table cannot be returned wholesale.
        $sql = (new Check)->prunable()->toSql();
        $this->assertStringContainsString('created_at', $sql);
        $this->assertStringContainsString('<=', $sql);
    }
}
