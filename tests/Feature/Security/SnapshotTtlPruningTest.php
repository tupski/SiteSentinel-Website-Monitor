<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Incident;
use App\Models\Snapshot;
use Illuminate\Support\Carbon;

/**
 * Snapshot retention TTL contract (Phase 10, AC-19 / PRD §16.3 / DATABASE.md §4).
 *
 * DATABASE.md §4 pins snapshot retention as "14d (also honors `expires_at`)";
 * `SnapshotWriter` stamps `expires_at = captured_at + retention.snapshots_days`.
 * These tests prove the model's `prunable()` actually honours that declared
 * `expires_at` (rather than silently ignoring it and ageing by `created_at`
 * only), keeps NULL-`expires_at` rows from becoming immortal, and preserves
 * evidence still referenced by an OPEN incident until it is resolved (FR-95).
 */
final class SnapshotTtlPruningTest extends SecurityTestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-06-15 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Create a snapshot and force its `created_at` (not mass-assignable).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function snapshot(array $attributes, int $createdDaysAgo = 0): Snapshot
    {
        /** @var Snapshot $snapshot */
        $snapshot = new Snapshot($attributes);
        $when = $this->now->copy()->subDays($createdDaysAgo);
        $snapshot->created_at = $when;
        $snapshot->updated_at = $when;
        $snapshot->save();

        return $snapshot;
    }

    public function test_snapshot_with_past_expires_at_is_pruned_even_when_created_recently(): void
    {
        $website = $this->makeWebsite();

        // created_at is only 1 day ago, but the declared TTL expired yesterday.
        // Honouring `expires_at` must prune it regardless of `created_at`.
        $expired = $this->snapshot([
            'website_id' => $website->id,
            'captured_at' => $this->now->copy()->subDay(),
            'expires_at' => $this->now->copy()->subSecond(),
        ], createdDaysAgo: 1);

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('snapshots', ['id' => $expired->id]);
    }

    public function test_snapshot_with_future_expires_at_survives_despite_old_created_at(): void
    {
        $website = $this->makeWebsite();

        // created_at is 30 days old (well past the 14d default) but the declared
        // TTL still has a day to run: `expires_at` governs and the row survives.
        $stillFresh = $this->snapshot([
            'website_id' => $website->id,
            'captured_at' => $this->now->copy()->subDays(30),
            'expires_at' => $this->now->copy()->addDay(),
        ], createdDaysAgo: 30);

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        $this->assertDatabaseHas('snapshots', ['id' => $stillFresh->id]);
    }

    public function test_snapshot_with_null_expires_at_falls_back_to_created_at_age(): void
    {
        $website = $this->makeWebsite();

        $old = $this->snapshot([
            'website_id' => $website->id,
            'captured_at' => $this->now->copy()->subDays(15),
            'expires_at' => null,
        ], createdDaysAgo: 15);

        $recent = $this->snapshot([
            'website_id' => $website->id,
            'captured_at' => $this->now->copy(),
            'expires_at' => null,
        ]);

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('snapshots', ['id' => $old->id]);
        // A NULL-TTL recent row must not be treated as expired.
        $this->assertDatabaseHas('snapshots', ['id' => $recent->id]);
    }

    public function test_open_incident_evidence_is_preserved_until_the_incident_is_resolved(): void
    {
        $website = $this->makeWebsite();

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'CRITICAL',
            'status' => 'DETECTED',
            'score' => 20,
            'dedupe_key' => 'open-incident',
            'detected_at' => $this->now->copy()->subDay(),
        ]);

        // The snapshot's own 14d TTL has expired, but its incident is still open.
        $snapshot = $this->snapshot([
            'website_id' => $website->id,
            'incident_id' => $incident->id,
            'captured_at' => $this->now->copy()->subDays(20),
            'expires_at' => $this->now->copy()->subDay(),
        ], createdDaysAgo: 20);

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        $this->assertDatabaseHas('snapshots', ['id' => $snapshot->id]);

        // Resolve the incident, then prune again: the evidence is now releasable.
        $incident->update([
            'status' => 'RESOLVED',
            'resolved_at' => $this->now->copy(),
            'resolution_mode' => 'manual',
        ]);

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('snapshots', ['id' => $snapshot->id]);
    }

    public function test_settled_incident_evidence_expires_with_its_declared_ttl(): void
    {
        $website = $this->makeWebsite();

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'RESOLVED',
            'score' => 9,
            'dedupe_key' => 'resolved-incident',
            'detected_at' => $this->now->copy()->subDays(20),
            'resolved_at' => $this->now->copy()->subDays(19),
            'resolution_mode' => 'auto',
        ]);

        $snapshot = $this->snapshot([
            'website_id' => $website->id,
            'incident_id' => $incident->id,
            'captured_at' => $this->now->copy()->subDays(20),
            'expires_at' => $this->now->copy()->subDay(),
        ], createdDaysAgo: 20);

        $this->artisan('model:prune', ['--model' => [Snapshot::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('snapshots', ['id' => $snapshot->id]);
    }
}
