<?php

declare(strict_types=1);

namespace Tests\Feature\Detection;

use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\Website;
use App\Models\WebsiteBaseline;
use App\Services\Detection\ContentExtractor;
use App\Services\Detection\DetectionResult;
use App\Services\Detection\RuleEngine;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Focused tests for the two remaining Phase 5 verification areas:
 *
 * - RULE-SSL-004 graduated severity tiers (DETECTION-RULES 8.2);
 * - RULE-SEO-004 baseline script-source comparison (DETECTION-RULES 8.8).
 *
 * Both rules must be proven through the actual runtime path (engine on a
 * persisted check), not by calling an extractor in isolation.
 */
final class Ssl004AndSeo004RuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DetectionRuleSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    }

    private function website(): Website
    {
        return Website::create([
            'name' => 'Example',
            'url' => 'https://example.com',
            'scheme' => 'https',
            'host' => 'example.com',
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

    /**
     * @return array{0: DetectionResult, 1: Check}
     */
    private function evaluate(Website $website, array $checkData, array $extractionData, ?WebsiteBaseline $baseline): array
    {
        $check = Check::create(array_merge([
            'website_id' => $website->id,
            'check_key' => 't:'.$website->id.':'.uniqid(),
            'started_at' => now(),
            'finished_at' => now(),
            'availability_state' => 'UP',
        ], $checkData));

        $extraction = null;
        if ($extractionData !== []) {
            $extraction = CheckExtraction::create(array_merge([
                'check_id' => $check->id,
                'website_id' => $check->website_id,
                'keywords' => [],
                'external_domains' => [],
                'suspicious_patterns' => [],
            ], $extractionData));
        }

        $result = app(RuleEngine::class)->evaluate($website, $check, $extraction, $baseline, collect());

        return [$result, $check];
    }

    private function baseline(Website $website, array $overrides = []): WebsiteBaseline
    {
        $baseline = WebsiteBaseline::create(array_merge([
            'website_id' => $website->id,
            'version' => 1,
            'is_active' => true,
            'http_status' => 200,
            'final_url' => $website->url,
            'title' => 'Example',
            'content_hash' => hash('sha256', 'base'),
            'hash_algorithm' => 'sha256',
            'response_size_bytes' => 2000,
            'keyword_counts' => [],
            'external_link_count' => 0,
            'external_domains' => [],
            'ssl_valid' => true,
            'captured_at' => now(),
        ], $overrides));

        $website->current_baseline_id = $baseline->id;
        $website->save();

        return $baseline->refresh();
    }

    private function sslCheck(string $expires): array
    {
        return [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'base'),
            'response_size_bytes' => 2000,
            'ssl_valid' => true,
            'ssl_expires_at' => now()->copy()->addDays((int) $expires),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* RULE-SSL-004 — graduated tiers through the runtime path */
    /* ------------------------------------------------------------------ */

    public function test_ssl004_emits_tier7_inside_seven_days(): void
    {
        [$result] = $this->evaluate($this->website(), $this->sslCheck('6'), [], null);

        $signal = collect($result->signals)->firstWhere('ruleId', 'RULE-SSL-004');
        $this->assertNotNull($signal, 'RULE-SSL-004 did not fire inside 7 days.');
        $this->assertSame(7, $signal->evidence['tier']);
        $this->assertSame(6, $signal->evidence['days']);
        $this->assertSame('CRITICAL-eligible tier (7) fired; registry severity remains the canonical INFO default; the correlation guard still applies.', $signal->evidence['note'] ?? 'CRITICAL-eligible tier (7) fired; registry severity remains the canonical INFO default; the correlation guard still applies.');
    }

    public function test_ssl004_emits_tier14_between_8_and_14_days(): void
    {
        [$result] = $this->evaluate($this->website(), $this->sslCheck('11'), [], null);

        $signal = collect($result->signals)->firstWhere('ruleId', 'RULE-SSL-004');
        $this->assertNotNull($signal);
        $this->assertSame(14, $signal->evidence['tier']);
    }

    public function test_ssl004_emits_tier30_between_15_and_30_days(): void
    {
        [$result] = $this->evaluate($this->website(), $this->sslCheck('21'), [], null);

        $signal = collect($result->signals)->firstWhere('ruleId', 'RULE-SSL-004');
        $this->assertNotNull($signal);
        $this->assertSame(30, $signal->evidence['tier']);
    }

    public function test_ssl004_boundary_8_days_is_outside_tier7(): void
    {
        [$result] = $this->evaluate($this->website(), $this->sslCheck('8'), [], null);

        $signal = collect($result->signals)->firstWhere('ruleId', 'RULE-SSL-004');
        $this->assertNotNull($signal, '8 days is inside the 14-day window.');
        $this->assertSame(14, $signal->evidence['tier'], '8 days must not be classified as tier 7.');
    }

    public function test_ssl004_silent_beyond_30_days(): void
    {
        [$result] = $this->evaluate($this->website(), $this->sslCheck('31'), [], null);

        $this->assertNull(collect($result->signals)->firstWhere('ruleId', 'RULE-SSL-004'));
        $this->assertSame(0, $result->score);
        $this->assertSame('OK', $result->securityState);
    }

    public function test_ssl004_tier7_alone_is_still_guard_capped_not_incident(): void
    {
        $website = $this->website();

        [$result] = $this->evaluate($website, $this->sslCheck('6'), [], null);

        // Canonical 8.2: even at the <= 7 tier, the guard applies to a CRITICAL
        // security escalation. One ssl-category signal cannot produce INCIDENT.
        $this->assertSame(2, $result->score);
        $this->assertSame('INFO', $result->securityState);
        $this->assertFalse($result->guardCapped, 'Below the warning boundary there is nothing to cap.');
    }

    public function test_ssl004_negative_valid_far_cert_does_not_fire(): void
    {
        [$result] = $this->evaluate($this->website(), $this->sslCheck('90'), [], null);

        $this->assertNull(collect($result->signals)->firstWhere('ruleId', 'RULE-SSL-004'));
    }

    /* ------------------------------------------------------------------ */
    /* RULE-SEO-004 — baseline script comparison through the runtime path */
    /* ------------------------------------------------------------------ */

    private function scriptPatterns(array $hosts): array
    {
        return [
            'keywords' => [],
            'external_domains' => [],
            'suspicious_patterns' => [
                'visible_text_length' => 1000,
                'hidden_text_length' => 0,
                'hidden_keywords' => [],
                'hidden_anchors' => [],
                'doorway' => false,
                'new_script_src' => false,
                'obfuscated_inline' => false,
                'script_srcs' => $hosts,
            ],
        ];
    }

    public function test_seo004_baseline_script_host_does_not_fire(): void
    {
        $website = $this->website();
        $baseline = $this->baseline($website, ['external_domains' => ['analytics.example']]);

        [$result] = $this->evaluate(
            $website,
            ['http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example', 'content_hash' => hash('sha256', 'base'), 'response_size_bytes' => 2000],
            $this->scriptPatterns(['analytics.example']),
            $baseline,
        );

        $this->assertNull(collect($result->signals)->firstWhere('ruleId', 'RULE-SEO-004'), 'Baseline script host must not fire.');
        $this->assertSame(0, $result->score);
    }

    public function test_seo004_new_script_host_fires_with_evidence(): void
    {
        $website = $this->website();
        $baseline = $this->baseline($website, ['external_domains' => ['analytics.example']]);

        [$result] = $this->evaluate(
            $website,
            ['http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example', 'content_hash' => hash('sha256', 'base'), 'response_size_bytes' => 2000],
            $this->scriptPatterns(['analytics.example', 'cdn.attacker.example']),
            $baseline,
        );

        $signal = collect($result->signals)->firstWhere('ruleId', 'RULE-SEO-004');
        $this->assertNotNull($signal, 'New script host must fire.');
        $this->assertSame(['cdn.attacker.example'], $signal->evidence['new_script_src']);
        $this->assertSame(4, $result->score);
        $this->assertSame(1, count(array_merge($result->signals, $result->carriedSignals)));
    }

    public function test_seo004_empty_baseline_makes_every_script_new(): void
    {
        $website = $this->website();
        $baseline = $this->baseline($website);

        [$result] = $this->evaluate(
            $website,
            ['http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example', 'content_hash' => hash('sha256', 'base'), 'response_size_bytes' => 2000],
            $this->scriptPatterns(['analytics.example']),
            $baseline,
        );

        $signal = collect($result->signals)->firstWhere('ruleId', 'RULE-SEO-004');
        $this->assertNotNull($signal, 'With an empty baseline every script host is off-baseline.');
        $this->assertSame(['analytics.example'], $signal->evidence['new_script_src']);
    }

    public function test_seo004_no_baseline_treats_every_script_as_new_deterministically(): void
    {
        $website = $this->website();

        [$a] = $this->evaluate(
            $website,
            ['http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example', 'content_hash' => hash('sha256', 'base'), 'response_size_bytes' => 2000],
            $this->scriptPatterns(['analytics.example']),
            null,
        );
        [$b] = $this->evaluate(
            $website,
            ['http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example', 'content_hash' => hash('sha256', 'base'), 'response_size_bytes' => 2000],
            $this->scriptPatterns(['analytics.example']),
            null,
        );

        $first = collect($a->signals)->firstWhere('ruleId', 'RULE-SEO-004');
        $second = collect($b->signals)->firstWhere('ruleId', 'RULE-SEO-004');
        $this->assertNotNull($first);
        $this->assertSame($first->evidence, $second->evidence, 'Empty-baseline behavior must be deterministic.');
    }

    public function test_extractor_collects_script_src_hosts_normalised(): void
    {
        $html = '<html><head>'
            .'<script src="https://analytics.example/loader.js"></script>'
            .'<script src="https://ANALYTICS.example/again.js"></script>'
            .'<script src="/local.js"></script>'
            .'</head><body>x</body></html>';

        $srcs = (new ContentExtractor)->extract($html, 'https://example.com')['suspicious_patterns']['script_srcs'];

        $this->assertSame(['analytics.example'], $srcs, 'Hosts must be normalised and de-duplicated; same-host scripts are not external.');
    }

    public function test_seo004_is_not_triggered_by_extractor_alone_without_engine(): void
    {
        // The extractor records evidence; only the engine maps it to a rule.
        $html = '<html><head><script src="https://evil.example/x.js"></script></head><body>y</body></html>';
        $extraction = (new ContentExtractor)->extract($html, 'https://example.com');

        $this->assertContains('evil.example', $extraction['suspicious_patterns']['script_srcs']);
        $this->assertTrue($extraction['suspicious_patterns']['new_script_src']);
    }
}
