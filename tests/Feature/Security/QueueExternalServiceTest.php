<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Jobs\DispatchIncidentNotifications;
use App\Jobs\RunWebsiteCheck;
use App\Jobs\SendNotification;
use App\Models\Incident;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use App\Services\Incidents\IncidentEngine;
use App\Services\Monitor\Probe;
use App\Services\Notifications\CircuitBreaker;
use App\Services\Notifications\NotificationProviderRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * Queue / external-service regression suite (SECURITY.md §7, NOTIFICATIONS §12).
 *
 * Asserts that job payloads never carry secrets, that retries are bounded, and
 * that a provider failure is isolated from the monitoring job.
 */
final class QueueExternalServiceTest extends SecurityTestCase
{
    public function test_send_notification_payload_carries_only_ids_and_event_kind(): void
    {
        $job = new SendNotification(incidentId: 7, channelId: 3, eventKind: 'incident.opened', actorId: 1);

        $serialized = serialize($job);

        // No secret material may be embedded in the queued payload.
        $this->assertStringNotContainsString('secret_ref', $serialized);
        $this->assertStringNotContainsString('bot_token', $serialized);
        $this->assertStringNotContainsString('password', $serialized);
    }

    public function test_dispatch_job_payload_carries_only_ids_and_event_kind(): void
    {
        $job = new DispatchIncidentNotifications(incidentId: 7, eventKind: 'incident.opened', actorId: 1);
        $serialized = serialize($job);

        $this->assertStringNotContainsString('secret', $serialized);
        $this->assertStringNotContainsString('token', $serialized);
    }

    public function test_notification_retries_are_bounded(): void
    {
        $job = new SendNotification(1, 1, 'incident.opened');

        $this->assertSame(3, $job->tries);
        // retryUntil bounds total retry lifetime to 2 hours.
        $this->assertLessThanOrEqual(7200, (int) ($job->retryUntil()->getTimestamp() - now()->getTimestamp()));
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_provider_failure_does_not_fail_the_monitoring_job(): void
    {
        // A SendNotification for a missing incident/channel is a no-op, never
        // a thrown failure that would fail the queue job.
        $job = new SendNotification(incidentId: 999999, channelId: 999999, eventKind: 'incident.opened');

        $job->handle(
            app(NotificationProviderRegistry::class),
            app(CircuitBreaker::class),
        );

        $this->assertTrue(true, 'missing incident/channel must be a safe no-op');
    }

    public function test_per_website_check_lock_prevents_overlap(): void
    {
        $website = $this->makeWebsite();

        // Simulate a lock already held by another worker.
        $lock = Cache::lock('website-check:'.$website->id, 120);
        $this->assertTrue($lock->get());

        $job = new RunWebsiteCheck($website);
        // handle() acquires the same lock; with it held, the job returns early
        // without probing (no Http::fake needed because no request is made).
        $job->handle(
            app(Probe::class),
            app(RuleEngine::class),
            app(SnapshotWriter::class),
            app(IncidentEngine::class),
        );

        $this->assertDatabaseCount('checks', 0);
        $lock->release();
    }

    public function test_unknown_channel_type_fails_closed(): void
    {
        $registry = app(NotificationProviderRegistry::class);

        // The registry must not resolve an unknown provider type (fail closed).
        $this->assertFalse(NotificationProviderRegistry::known('unknown_type'));

        $this->expectException(\InvalidArgumentException::class);
        $registry->resolve('unknown_type');
    }
}
