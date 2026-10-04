<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\AdminNotifications;

use App\Jobs\RunWebsiteCheck;
use App\Models\AdminNotification;
use App\Models\Check;
use App\Models\Incident;
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
 * In-app notification generation from real events (Phase G, ADR-038,
 * NOTIFICATIONS.md §15.2).
 *
 * Drives the real check pipeline (mirroring IncidentLifecycleTest) so the whole
 * chain check -> detection -> incident -> in-app notification is exercised:
 * DOWN/recovery, security detected/resolved, dedupe, and — crucially — that
 * routine healthy checks generate nothing.
 */
final class AdminNotificationGenerationTest extends TestCase
{
    use RefreshDatabase;

    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DetectionRuleSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);
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

    private function fakeHttp(): void
    {
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_routine_healthy_checks_generate_no_notification(): void
    {
        $this->admin();
        $this->fakeHttp();
        $this->down = false;
        $website = $this->website();

        $this->runJob($website);
        $this->travel(5)->minutes();
        $this->runJob($website);

        $this->assertSame(0, AdminNotification::query()->count(), 'Routine UP/OK checks must never notify.');
    }

    public function test_down_transition_creates_one_incident_down_notification_per_admin(): void
    {
        $adminA = $this->admin();
        $adminB = $this->admin();
        $this->fakeHttp();
        $this->down = false;
        $website = $this->website();
        $this->runJob($website); // baseline

        $this->down = true;
        $this->travel(5)->minutes();
        $this->runJob($website); // failure 1: below threshold
        $this->travel(5)->minutes();
        $this->runJob($website); // failure 2: threshold crossed

        $this->assertSame(1, Incident::query()->where('type', 'availability')->count());

        foreach ([$adminA, $adminB] as $admin) {
            $this->assertSame(1, AdminNotification::query()
                ->where('user_id', $admin->id)
                ->where('type', AdminNotification::TYPE_INCIDENT_DOWN)
                ->count());
        }

        $this->assertSame(2, AdminNotification::query()->count());
    }

    public function test_repeated_down_checks_do_not_duplicate_the_notification(): void
    {
        $this->admin();
        $this->fakeHttp();
        $this->down = false;
        $website = $this->website();
        $this->runJob($website);

        $this->down = true;
        for ($i = 0; $i < 5; $i++) {
            $this->travel(5)->minutes();
            $this->runJob($website);
        }

        $this->assertSame(1, Incident::query()->where('type', 'availability')->count());
        $this->assertSame(1, AdminNotification::query()
            ->where('type', AdminNotification::TYPE_INCIDENT_DOWN)
            ->count(), 'Repeat detections must not duplicate the in-app notification.');
    }

    public function test_recovery_creates_an_incident_recovered_notification(): void
    {
        $this->admin();
        $this->fakeHttp();
        $this->down = false;
        $website = $this->website();
        $this->runJob($website);

        $this->down = true;
        $this->travel(5)->minutes();
        $this->runJob($website);
        $this->travel(5)->minutes();
        $this->runJob($website);
        $this->assertSame(1, AdminNotification::query()->where('type', AdminNotification::TYPE_INCIDENT_DOWN)->count());

        // Sustained recovery (2 consecutive healthy checks) auto-resolves.
        $this->down = false;
        $this->travel(5)->minutes();
        $this->runJob($website);
        $this->travel(5)->minutes();
        $this->runJob($website);

        $this->assertSame('RESOLVED', Incident::query()->sole()->status);
        $this->assertSame(1, AdminNotification::query()
            ->where('type', AdminNotification::TYPE_INCIDENT_RECOVERED)
            ->count());
    }

    public function test_security_incident_detected_is_wired_from_the_engine(): void
    {
        $this->admin();
        $website = $this->website();
        $check = $this->check($website);

        app(IncidentEngine::class)->processCheck($website, $check, [
            'security_state' => 'INCIDENT',
            'score' => 20,
            'triggered_rules' => ['RULE-XYZ-999' => ['category' => 'keyword', 'weight' => 20]],
        ]);

        $this->assertSame(1, Incident::query()->where('type', 'security')->count());
        $this->assertSame(1, AdminNotification::query()
            ->where('type', AdminNotification::TYPE_SECURITY_INCIDENT_DETECTED)
            ->count());

        // The title is human-readable and carries no rule id / score detail.
        $notification = AdminNotification::query()->where('type', AdminNotification::TYPE_SECURITY_INCIDENT_DETECTED)->sole();
        $this->assertStringContainsString('Example', $notification->title);
        $this->assertStringNotContainsString('RULE-XYZ-999', $notification->title.' '.(string) $notification->body);
    }

    public function test_security_incident_resolved_is_wired_from_the_controller(): void
    {
        $admin = $this->admin();
        $website = $this->website();
        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'CRITICAL',
            'status' => 'DETECTED',
            'score' => 20,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incidents.resolve', $incident))
            ->assertRedirect();

        $this->assertSame('RESOLVED', $incident->refresh()->status);
        $this->assertSame(1, AdminNotification::query()
            ->where('user_id', $admin->id)
            ->where('type', AdminNotification::TYPE_SECURITY_INCIDENT_RESOLVED)
            ->count());
    }

    private function check(Website $website): Check
    {
        return Check::create([
            'website_id' => $website->id,
            'check_key' => 'gen-check-'.$website->id,
            'started_at' => now(),
            'finished_at' => now(),
            'availability_state' => 'UP',
            'security_state' => 'OK',
            'score' => 0,
        ]);
    }
}
