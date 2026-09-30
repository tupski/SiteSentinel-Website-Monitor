<?php

declare(strict_types=1);

namespace Tests\Feature\Detection;

use App\Jobs\RunWebsiteCheck;
use App\Models\Check;
use App\Models\Website;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use App\Services\Monitor\Probe;
use App\Services\Security\SsrfGuard;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FR-49 (MVP): every detection persists its rule attribution — which rules
 * fired, with what weights, contributing to which score.
 *
 * The Phase 5 storage home is `checks.triggered_rules` (JSON). This is the
 * vehicle for (a) FR-49 attribution and (b) the per-signal carry-forward
 * arithmetic of DETECTION-RULES 6.4; the incident-side copy
 * (`incidents.triggered_rules`) is a Phase 6 concern and is not created here.
 */
final class TriggeredRulesAttributionTest extends TestCase
{
    use RefreshDatabase;

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
        $padding = str_repeat('<p>Isi halaman normal.</p>', 12);

        Http::fake([
            'https://public.example.test/*' => fn () => Http::response(
                '<html><head><title>Situs Slot Gacor Maxwin</title></head><body>'.$padding
                .'<div style="display:none">maxwin</div></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
    }

    public function test_check_persists_per_rule_attribution_matching_the_score(): void
    {
        $this->fakeHttp();
        $website = $this->website();

        (new RunWebsiteCheck($website))->handle(
            new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)),
            app(RuleEngine::class),
            app(SnapshotWriter::class),
        );

        $check = Check::where('website_id', $website->id)->orderByDesc('id')->firstOrFail();

        $this->assertIsArray($check->triggered_rules);
        $this->assertNotSame([], $check->triggered_rules);

        // Recompute the score from the stored attribution and compare: the
        // persisted map must fully explain the persisted score (FR-49).
        $multipliers = ['low' => 0.5, 'medium' => 1.0, 'high' => 1.5];
        $recomputed = 0;
        foreach ($check->triggered_rules as $ruleId => $meta) {
            $this->assertMatchesRegularExpression('/^RULE-(AV|SSL|RED|CNT|KW|LNK|SEO)-\d{3}$/', $ruleId);
            $this->assertArrayHasKey('category', $meta);
            $this->assertArrayHasKey('weight', $meta);
            $this->assertArrayHasKey('confidence', $meta);
            $this->assertArrayHasKey('reason', $meta);
            $this->assertArrayHasKey($meta['confidence'], $multipliers);

            $recomputed += (int) round($meta['weight'] * $multipliers[$meta['confidence']]);
        }

        $this->assertSame($check->score, $recomputed, 'Stored attribution must reproduce the stored score.');
    }

    public function test_score_matches_expected_band_for_the_injected_content(): void
    {
        $this->fakeHttp();
        $website = $this->website();

        (new RunWebsiteCheck($website))->handle(
            new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)),
            app(RuleEngine::class),
            app(SnapshotWriter::class),
        );

        $check = Check::where('website_id', $website->id)->orderByDesc('id')->firstOrFail();

        // Title carries a tier-1 keyword and hidden keyword evidence exists.
        $this->assertArrayHasKey('RULE-KW-004', $check->triggered_rules);
        $this->assertArrayHasKey('RULE-KW-005', $check->triggered_rules);
        $this->assertSame('content-keyword', $check->triggered_rules['RULE-KW-004']['category']);
        $this->assertContains($check->security_state, ['SUSPECT', 'INCIDENT']);
    }

    public function test_clean_check_persists_an_empty_attribution_map(): void
    {
        Http::fake([
            'https://public.example.test/*' => Http::response(
                '<html><head><title>Example</title></head><body>'
                .str_repeat('<p>Ordinary paragraph of site copy.</p>', 25).'</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $website = $this->website();

        (new RunWebsiteCheck($website))->handle(
            new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)),
            app(RuleEngine::class),
            app(SnapshotWriter::class),
        );

        $check = Check::where('website_id', $website->id)->orderByDesc('id')->firstOrFail();

        $this->assertSame([], $check->triggered_rules);
        $this->assertSame(0, $check->score);
        $this->assertSame('OK', $check->security_state);
    }
}
