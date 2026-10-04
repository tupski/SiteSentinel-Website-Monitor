<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Check;
use App\Models\Incident;
use App\Models\NotificationLog;
use App\Models\Snapshot;
use App\Services\Audit\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY.md §9.1 `retention.pruned` audit regression (PLAN.md Phase 10).
 *
 * The retention pruner (`model:prune`) must emit exactly one `retention.pruned`
 * audit row per run, carrying the per-model pruned counts in `metadata`, and
 * must never leak a secret or monitored content into that row.
 */
final class RetentionAuditTest extends SecurityTestCase
{
    /**
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

    public function test_pruning_run_emits_retention_pruned_audit_event_with_counts(): void
    {
        $website = $this->makeWebsite();

        $this->aged(Check::class, [
            'website_id' => $website->id,
            'check_key' => 'aged-check-1',
            'started_at' => now()->subDays(31),
        ], 31);
        $this->aged(Check::class, [
            'website_id' => $website->id,
            'check_key' => 'aged-check-2',
            'started_at' => now()->subDays(40),
        ], 40);

        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditEvent::RETENTION_PRUNED,
        ]);

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->where('event', AuditEvent::RETENTION_PRUNED)->firstOrFail();

        $this->assertSame('artisan:model:prune', $entry->user_agent);
        $this->assertNull($entry->user_id);
        $this->assertIsArray($entry->metadata);
        $this->assertSame(2, $entry->metadata['total']);
        $this->assertSame(2, $entry->metadata['models']['Check']);
    }

    public function test_pruning_audit_is_emitted_even_when_nothing_matches(): void
    {
        $this->makeWebsite();

        // No aged rows: the run still completes and still records the event
        // (an operator must be able to see that the pruner ran).
        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditEvent::RETENTION_PRUNED,
        ]);

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->where('event', AuditEvent::RETENTION_PRUNED)->firstOrFail();
        $this->assertSame(0, $entry->metadata['total']);
    }

    public function test_retention_audit_never_leaks_secrets_or_monitored_content(): void
    {
        $website = $this->makeWebsite([
            'name' => 'Leaky Fixture',
            'url' => 'https://leaky.example.test/secret-path',
        ]);

        // A snapshot carrying monitored HTML evidence + a notification log with
        // a redacted-looking secret must never appear in the audit metadata.
        $this->aged(Snapshot::class, [
            'website_id' => $website->id,
            'html_path' => 'snapshots/secret-evidence.html',
            'captured_at' => now()->subDays(20),
            'expires_at' => now()->subDays(1),
        ], 20);

        $this->aged(NotificationLog::class, [
            'incident_id' => null,
            'channel_id' => null,
            'status' => 'failed',
            'attempt' => 1,
            'error' => 'smtp-password-abcdef',
            'dedupe_key' => 'leaky-key',
        ], 120);

        $this->artisan('model:prune', ['--model' => [Snapshot::class, NotificationLog::class]])
            ->assertExitCode(0);

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->where('event', AuditEvent::RETENTION_PRUNED)->firstOrFail();
        $encoded = json_encode($entry->metadata);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('secret-path', $encoded);
        $this->assertStringNotContainsString('secret-evidence.html', $encoded);
        $this->assertStringNotContainsString('smtp-password-abcdef', $encoded);
        $this->assertStringNotContainsString($website->url, (string) $entry->user_agent);
    }

    public function test_audit_failure_never_breaks_pruning(): void
    {
        $website = $this->makeWebsite();

        $old = $this->aged(Check::class, [
            'website_id' => $website->id,
            'check_key' => 'aged-check',
            'started_at' => now()->subDays(45),
        ], 45);

        // Break the audit table to simulate an audit-write failure.
        Schema::drop('audit_logs');

        // Pruning still succeeds: the audit write is best-effort (AGENTS.md §12).
        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);
        $this->assertDatabaseMissing('checks', ['id' => $old->id]);
    }

    public function test_incidents_are_not_pruned_inside_the_retention_window(): void
    {
        $website = $this->makeWebsite();

        $recentIncident = Incident::create([
            'website_id' => $website->id,
            'type' => 'availability',
            'status' => 'RESOLVED',
            'severity' => 'WARNING',
            'dedupe_key' => 'recent-incident',
            'detected_at' => now()->subDays(2),
        ]);

        $this->artisan('model:prune', ['--model' => [Incident::class]])->assertExitCode(0);

        $this->assertDatabaseHas('incidents', ['id' => $recentIncident->id]);
    }
}
