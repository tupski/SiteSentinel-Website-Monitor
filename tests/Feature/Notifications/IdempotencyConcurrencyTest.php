<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\SendNotification;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Website;
use App\Services\Notifications\CircuitBreaker;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Idempotency and per-channel isolation (NOTIFICATIONS.md §9.1, §12.1).
 *
 * No live network. Single sent row per dedupe_key; email success is
 * independent of a telegram failure on the same incident event.
 */
final class IdempotencyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private int $telegramStatus = 200;

    /** @var array<string, mixed> */
    private array $telegramBody = ['ok' => true, 'result' => ['message_id' => 1]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        Http::fake([
            'https://api.telegram.org/*' => fn () => Http::response($this->telegramBody, $this->telegramStatus),
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

    private function incident(Website $website): Incident
    {
        return Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'message' => 'Content served does not match baseline.',
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ]);
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

    private function telegramChannel(): NotificationChannel
    {
        return NotificationChannel::create([
            'type' => 'telegram',
            'name' => 'Ops tg',
            'enabled' => true,
            'config' => ['chat_id' => '123456', 'min_severity' => 'WARNING'],
            'secret_ref' => 'fake-bot-token-value',
        ]);
    }

    /** Double job with same dedupe_key yields a single send. */
    public function test_double_job_same_dedupe_key_single_send(): void
    {
        $incident = $this->incident($this->website());
        $channel = $this->emailChannel();
        $dedupe = NotificationDispatcher::dedupeKey($incident->id, NotificationDispatcher::EVENT_OPENED, (int) $channel->id);

        $first = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $first->withFakeQueueInteractions();
        $first->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $replay = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $replay->withFakeQueueInteractions();
        $replay->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $this->assertSame(1, NotificationLog::query()->where('dedupe_key', $dedupe)->where('status', 'sent')->count());
        $this->assertSame(1, NotificationLog::query()->where('dedupe_key', $dedupe)->count());
        $replay->assertNotFailed();
        $replay->assertNotReleased();
    }

    /** Concurrent dispatcher fan-out converges on one send plus suppression. */
    public function test_concurrent_dispatch_safe(): void
    {
        $this->emailChannel();
        $incident = $this->incident($this->website());
        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_OPENED);
        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_OPENED);

        $sent = NotificationLog::query()->where('incident_id', $incident->id)->where('status', 'sent')->count();
        $suppressed = NotificationLog::query()->where('incident_id', $incident->id)->where('status', 'suppressed')->count();

        $this->assertSame(1, $sent, 'duplicate fan-out must not duplicate the send (NFR-09)');
        $this->assertSame(1, $suppressed);
    }

    /** Email success is not retried when telegram fails on the same event. */
    public function test_email_success_not_retried_when_telegram_fails(): void
    {
        $this->telegramStatus = 500;
        $this->telegramBody = ['ok' => false, 'description' => 'Internal error'];

        $email = $this->emailChannel();
        $telegram = $this->telegramChannel();
        $incident = $this->incident($this->website());

        app(NotificationDispatcher::class)->dispatch($incident->id, NotificationDispatcher::EVENT_OPENED);

        $emailLog = NotificationLog::query()->where('channel_id', $email->id)->firstOrFail();
        $telegramLog = NotificationLog::query()->where('channel_id', $telegram->id)->where('status', 'failed')->firstOrFail();

        $this->assertSame('sent', $emailLog->status);
        $this->assertSame('failed', $telegramLog->status);
        $this->assertStringContainsString('telegram_500', (string) $telegramLog->error);

        // Per-channel isolation: the email send ran exactly once.
        $this->assertSame(1, NotificationLog::query()->where('channel_id', $email->id)->where('status', 'sent')->count());
    }

    /** Dedupe identity holds: one sent row per incident plus event plus channel. */
    public function test_unique_constraint_holds(): void
    {
        $this->emailChannel();
        $incident = $this->incident($this->website());
        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_OPENED);
        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_OPENED);
        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_OPENED);

        $sent = NotificationLog::query()->where('incident_id', $incident->id)->where('status', 'sent')->count();

        $this->assertSame(1, $sent, 'identity rule is one notification per incident plus event plus channel (FR-68)');

        $keys = NotificationLog::query()->where('incident_id', $incident->id)->pluck('dedupe_key')->all();
        foreach ($keys as $key) {
            $this->assertStringContainsString((string) $incident->id, (string) $key);
            $this->assertStringContainsString(NotificationDispatcher::EVENT_OPENED, (string) $key);
        }
    }
}
