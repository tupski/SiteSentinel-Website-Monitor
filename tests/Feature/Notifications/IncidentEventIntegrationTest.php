<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\DispatchIncidentNotifications;
use App\Jobs\SendNotification;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Website;
use App\Services\Incidents\IncidentStateMachine;
use App\Services\Notifications\CircuitBreaker;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationIntents;
use App\Services\Notifications\NotificationProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Incident-event to notification-intent wiring (NOTIFICATIONS.md §3, §4.1).
 *
 * No RefreshDatabase/DatabaseMigrations trait: after-commit intent dispatch
 * needs no outer test transaction, otherwise callbacks stay deferred and
 * commit/rollback behaviour is unobservable. setUp runs migrate:fresh for
 * isolation instead (DatabaseMigrations teardown hits dropForeign calls the
 * sqlite grammar rejects). No live network.
 */
final class IncidentEventIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    private function website(): Website
    {
        return Website::create([
            'name' => 'Example',
            'url' => 'https://public.example.test/',
            'scheme' => 'https',
            'host' => 'public.example.test',
            'is_active' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
        ]);
    }

    private function incident(Website $website, array $overrides = []): Incident
    {
        return Incident::create(array_merge([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ], $overrides));
    }

    private function emailChannel(): NotificationChannel
    {
        return NotificationChannel::create([
            'type' => 'email',
            'name' => 'Ops mail',
            'enabled' => true,
            'config' => [
                'recipients' => ['ops@example.test'],
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'from_address' => 'alerts@example.test',
                'from_name' => 'SiteSentinel',
                'min_severity' => 'WARNING',
            ],
            'secret_ref' => 'smtp-secret-value',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_opened_triggers_intent(): void
    {
        Bus::fake();
        $incident = $this->incident($this->website());

        NotificationIntents::enqueue($incident->id, NotificationDispatcher::EVENT_OPENED);

        Bus::assertDispatched(DispatchIncidentNotifications::class, fn ($job) => $job->incidentId === $incident->id && $job->eventKind === NotificationDispatcher::EVENT_OPENED);
    }

    public function test_escalated_triggers_intent(): void
    {
        Bus::fake();
        $incident = $this->incident($this->website(), ['severity' => 'CRITICAL']);

        NotificationIntents::enqueue($incident->id, NotificationDispatcher::EVENT_ESCALATED);

        Bus::assertDispatched(DispatchIncidentNotifications::class, fn ($job) => $job->incidentId === $incident->id && $job->eventKind === NotificationDispatcher::EVENT_ESCALATED);
    }

    public function test_resolved_manual_and_auto_trigger_intent(): void
    {
        Bus::fake();
        $website = $this->website();
        $machine = app(IncidentStateMachine::class);
        $admin = $this->admin();

        $manual = $this->incident($website);
        $machine->resolveManually($manual, $admin, 'Mitigated upstream.');
        NotificationIntents::enqueue($manual->id, NotificationDispatcher::EVENT_RESOLVED, $admin->id);

        $auto = $this->incident($website, ['dedupe_key' => 'security:website:'.$website->id.':auto']);
        $machine->resolveAutomatically($auto, 'Sustained recovery.');
        NotificationIntents::enqueue($auto->id, NotificationDispatcher::EVENT_RESOLVED);

        Bus::assertDispatched(DispatchIncidentNotifications::class, fn ($job) => $job->incidentId === $manual->id && $job->eventKind === NotificationDispatcher::EVENT_RESOLVED);
        Bus::assertDispatched(DispatchIncidentNotifications::class, fn ($job) => $job->incidentId === $auto->id && $job->eventKind === NotificationDispatcher::EVENT_RESOLVED);
        $this->assertSame('manual', $manual->refresh()->resolution_mode);
        $this->assertSame('auto', $auto->refresh()->resolution_mode);
    }

    public function test_acknowledged_triggers_intent_per_policy(): void
    {
        Bus::fake();
        $incident = $this->incident($this->website());
        $this->actingAs($this->admin());

        $this->post(route('admin.incidents.acknowledge', $incident))->assertRedirect();

        $this->assertSame('ACKNOWLEDGED', $incident->refresh()->status);
        Bus::assertDispatched(DispatchIncidentNotifications::class, fn ($job) => $job->incidentId === $incident->id && $job->eventKind === NotificationDispatcher::EVENT_ACKNOWLEDGED);
    }

    public function test_repeated_detections_no_duplicate_beyond_cooldown(): void
    {
        $this->emailChannel();
        $incident = $this->incident($this->website());
        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_OPENED);
        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_OPENED);

        $sent = NotificationLog::query()->where('incident_id', $incident->id)->where('status', 'sent')->count();
        $suppressed = NotificationLog::query()->where('incident_id', $incident->id)->where('status', 'suppressed')->count();
        $this->assertSame(1, $sent, 'repeat detection must not re-notify (FR-68)');
        $this->assertSame(1, $suppressed);
    }

    public function test_dispatch_only_after_commit_rollback_sends_no_job(): void
    {
        Bus::fake();
        $incident = $this->incident($this->website());

        try {
            DB::transaction(function () use ($incident): void {
                NotificationIntents::enqueue($incident->id, NotificationDispatcher::EVENT_OPENED);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // Expected rollback path.
        }

        Bus::assertNotDispatched(DispatchIncidentNotifications::class);

        DB::transaction(function () use ($incident): void {
            NotificationIntents::enqueue($incident->id, NotificationDispatcher::EVENT_ESCALATED);
        });

        Bus::assertDispatched(DispatchIncidentNotifications::class, fn ($job) => $job->eventKind === NotificationDispatcher::EVENT_ESCALATED);
    }

    public function test_replayed_event_is_idempotent(): void
    {
        $channel = $this->emailChannel();
        $incident = $this->incident($this->website());
        $dedupe = NotificationDispatcher::dedupeKey($incident->id, NotificationDispatcher::EVENT_OPENED, (int) $channel->id);

        $first = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $first->withFakeQueueInteractions();
        $first->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $replay = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $replay->withFakeQueueInteractions();
        $replay->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $this->assertSame(1, NotificationLog::query()->where('dedupe_key', $dedupe)->where('status', 'sent')->count());
        $replay->assertNotFailed();
        $replay->assertNotReleased();
    }
}
