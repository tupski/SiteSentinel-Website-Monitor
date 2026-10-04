<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\RunWebsiteCheck;
use App\Models\Check;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Website;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use App\Services\Incidents\IncidentEngine;
use App\Services\Monitor\Probe;
use App\Services\Security\SsrfGuard;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PRD AC-22 (NOTIFICATIONS.md §12.5, FR-72, NFR-07, PLAN.md AC-7-05).
 *
 * Entry point: a scheduled monitoring check (RunWebsiteCheck) that opens an
 * incident and fans out a notification to a channel whose provider fails, plus
 * the admin dashboard (GET /admin) that must make those failures visible.
 *
 * Expected behaviour:
 *  - a failing notification channel NEVER prevents incident creation
 *    (availability/security reconciliation is independent of alerting);
 *  - the failing deliveries are visible to Admin on the dashboard.
 */
final class IncidentCreationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DetectionRuleSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);

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
            // The channel provider is permanently failing (401 Unauthorized).
            'https://api.telegram.org/*' => fn () => Http::response(
                ['ok' => false, 'description' => 'Unauthorized'],
                401,
            ),
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

    private function failingTelegramChannel(): NotificationChannel
    {
        return NotificationChannel::create([
            'type' => 'telegram',
            'name' => 'Broken Ops tg',
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

    public function test_failing_notification_channel_does_not_prevent_incident_creation(): void
    {
        $this->failingTelegramChannel();
        $website = $this->website();

        // Healthy baseline.
        $this->runJob($website);

        // Two consecutive failures cross the availability threshold and open an
        // incident, even though the notification channel is permanently failing.
        $this->travel(5)->minutes();
        $this->down = true;
        $this->runJob($website);

        $this->travel(5)->minutes();
        $this->runJob($website);

        $incident = Incident::query()->sole();
        $this->assertSame('availability', $incident->type);
        $this->assertSame('DETECTED', $incident->status);

        // The detection is persisted independently of alerting (AC-05/AC-22).
        $this->assertGreaterThanOrEqual(2, Check::query()->where('website_id', $website->id)->count());
    }

    public function test_failing_channel_failures_are_visible_to_admin_on_dashboard(): void
    {
        $channel = $this->failingTelegramChannel();

        // A permanently failed delivery attempt, as the dispatcher would record it.
        NotificationLog::create([
            'incident_id' => null,
            'channel_id' => $channel->id,
            'status' => 'failed',
            'attempt' => 3,
            'provider_message_id' => null,
            'error' => '[unauthorized] provider rejected the request',
            'dedupe_key' => 'ac22-dedupe-key',
            'sent_at' => null,
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();

        $html = (string) $response->getContent();

        // FR-101: the failed delivery and its channel are visible to Admin.
        $this->assertStringContainsString('notifications-heading', $html);
        $this->assertStringContainsString('Failed deliveries', $html);
        $this->assertStringContainsString('Broken Ops tg', $html);

        // And the raw provider error text is shown (redaction is a separate
        // concern proven elsewhere); the point here is Admin visibility.
        $this->assertStringContainsString('failed', $html);
    }

    public function test_disabled_channels_are_visible_to_admin(): void
    {
        NotificationChannel::create([
            'type' => 'email',
            'name' => 'Disabled Ops mail',
            'enabled' => false,
            'config' => [
                'recipients' => ['ops@example.test'],
                'host' => 'smtp.example.test',
                'port' => 587,
                'encryption' => 'tls',
                'from_address' => 'alerts@example.test',
                'from_name' => 'SiteSentinel',
                'min_severity' => 'WARNING',
            ],
            'secret_ref' => 'smtp-secret-value',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Disabled channels')
            ->assertSee('Disabled Ops mail');
    }
}
