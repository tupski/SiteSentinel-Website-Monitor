<?php

declare(strict_types=1);

namespace Tests\Feature\Detection;

use App\Jobs\RunWebsiteCheck;
use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\Snapshot;
use App\Models\Website;
use App\Models\WebsiteBaseline;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use App\Services\Monitor\Probe;
use App\Services\Security\SsrfGuard;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end persistence checks for the monitoring job: idempotency on an
 * identical `check_key` and snapshot capture on an escalating check
 * (ARCHITECTURE.md 5.1, AGENTS.md 9, DATABASE.md 3.12).
 *
 * The probe is driven through `Http::fake`, so no live network access occurs.
 */
final class RunWebsiteCheckPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DetectionRuleSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);
    }

    private bool $suspicious = false;

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

    private function fakeResponse(bool $suspicious): string
    {
        if (! $suspicious) {
            $paragraph = str_repeat('<p>A perfectly ordinary paragraph of legitimate site copy.</p>', 20);

            return '<html><head><title>Example</title></head><body>'.$paragraph.'</body></html>';
        }

        // Padded past the 512-byte floor so RULE-CNT-004 does not also fire;
        // the escalation must come from real injection evidence.
        $padding = str_repeat('<p>Isi halaman yang tampak normal bagi pengunjung biasa.</p>', 12);

        return '<html><head><title>Situs Slot Gacor Maxwin Terpercaya</title></head><body>'
            .$padding
            .'<a href="https://link-alternatif.top/go" style="visibility:hidden">go</a>'
            .'<div style="display:none">maxwin situs slot gacor</div>'
            .'</body></html>';
    }

    /**
     * Install a single stateful fake. Calling Http::fake() twice would merge
     * stubs and the first match would always win, so the served body is driven
     * by this flag instead.
     */
    private function fakeHttp(): void
    {
        Http::fake([
            'https://public.example.test/*' => fn () => Http::response(
                $this->fakeResponse($this->suspicious),
                200,
                ['Content-Type' => 'text/html', 'Set-Cookie' => 'session=secret'],
            ),
        ]);
    }

    private function serveSuspicious(): void
    {
        $this->suspicious = true;
    }

    private function runJob(Website $website): void
    {
        (new RunWebsiteCheck($website))->handle(
            new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)),
            app(RuleEngine::class),
            app(SnapshotWriter::class),
        );
    }

    public function test_first_run_creates_exactly_one_check_baseline_and_extraction(): void
    {
        Storage::fake('local');
        $this->fakeHttp();
        $website = $this->website();

        $this->runJob($website);

        $this->assertSame(1, Check::where('website_id', $website->id)->count());
        $this->assertSame(1, WebsiteBaseline::where('website_id', $website->id)->count());
        $this->assertSame(1, CheckExtraction::where('website_id', $website->id)->count());
        $this->assertNotNull($website->fresh()->current_baseline_id);
    }

    public function test_baseline_persists_canonical_size_and_domains_through_the_job(): void
    {
        Storage::fake('local');
        $this->fakeHttp();
        $this->serveSuspicious();
        $website = $this->website();

        $this->runJob($website);

        $baseline = WebsiteBaseline::where('website_id', $website->id)->firstOrFail();

        $this->assertNotNull($baseline->response_size_bytes);
        $this->assertGreaterThan(0, $baseline->response_size_bytes);
        $this->assertContains('link-alternatif.top', $baseline->external_domains);
        $this->assertSame(count($baseline->external_domains), $baseline->external_link_count);
        $this->assertNotEmpty($baseline->keyword_counts);
    }

    public function test_replaying_the_same_check_key_produces_no_duplicate_rows(): void
    {
        Storage::fake('local');
        $this->fakeHttp();
        $website = $this->website();

        $this->runJob($website);

        $before = [
            'checks' => Check::where('website_id', $website->id)->count(),
            'baselines' => WebsiteBaseline::where('website_id', $website->id)->count(),
            'extractions' => CheckExtraction::where('website_id', $website->id)->count(),
            'snapshots' => Snapshot::where('website_id', $website->id)->count(),
        ];

        // Identical logical check: same minute -> identical deterministic check_key.
        $this->runJob($website);

        $after = [
            'checks' => Check::where('website_id', $website->id)->count(),
            'baselines' => WebsiteBaseline::where('website_id', $website->id)->count(),
            'extractions' => CheckExtraction::where('website_id', $website->id)->count(),
            'snapshots' => Snapshot::where('website_id', $website->id)->count(),
        ];

        $this->assertSame(1, $before['checks']);
        $this->assertSame($before, $after, 'Replay created duplicate persistent rows.');
        $this->assertSame(
            1,
            Check::where('check_key', 'website:'.$website->id.':2026-09-30-12-00')->count(),
        );
    }

    public function test_second_run_in_a_later_minute_appends_one_check_and_never_recreates_baseline(): void
    {
        Storage::fake('local');
        $this->fakeHttp();
        $website = $this->website();

        $this->runJob($website);
        $baselineId = $website->fresh()->current_baseline_id;

        $this->travel(5)->minutes();
        $this->serveSuspicious();
        $this->runJob($website);

        $this->assertSame(2, Check::where('website_id', $website->id)->count());
        $this->assertSame(1, WebsiteBaseline::where('website_id', $website->id)->count());
        $this->assertSame($baselineId, $website->fresh()->current_baseline_id, 'Baseline was recreated.');

        $latest = Check::where('website_id', $website->id)->orderByDesc('id')->firstOrFail();
        $this->assertIsArray($latest->triggered_rules);
    }

    public function test_escalating_check_persists_a_snapshot_with_relative_path(): void
    {
        Storage::fake('local');
        $this->fakeHttp();
        $website = $this->website();
        $this->runJob($website);

        $this->travel(5)->minutes();
        $this->serveSuspicious();
        $this->runJob($website);

        $check = Check::where('website_id', $website->id)->orderByDesc('id')->firstOrFail();
        $this->assertContains($check->security_state, ['SUSPECT', 'INCIDENT']);

        $snapshot = Snapshot::where('check_id', $check->id)->firstOrFail();
        $this->assertSame("snapshots/{$website->id}/{$check->id}.html", $snapshot->html_path);
        $this->assertStringNotContainsString(':', $snapshot->html_path, 'html_path must be disk-relative.');
        $this->assertFileExists(Storage::disk('local')->path($snapshot->html_path));
        $this->assertArrayNotHasKey('Set-Cookie', $snapshot->headers);
    }

    public function test_snapshot_failure_does_not_roll_back_the_check(): void
    {
        $this->fakeHttp();
        $website = $this->website();
        Storage::fake('local');
        $this->runJob($website);

        $this->travel(5)->minutes();
        $this->serveSuspicious();

        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('storage offline'));

        $this->runJob($website);

        $check = Check::where('website_id', $website->id)->orderByDesc('id')->firstOrFail();

        $this->assertContains($check->security_state, ['SUSPECT', 'INCIDENT']);
        $this->assertSame(2, Check::where('website_id', $website->id)->count());
        $this->assertSame(0, Snapshot::where('website_id', $website->id)->count());
    }

    public function test_availability_and_security_dimensions_stay_separate(): void
    {
        Storage::fake('local');
        $this->fakeHttp();
        $website = $this->website();

        $this->runJob($website);

        $check = Check::firstOrFail();
        $fresh = $website->fresh();

        $this->assertSame('UP', $check->availability_state);
        $this->assertSame('OK', $check->security_state);
        $this->assertSame('UP', $fresh->status_availability);
        $this->assertSame('OK', $fresh->status_security);
    }

    public function test_down_check_does_not_become_a_security_incident(): void
    {
        Storage::fake('local');
        Http::fake(['https://public.example.test/*' => Http::failedConnection('connection refused')]);

        $website = $this->website();
        $this->runJob($website);

        $check = Check::firstOrFail();

        $this->assertSame('DOWN', $check->availability_state);
        $this->assertNotSame('INCIDENT', $check->security_state, 'Availability failure must not be a security incident.');
    }
}
