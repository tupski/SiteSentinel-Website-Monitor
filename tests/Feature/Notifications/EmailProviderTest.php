<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\SendNotification;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Website;
use App\Services\Notifications\Channels\EmailProvider;
use App\Services\Notifications\CircuitBreaker;
use App\Services\Notifications\MessageRedactor;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Email provider behaviour (NOTIFICATIONS.md §5).
 *
 * No live SMTP, no Mail::fake (the provider sends view-arrays, which the
 * MailFake drops silently). Success path asserts against the real `array`
 * transport; failure classification is driven by a stub mailer throwing
 * scripted TransportExceptions.
 */
final class EmailProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    private function website(): Website
    {
        return Website::create([
            'name' => 'Example Shop',
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

    /**
     * Swap the Mail facade for a stub whose send() throws the given
     * transport message. Avoids Mockery and any real SMTP connection.
     */
    private function swapThrowingMailer(string $message): void
    {
        Mail::swap(new class($message)
        {
            public function __construct(private readonly string $message) {}

            public function mailer($name = null): object
            {
                $message = $this->message;

                return new class($message)
                {
                    public function __construct(private readonly string $message) {}

                    public function send($view, array $data = [], $callback = null): void
                    {
                        throw new TransportException($this->message);
                    }
                };
            }
        });
    }

    /** Recipients resolve from config string and list forms. */
    public function test_recipient_resolution_from_config(): void
    {
        $website = $this->website();
        $incident = $this->incident($website);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 1);

        $single = new EmailProvider(
            ['recipients' => 'ops@example.test', 'host' => '', 'from_address' => 'alerts@example.test'],
            'secret-value'
        );
        $this->assertTrue($single->send($payload)->ok);

        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
        $this->assertNotEmpty($transport->messages());

        $multi = new EmailProvider(
            ['recipients' => 'a@example.test, b@example.test', 'host' => '', 'from_address' => 'alerts@example.test'],
            'secret-value'
        );
        $this->assertTrue($multi->send($payload)->ok);

        $last = $transport->messages()->last();
        $recipients = collect($last->getEnvelope()->getRecipients())->map(fn ($a) => $a->getAddress())->all();
        sort($recipients);
        $this->assertSame(['a@example.test', 'b@example.test'], $recipients);
    }

    /** Subject format identifies product, severity, website. */
    public function test_subject_format(): void
    {
        $website = $this->website();
        $incident = $this->incident($website);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 1);

        (new EmailProvider(
            ['recipients' => ['ops@example.test'], 'host' => '', 'from_address' => 'alerts@example.test'],
            'secret-value'
        ))->send($payload);

        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
        $subject = (string) $transport->messages()->last()->getOriginalMessage()->getSubject();
        $this->assertStringContainsString('[SiteSentinel]', $subject);
        $this->assertStringContainsString('WARNING', $subject);
        $this->assertStringContainsString('Example Shop', $subject);
    }

    /** HTML plus text bodies rendered with summary and admin link. */
    public function test_html_and_text_rendered(): void
    {
        $website = $this->website();
        $incident = $this->incident($website);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 1);

        (new EmailProvider(
            ['recipients' => ['ops@example.test'], 'host' => '', 'from_address' => 'alerts@example.test'],
            'secret-value'
        ))->send($payload);

        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
        $original = $transport->messages()->last()->getOriginalMessage();

        $html = (string) $original->getHtmlBody();
        $text = (string) $original->getTextBody();

        $this->assertStringContainsString('Example Shop', $html);
        $this->assertStringContainsString('admin/incidents/'.$incident->id, $html);
        $this->assertStringContainsString('Example Shop', $text);
        $this->assertStringContainsString('admin/incidents/'.$incident->id, $text);
    }

    /** Sent delivery logged with sent status. */
    public function test_sent_logged(): void
    {
        $website = $this->website();
        $incident = $this->incident($website);
        $channel = $this->emailChannel();

        $job = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $job->withFakeQueueInteractions();
        $job->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $log = NotificationLog::query()->where('channel_id', $channel->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertNull($log->error);
    }

    /** 4xx transient is retryable; 5xx permanent is not. */
    public function test_4xx_retryable_vs_5xx_permanent(): void
    {
        $website = $this->website();
        $incident = $this->incident($website);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 1);
        $config = ['recipients' => ['ops@example.test'], 'host' => 'smtp.example.test'];

        $this->swapThrowingMailer('Expected response code 421 got 4.7.0 greylisted, try again later');
        $transient = (new EmailProvider($config, 's3cret'))->send($payload);
        $this->assertFalse($transient->ok);
        $this->assertTrue($transient->retryable);
        $this->assertSame('smtp_4xx', $transient->error_code);

        $this->swapThrowingMailer('Expected response code 550 mailbox unavailable 5.1.1 user unknown');
        $permanent = (new EmailProvider($config, 's3cret'))->send($payload);
        $this->assertFalse($permanent->ok);
        $this->assertFalse($permanent->retryable);
        $this->assertSame('smtp_5xx', $permanent->error_code);
    }

    /** Secrets never reach logs; error strings scrubbed. */
    public function test_secrets_redacted_in_logs(): void
    {
        $website = $this->website();
        $incident = $this->incident($website);
        $payload = NotificationDispatcher::buildPayload($incident, $website, NotificationDispatcher::EVENT_OPENED, 1);

        $this->swapThrowingMailer('smtp password=supersecret-value auth failed');
        $result = (new EmailProvider(
            ['recipients' => ['ops@example.test'], 'host' => 'smtp.example.test'],
            'supersecret-value'
        ))->send($payload);

        $this->assertStringNotContainsString('supersecret-value', (string) $result->error_message);
        $this->assertStringContainsString('[REDACTED]', (string) $result->error_message);

        // Same redacted failure through the job log path.
        $channel = $this->emailChannel();
        $job = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $job->withFakeQueueInteractions();
        $job->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $log = NotificationLog::query()->where('channel_id', $channel->id)->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('supersecret-value', (string) $log->error);

        // Payload itself carries no secrets or evidence (FR-73).
        $dump = (string) json_encode([$payload->title, $payload->summary, $payload->template_vars]);
        $this->assertStringNotContainsString('supersecret-value', $dump);
        $this->assertStringNotContainsString('RULE-', $dump);
        $this->assertStringNotContainsString('redirect_chain', $dump);

        $this->assertStringContainsString('[REDACTED]', (string) MessageRedactor::redact('token=abc'));
    }
}
