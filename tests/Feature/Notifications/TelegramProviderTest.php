<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\SendNotification;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Website;
use App\Services\Notifications\Channels\TelegramProvider;
use App\Services\Notifications\CircuitBreaker;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Telegram provider behaviour (NOTIFICATIONS.md §6).
 *
 * Http::fake only; no live bot token or network. Asserts request shape
 * (token URL redacted on assert, chat_id payload), HTML escaping, 4096
 * truncation preserving admin_url, retry classification, and no-token logs.
 */
final class TelegramProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'fake-bot-token-value';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    }

    private function website(string $suffix = ''): Website
    {
        $host = 'public'.$suffix.'.example.test';

        return Website::create([
            'name' => 'Example Shop',
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
            'message' => 'Content served does not match baseline.',
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ], $overrides));
    }

    private function telegramChannel(array $overrides = []): NotificationChannel
    {
        return NotificationChannel::create(array_merge([
            'type' => 'telegram',
            'name' => 'Ops tg',
            'enabled' => true,
            'config' => ['chat_id' => '123456', 'min_severity' => 'WARNING'],
            'secret_ref' => self::SECRET,
        ], $overrides));
    }

    /** Request shape: tokenised URL, chat_id payload, HTML mode. */
    public function test_request_shape_chat_id_payload(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200),
        ]);

        $website = $this->website();
        $incident = $this->incident($website);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 9);

        $result = (new TelegramProvider(['chat_id' => '123456'], self::SECRET))->send($payload);

        $this->assertTrue($result->ok);
        $this->assertSame('7', $result->provider_message_id);

        Http::assertSent(function ($request): bool {
            $recorded = (string) $request->url();
            // Assert on the shape without echoing the secret: URL carries the
            // bot path and the payload carries chat_id plus HTML parse mode.
            $this->assertStringContainsString('https://api.telegram.org/bot', $recorded);
            $this->assertStringContainsString('/sendMessage', $recorded);
            $data = $request->data();

            return ($data['chat_id'] ?? null) === '123456'
                && ($data['parse_mode'] ?? null) === 'HTML'
                && is_string($data['text'] ?? null)
                && $data['text'] !== '';
        });
    }

    /** Untrusted values escaped for HTML parse mode. */
    public function test_html_escaping(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        $website = $this->website();
        $website->name = 'Shop <b>& "quotes"';
        $website->save();
        $incident = $this->incident($website, ['message' => 'Odd <script>& summary']);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 9);

        (new TelegramProvider(['chat_id' => '123456'], self::SECRET))->send($payload);

        Http::assertSent(function ($request): bool {
            $text = (string) ($request->data()['text'] ?? '');

            $this->assertStringContainsString('<b>', $text);
            $this->assertStringContainsString('&', $text);
            $this->assertStringNotContainsString('<script>', $text);

            return true;
        });
    }

    /** Over-length body truncated at 4096 with admin_url surviving. */
    public function test_truncation_preserves_admin_url(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        $website = $this->website();
        $incident = $this->incident($website, ['message' => str_repeat('Long benign summary sentence. ', 300)]);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 9);

        (new TelegramProvider(['chat_id' => '123456'], self::SECRET))->send($payload);

        Http::assertSent(function ($request) use ($payload): bool {
            $text = (string) ($request->data()['text'] ?? '');
            $this->assertLessThanOrEqual(4096, mb_strlen($text));
            $this->assertStringContainsString($payload->admin_url, $text);

            return true;
        });
    }

    /** Ok delivery logged sent with provider message id. */
    public function test_ok_logged(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
        ]);

        $website = $this->website();
        $incident = $this->incident($website);
        $channel = $this->telegramChannel();

        $job = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $job->withFakeQueueInteractions();
        $job->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $log = NotificationLog::query()->where('channel_id', $channel->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('42', (string) $log->provider_message_id);
        $job->assertNotReleased();
        $job->assertNotFailed();
    }

    /** 429 honours retry_after as release delay. */
    public function test_429_honours_retry_after(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(
                ['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 37]],
                429
            ),
        ]);

        $website = $this->website();
        $incident = $this->incident($website);
        $channel = $this->telegramChannel();

        $job = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $job->withFakeQueueInteractions();
        $job->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $job->assertReleased(37);
        $log = NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'failed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('rate_limited', (string) $log->error);
        $this->assertStringContainsString('retry_after=37', (string) $log->error);
    }

    /** Timeout is retryable (released, not dead-lettered). */
    public function test_timeout_retryable(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => function (): void {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $website = $this->website();
        $incident = $this->incident($website);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 9);

        $result = (new TelegramProvider(['chat_id' => '123456'], self::SECRET))->send($payload);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->retryable);
        $this->assertSame('timeout', $result->error_code);
    }

    /** 401/403/400 permanent: dead-letter, no release. */
    public function test_auth_and_bad_request_permanent(): void
    {
        // One registration serving an ordered sequence: re-registering
        // Http::fake merges stubs with first-match-wins, so per-iteration
        // fakes would never take effect.
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Unauthorized'], 401)
                ->push(['ok' => false, 'description' => 'Forbidden'], 403)
                ->push(['ok' => false, 'description' => 'Bad request'], 400)
                ->push(['ok' => false, 'description' => 'Forbidden'], 403),
        ]);

        foreach ([401, 403, 400] as $i => $status) {
            $website = $this->website('-perm-'.$status);
            $incident = $this->incident($website);
            $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 9);

            $result = (new TelegramProvider(['chat_id' => '123456'], self::SECRET))->send($payload);

            $this->assertFalse($result->ok, "HTTP {$status} must fail");
            $this->assertFalse($result->retryable, "HTTP {$status} must be permanent");
            $this->assertSame('telegram_'.$status, $result->error_code);
            unset($i);
        }

        // Permanent failure through the job dead-letters through fail(), not release().
        $website = $this->website('-perm-job');
        $incident = $this->incident($website);
        $channel = $this->telegramChannel();
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
    }

    /** Bot token never appears in logs. */
    public function test_no_token_in_logs(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        $website = $this->website();
        $incident = $this->incident($website);
        $channel = $this->telegramChannel();

        $job = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $job->withFakeQueueInteractions();

        try {
            $job->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));
        } catch (RuntimeException) {
            // Permanent 401 dead-letter path; the log row is what matters here.
        }

        foreach (NotificationLog::query()->where('channel_id', $channel->id)->get() as $log) {
            $this->assertStringNotContainsString(self::SECRET, (string) $log->error);
            $this->assertStringNotContainsString(self::SECRET, (string) $log->provider_message_id);
        }

        // Decrypted secret is readable by the model (encrypted cast works).
        $this->assertSame(self::SECRET, (string) $channel->refresh()->secret_ref);
    }
}
