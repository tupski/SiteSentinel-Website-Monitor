<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\DispatchIncidentNotifications;
use App\Jobs\RunWebsiteCheck;
use App\Jobs\SendNotification;
use App\Models\Check;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Website;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use App\Services\Incidents\IncidentEngine;
use App\Services\Monitor\Probe;
use App\Services\Notifications\CircuitBreaker;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderRegistry;
use App\Services\Security\SsrfGuard;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Queue failure isolation (NOTIFICATIONS.md §12.5, FR-72, NFR-07, NFR-10).
 *
 * A failing provider must never fail the monitoring job or incident
 * creation. Bounded retries, failed_jobs visibility, partial success,
 * and no infinite loops. No live network or creds.
 */
final class QueueFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    private bool $down = false;

    private int $telegramStatus = 401;

    /** @var array<string, mixed> */
    private array $telegramBody = ['ok' => false, 'description' => 'Unauthorized'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DetectionRuleSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);
        // Single stateful fake: re-registering Http::fake merges stubs and the
        // first match wins, so per-test behaviour is driven by these flags.
        Http::fake([
            'https://public.example.test/*' => fn () => $this->down
                ? Http::failedConnection('connection refused')
                : Http::response(
                    '<html><head><title>Example</title></head><body>'
                    .str_repeat('<p>Ordinary legitimate site copy for visitors.</p>', 20)
                    .'</body></html>',
                    200,
                    ['Content-Type' => 'text/html'],
                ),
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
            'monitor_ssl' => true,
            'monitor_redirects' => true,
            'monitor_content' => true,
            'monitor_security' => true,
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

    private function runJob(Website $website): void
    {
        (new RunWebsiteCheck($website))->handle(
            new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)),
            app(RuleEngine::class),
            app(SnapshotWriter::class),
            app(IncidentEngine::class),
        );
    }

    /** Provider failure never fails the monitoring job or incident creation. */
    public function test_provider_fail_never_fails_check_or_incident_creation(): void
    {
        $this->emailChannel();
        $this->telegramChannel();
        $website = $this->website();

        $this->runJob($website);

        $this->travel(5)->minutes();
        $this->down = true;
        $this->runJob($website);

        $this->assertSame(0, Incident::count());

        $this->travel(5)->minutes();
        $this->runJob($website);

        $incident = Incident::query()->sole();
        $this->assertSame('availability', $incident->type);
        $this->assertSame('DETECTED', $incident->status);
        $this->assertGreaterThanOrEqual(2, Check::query()->where('website_id', $website->id)->count());
    }

    /** Retries bounded on both notification jobs. */
    public function test_bounded_tries(): void
    {
        $send = new SendNotification(1, 1, NotificationDispatcher::EVENT_OPENED);
        $intent = new DispatchIncidentNotifications(1, NotificationDispatcher::EVENT_OPENED);

        $this->assertSame(3, $send->tries);
        $this->assertSame(3, $intent->tries);
        $this->assertNotEmpty($send->backoff());
        $this->assertLessThanOrEqual(3, count($send->backoff()));

        $until = $send->retryUntil();
        $this->assertTrue($until > now()->addHour());
        $this->assertTrue($until <= now()->addHours(3));
    }

    /** Permanent failure lands in failed_jobs and stays visible. */
    public function test_failed_jobs_visible(): void
    {
        config(['queue.default' => 'database']);

        $website = $this->website();
        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ]);
        $channel = $this->telegramChannel();

        SendNotification::dispatch($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED)->onConnection('database');

        $this->assertSame(1, DB::table('jobs')->count());

        // SendNotification pins itself to the `notifications` queue, so the
        // worker must listen there (the default queue would stay empty).
        Artisan::call('queue:work', ['--once' => true, '--sleep' => '0', '--tries' => '1', '--queue' => 'notifications']);

        $failed = DB::table('failed_jobs')->count();
        $this->assertSame(1, $failed, 'permanent provider failure must dead-letter to failed_jobs (NFR-08)');

        $payload = (string) DB::table('failed_jobs')->value('payload');
        $this->assertStringContainsString('SendNotification', $payload);

        $log = NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'failed')->first();
        $this->assertNotNull($log);
    }

    /** Partial success: email sent even when telegram fails on the same event. */
    public function test_partial_success_correct(): void
    {
        $email = $this->emailChannel();
        $telegram = $this->telegramChannel();
        $website = $this->website();
        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ]);

        app(NotificationDispatcher::class)->dispatch($incident->id, NotificationDispatcher::EVENT_OPENED);

        $this->assertSame('sent', NotificationLog::query()->where('channel_id', $email->id)->value('status'));
        $this->assertSame('failed', NotificationLog::query()->where('channel_id', $telegram->id)->value('status'));
        $this->assertTrue($incident->refresh()->exists());
    }

    /** Retryable failure releases once; no infinite loop of jobs. */
    public function test_no_infinite_loop(): void
    {
        $this->telegramStatus = 500;
        $this->telegramBody = ['ok' => false, 'description' => 'Internal error'];

        $website = $this->website();
        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ]);
        $channel = $this->telegramChannel();

        $job = new SendNotification($incident->id, (int) $channel->id, NotificationDispatcher::EVENT_OPENED);
        $job->withFakeQueueInteractions();
        $job->handle(app(NotificationProviderRegistry::class), app(CircuitBreaker::class));

        $job->assertReleased();
        $job->assertNotFailed();

        // Exactly one failed attempt row; the release does not fan out new rows.
        $this->assertSame(1, NotificationLog::query()->where('channel_id', $channel->id)->count());
        $this->assertSame(1, NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'failed')->count());
    }
}
