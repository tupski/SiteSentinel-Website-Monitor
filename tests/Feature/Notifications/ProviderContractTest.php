<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Contracts\DeliveryResult;
use App\Contracts\NotificationPayload;
use App\Contracts\NotificationProvider;
use App\Jobs\SendNotification;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Website;
use App\Services\Notifications\Channels\EmailProvider;
use App\Services\Notifications\Channels\TelegramProvider;
use App\Services\Notifications\Channels\WebPushProvider;
use App\Services\Notifications\CircuitBreaker;
use App\Services\Notifications\MessageRedactor;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Provider contract boundary (NOTIFICATIONS.md §2.3, §2.5, ADR-010).
 *
 * Behavior-level: registry resolution, ok/retryable/permanent routing,
 * unknown-type fail-closed, bounded retries. No live network or creds.
 */
final class ProviderContractTest extends TestCase
{
    use RefreshDatabase;

    private int $telegramStatus = 200;

    /** @var array<string, mixed> */
    private array $telegramBody = ['ok' => true, 'result' => ['message_id' => 1]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        // Single stateful fake: re-registering Http::fake merges stubs and the
        // first match wins, so per-test behaviour is driven by these flags.
        Http::fake([
            'https://api.telegram.org/*' => fn () => Http::response($this->telegramBody, $this->telegramStatus),
        ]);
    }

    private function website(string $host = 'public.example.test'): Website
    {
        return Website::create([
            'name' => 'Example',
            'url' => 'https://'.$host.'/',
            'scheme' => 'https',
            'host' => $host,
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

    private function emailChannel(array $overrides = []): NotificationChannel
    {
        return NotificationChannel::create(array_merge([
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
        ], $overrides));
    }

    /** Registry resolves every provider type via container. */
    public function test_fake_provider_registration_resolves(): void
    {
        $registry = app(NotificationProviderRegistry::class);

        $this->assertInstanceOf(EmailProvider::class, $registry->resolve('email'));
        $this->assertInstanceOf(TelegramProvider::class, $registry->resolve('telegram'));
        $this->assertInstanceOf(WebPushProvider::class, $registry->resolve('browser_push'));
        $this->assertTrue(NotificationProviderRegistry::known('email'));
        $this->assertTrue(NotificationProviderRegistry::known('telegram'));
        $this->assertTrue(NotificationProviderRegistry::known('browser_push'));
        $this->assertFalse(NotificationProviderRegistry::known('smoke-signal'));
        $this->assertTrue($registry->resolve('email') instanceof NotificationProvider);
        $this->assertTrue($registry->resolve('email')->supports(NotificationDispatcher::EVENT_OPENED));
        $this->assertTrue($registry->resolve('browser_push')->supports(NotificationDispatcher::EVENT_OPENED));
    }

    /** Ok result logged sent. */
    public function test_ok_result_logged_sent(): void
    {
        $website = $this->website();
        $incident = $this->incident($website);
        $channel = $this->emailChannel();

        $job = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $job->withFakeQueueInteractions();
        $job->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $log = NotificationLog::query()
            ->where('dedupe_key', NotificationDispatcher::dedupeKey($incident->id, NotificationDispatcher::EVENT_OPENED, (int) $channel->id))
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->sent_at);
        $job->assertNotFailed();
        $job->assertNotReleased();
    }

    /** Retryable failure logged failed + released bounded. */
    public function test_retryable_failure_logged_failed_and_retried_bounded(): void
    {
        $this->telegramStatus = 500;
        $this->telegramBody = ['ok' => false, 'description' => 'Internal error'];

        $website = $this->website();
        $incident = $this->incident($website);
        $channel = NotificationChannel::create([
            'type' => 'telegram',
            'name' => 'Ops tg',
            'enabled' => true,
            'config' => ['chat_id' => '123456', 'min_severity' => 'WARNING'],
            'secret_ref' => 'fake-bot-token-value',
        ]);

        $job = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $job->withFakeQueueInteractions();
        $job->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $this->assertSame(3, $job->tries, 'retries bounded (NOTIFICATIONS §12.1)');
        $this->assertNotEmpty($job->backoff());
        $job->assertReleased();
        $job->assertNotFailed();

        $log = NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'failed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('telegram_500', (string) $log->error);
    }

    /** Permanent failure dead-letters (fail, no release). */
    public function test_permanent_failure_dead_letters(): void
    {
        $this->telegramStatus = 401;
        $this->telegramBody = ['ok' => false, 'description' => 'Unauthorized'];

        $website = $this->website();
        $incident = $this->incident($website);
        $channel = NotificationChannel::create([
            'type' => 'telegram',
            'name' => 'Ops tg',
            'enabled' => true,
            'config' => ['chat_id' => '123456', 'min_severity' => 'WARNING'],
            'secret_ref' => 'fake-bot-token-value',
        ]);

        $job = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $job->withFakeQueueInteractions();

        try {
            $job->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));
            $this->fail('permanent failure must throw so the worker dead-letters to failed_jobs');
        } catch (RuntimeException) {
            // Expected dead-letter path.
        }

        $job->assertFailed();
        $job->assertNotReleased();

        $log = NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'failed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('telegram_401', (string) $log->error);
    }

    /**
     * Unknown type fails closed with failed row, no exception.
     *
     * The DB enum only persists email/telegram, so the legacy-unknown path
     * is exercised with an in-memory type override: the registry rejects the
     * type and the dispatcher records a failed row instead of dispatching.
     */
    public function test_unknown_type_fails_closed(): void
    {
        $website = $this->website();
        $incident = $this->incident($website);
        $channel = NotificationChannel::create([
            'type' => 'email',
            'name' => 'Legacy unknown',
            'enabled' => true,
            'config' => ['min_severity' => 'WARNING'],
            'secret_ref' => null,
        ]);
        $channel->type = 'pigeon';

        $this->assertFalse(NotificationProviderRegistry::known('pigeon'));

        try {
            app(NotificationProviderRegistry::class)->resolve('pigeon');
            $this->fail('registry must reject unknown channel types');
        } catch (InvalidArgumentException) {
            // Expected fail-closed boundary.
        }

        $method = new ReflectionMethod(NotificationDispatcher::class, 'dispatchToChannel');
        $method->setAccessible(true);
        $method->invoke(app(NotificationDispatcher::class), $incident, $website, $channel, NotificationDispatcher::EVENT_OPENED, null);

        $log = NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'failed')->first();
        $this->assertNotNull($log, 'unknown type must log failed, never silently ignore');
        $this->assertStringContainsString('unknown channel type', (string) $log->error);
    }

    /** No-secrets plus no-evidence in payload and logs. */
    public function test_no_secrets_no_evidence_in_payload_and_logs(): void
    {
        $website = $this->website();
        $incident = $this->incident($website, ['message' => 'Content served does not match baseline.']);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 1);

        $dump = (string) json_encode([$payload->title, $payload->summary, $payload->template_vars, $payload->admin_url]);
        foreach (['secret', 'token', 'password', 'smtp', 'bot_token'] as $frag) {
            $this->assertStringNotContainsStringIgnoringCase($frag, $dump);
        }
        foreach (['RULE-', 'redirect_chain', 'snapshot'] as $frag) {
            $this->assertStringNotContainsString($frag, $dump);
        }

        $redacted = MessageRedactor::redact('smtp password=supersecret token=abc bot_token=xyz');
        $this->assertStringNotContainsString('supersecret', (string) $redacted);
        $this->assertStringContainsString('[REDACTED]', (string) $redacted);

        $result = new DeliveryResult(ok: true, latency_ms: 5);
        $this->assertTrue($result->ok);
        $this->assertFalse($result->retryable);

        $payloadRecord = new NotificationPayload(
            incident_id: $incident->id,
            website_id: $website->id,
            event_kind: NotificationDispatcher::EVENT_OPENED,
            severity: 'WARNING',
            title: 't',
            summary: 's',
            detected_at: new \DateTimeImmutable('2026-09-30 12:00:00'),
            admin_url: 'http://localhost/admin/incidents/'.$incident->id,
            dedupe_key: 'k',
        );
        $this->assertSame($incident->id, $payloadRecord->incident_id);
        Mail::fake();
        $this->assertTrue(true);
    }
}
