<?php

declare(strict_types=1);

namespace Tests\Feature\Detection;

use App\Jobs\RunWebsiteCheck;
use App\Models\Check;
use App\Models\DetectionRule;
use App\Models\Incident;
use App\Models\Website;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use App\Services\Incidents\IncidentEngine;
use App\Services\Monitor\Probe;
use App\Services\Security\SsrfGuard;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Data-flow + severity-consistency verification for Phase 10 (DETECTION-RULES §11).
 *
 * Every test drives the REAL monitoring pipeline (`RunWebsiteCheck` -> `Probe`)
 * with a FAKED HTTP client and a controllable clock; no live network access
 * occurs. The purpose is to prove the persisted data the rules depend on is
 * actually populated by the real pipeline (not only by a fixture helper), and
 * that severity expectations are consistent across the rule registry, the
 * incident engine and the notification severity ranking.
 *
 * Note on the baseline: the FIRST successful check becomes the website baseline
 * (ADR-020, DATABASE §3.11). Redirect rules compare against that baseline, so
 * these integration tests establish a clean baseline on the monitored host
 * before injecting the behaviour under test.
 */
final class RedirectAndProbeDataFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DetectionRuleSeeder::class);
        // Controllable clock (DETECTION-RULES §11): relative certificate dates
        // and the per-minute `check_key` must be deterministic.
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    }

    private function website(array $overrides = []): Website
    {
        return Website::create(array_merge([
            'name' => 'Example',
            'url' => 'https://public.example.test/',
            'scheme' => 'https',
            'host' => 'public.example.test',
            'is_active' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
            'follow_redirects' => true,
            'monitor_ssl' => true,
            'monitor_redirects' => true,
            'monitor_content' => true,
            'monitor_security' => true,
        ], $overrides));
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

    private static function rulesOf(Check $check): array
    {
        return array_keys($check->triggered_rules ?? []);
    }

    private function cleanHtml(): string
    {
        return '<html><head><title>Example</title></head><body>'
            .str_repeat('<p>ordinary legitimate site copy</p>', 40)
            .'</body></html>';
    }

    /* ------------------------------------------------------------------ */
    /* Defect 1 regression: Probe must record the hop destination */
    /* ------------------------------------------------------------------ */

    public function test_probe_records_redirect_hop_destination(): void
    {
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);

        Http::fake([
            'https://public.example.test/*' => Http::response('', 301, ['Location' => 'https://landing.example.test/']),
            'https://landing.example.test/*' => Http::response($this->cleanHtml(), 200, ['Content-Type' => 'text/html']),
        ]);

        $website = $this->website();
        $this->runJob($website);

        $check = Check::firstOrFail();

        $this->assertSame('UP', $check->availability_state);
        $this->assertIsArray($check->redirect_chain);
        $this->assertNotEmpty($check->redirect_chain, 'A followed redirect must be recorded in checks.redirect_chain.');

        $hop = $check->redirect_chain[0];
        // Canonical hop shape (DETECTION-RULES §8.3): status + from + to.
        $this->assertArrayHasKey('to_url', $hop, 'Each persisted hop must carry its destination URL.');
        $this->assertSame('https://landing.example.test/', $hop['to_url']);
        $this->assertSame(301, $hop['status']);
    }

    /* ------------------------------------------------------------------ */
    /* RED-005 https -> http downgrade on a real check */
    /* ------------------------------------------------------------------ */

    public function test_https_to_http_downgrade_fires_through_a_real_check(): void
    {
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);

        // 1st public request = clean baseline; 2nd = 301 downgrade.
        $calls = 0;
        Http::fake([
            'https://public.example.test/*' => function () use (&$calls) {
                $calls++;

                return $calls === 1
                    ? Http::response($this->cleanHtml(), 200, ['Content-Type' => 'text/html'])
                    : Http::response('', 301, ['Location' => 'http://public.example.test/']);
            },
            'http://public.example.test/*' => Http::response($this->cleanHtml(), 200, ['Content-Type' => 'text/html']),
        ]);

        $website = $this->website();
        $this->runJob($website); // baseline
        $this->travelTo(Carbon::parse('2026-09-30 12:05:00'));
        $this->runJob($website->fresh()); // downgrade

        $check = Check::where('website_id', $website->id)->orderByDesc('id')->firstOrFail();
        $rules = self::rulesOf($check);

        $this->assertContains('RULE-RED-005', $rules, 'HTTPS->HTTP downgrade must be detected from the persisted chain. Rules: '.implode(',', $rules));
        // The downgrade stays on the monitored domain, so it is an *expected*
        // redirect and RULE-RED-001 (unexpected redirect) does not fire.
        $this->assertNotContains('RULE-RED-001', $rules);
    }

    /* ------------------------------------------------------------------ */
    /* RED-003 redirect to a suspicious/external target on a real check */
    /* ------------------------------------------------------------------ */

    public function test_redirect_to_suspicious_external_domain_fires_through_a_real_check(): void
    {
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);

        // 1st public request = clean baseline; 2nd = 302 to a suspicious host.
        $calls = 0;
        Http::fake([
            'https://public.example.test/*' => function () use (&$calls) {
                $calls++;

                return $calls === 1
                    ? Http::response($this->cleanHtml(), 200, ['Content-Type' => 'text/html'])
                    : Http::response('', 302, ['Location' => 'https://parked.top/']);
            },
            'https://parked.top/*' => Http::response($this->cleanHtml(), 200, ['Content-Type' => 'text/html']),
        ]);

        $website = $this->website();
        $this->runJob($website); // baseline
        $this->travelTo(Carbon::parse('2026-09-30 12:05:00'));
        $this->runJob($website->fresh()); // injected redirect

        $check = Check::where('website_id', $website->id)->orderByDesc('id')->firstOrFail();
        $rules = self::rulesOf($check);

        $this->assertContains('RULE-RED-003', $rules, 'Redirect to a suspicious TLD must fire without an explicit override. Rules: '.implode(',', $rules));
        $this->assertContains('RULE-RED-002', $rules, 'Final URL on a different registrable domain must fire.');
        $this->assertContains('RULE-RED-001', $rules, 'A redirect to an unexpected host must fire.');
    }

    /* ------------------------------------------------------------------ */
    /* Probe failure taxonomy -> availability rules (data flow) */
    /* ------------------------------------------------------------------ */

    public function test_connection_failure_fires_av_005_and_persists_null_status(): void
    {
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);
        Http::fake(['https://public.example.test/*' => Http::failedConnection('Connection refused (cURL error 7)')]);

        $website = $this->website();
        $this->runJob($website);

        $check = Check::firstOrFail();

        $this->assertSame('DOWN', $check->availability_state);
        $this->assertNull($check->http_status, 'A connection failure must not fabricate an HTTP status.');
        $this->assertSame('connection_refused', $check->error_type);
        $this->assertContains('RULE-AV-005', self::rulesOf($check));
    }

    public function test_timeout_fires_av_003(): void
    {
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);
        Http::fake(['https://public.example.test/*' => function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        $website = $this->website();
        $this->runJob($website);

        $check = Check::firstOrFail();

        $this->assertSame('DOWN', $check->availability_state);
        $this->assertSame('timeout', $check->error_type);
        $this->assertContains('RULE-AV-003', self::rulesOf($check));
    }

    public function test_dns_failure_fires_av_004(): void
    {
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);
        Http::fake(['https://public.example.test/*' => function (): void {
            throw new ConnectionException('cURL error 6: Could not resolve host');
        }]);

        $website = $this->website();
        $this->runJob($website);

        $check = Check::firstOrFail();

        $this->assertSame('dns_failure', $check->error_type);
        $this->assertContains('RULE-AV-004', self::rulesOf($check));
    }

    /* ------------------------------------------------------------------ */
    /* Content change on a real second check */
    /* ------------------------------------------------------------------ */

    public function test_second_check_content_change_fires_cnt_001(): void
    {
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);

        $website = $this->website();

        // Ordered fake responses: the first check establishes the baseline, the
        // second (distinct check_key) serves changed content.
        Http::fakeSequence('https://public.example.test/*')
            ->push($this->cleanHtml(), 200, ['Content-Type' => 'text/html'])
            ->push(
                '<html><head><title>Example</title></head><body>'
                .str_repeat('<p>DIFFERENT copy now</p>', 40)
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );

        $this->runJob($website); // baseline
        $this->travelTo(Carbon::parse('2026-09-30 12:05:00'));
        $this->runJob($website->fresh()); // changed content

        $check = Check::where('website_id', $website->id)->orderByDesc('id')->firstOrFail();
        $rules = self::rulesOf($check);

        $baselineHash = $website->fresh()->currentBaseline?->content_hash;
        $this->assertNotSame($baselineHash, $check->content_hash, 'The second check must serve changed content.');
        $this->assertContains('RULE-CNT-001', $rules, 'Content hash drift vs the baseline must fire. Rules: '.implode(',', $rules));
    }

    /* ------------------------------------------------------------------ */
    /* Severity consistency across registry / incidents / notifications */
    /* ------------------------------------------------------------------ */

    public function test_seeder_severity_matches_the_canonical_catalogue(): void
    {
        // Authoritative values transcribed from DETECTION-RULES.md §8.
        $canonical = [
            'RULE-AV-001' => 'WARNING', 'RULE-AV-002' => 'INFO', 'RULE-AV-003' => 'WARNING',
            'RULE-AV-004' => 'WARNING', 'RULE-AV-005' => 'WARNING', 'RULE-AV-006' => 'INFO',
            'RULE-SSL-001' => 'WARNING', 'RULE-SSL-002' => 'WARNING', 'RULE-SSL-003' => 'INFO',
            'RULE-SSL-004' => 'INFO', 'RULE-SSL-005' => 'INFO',
            'RULE-RED-001' => 'WARNING', 'RULE-RED-002' => 'INFO', 'RULE-RED-003' => 'WARNING',
            'RULE-RED-004' => 'INFO', 'RULE-RED-005' => 'WARNING', 'RULE-RED-006' => 'WARNING',
            'RULE-CNT-001' => 'INFO', 'RULE-CNT-002' => 'WARNING', 'RULE-CNT-003' => 'INFO',
            'RULE-CNT-004' => 'WARNING', 'RULE-CNT-005' => 'WARNING',
            'RULE-KW-001' => 'INFO', 'RULE-KW-002' => 'WARNING', 'RULE-KW-003' => 'INFO',
            'RULE-KW-004' => 'WARNING', 'RULE-KW-005' => 'WARNING',
            'RULE-LNK-001' => 'INFO', 'RULE-LNK-002' => 'INFO', 'RULE-LNK-003' => 'WARNING',
            'RULE-LNK-004' => 'WARNING', 'RULE-LNK-005' => 'WARNING',
            'RULE-SEO-001' => 'WARNING', 'RULE-SEO-002' => 'WARNING', 'RULE-SEO-003' => 'INFO',
            'RULE-SEO-004' => 'WARNING',
        ];

        $seeded = DetectionRule::pluck('severity', 'rule_id')->all();

        $this->assertSame(count($canonical), count($seeded), 'Registry size must match the catalogued rule count.');
        foreach ($canonical as $ruleId => $severity) {
            $this->assertArrayHasKey($ruleId, $seeded, "Rule [{$ruleId}] is missing from the registry.");
            $this->assertSame($severity, $seeded[$ruleId], "Rule [{$ruleId}] severity drifted from the catalogue.");
        }
    }

    public function test_security_state_maps_to_incident_severity_band(): void
    {
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);

        // Tier-1 spam content across several categories -> SUSPECT or INCIDENT.
        Http::fake([
            'https://public.example.test/*' => Http::response(
                '<html><head><title>Situs Slot Gacor Maxwin</title></head><body>'
                .str_repeat('<p>maxwin togel</p>', 80)
                .'<div style="display:none">togel maxwin</div></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $website = $this->website();
        $this->runJob($website);

        $check = Check::orderByDesc('id')->firstOrFail();

        $incident = Incident::where('website_id', $website->id)->where('type', 'security')->first();

        // SUSPECT opens a WARNING security incident; INCIDENT opens CRITICAL
        // (IncidentEngine::severityFor); OK/INFO never open one.
        match ($check->security_state) {
            'INCIDENT' => $this->assertSame('CRITICAL', $incident?->severity, 'INCIDENT must map to a CRITICAL security incident.'),
            'SUSPECT' => $this->assertSame('WARNING', $incident?->severity, 'SUSPECT must map to a WARNING security incident.'),
            default => $this->assertNull($incident, 'OK/INFO must not open a security incident.'),
        };
    }
}
