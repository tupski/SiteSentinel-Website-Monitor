<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Detection;

use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\DetectionRule;
use App\Models\Website;
use App\Models\WebsiteBaseline;
use App\Models\WebsiteRuleSetting;
use App\Services\Detection\RuleEngine;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RuleEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DetectionRuleSeeder::class);
    }

    private function engine(): RuleEngine
    {
        return app(RuleEngine::class);
    }

    private function website(array $overrides = []): Website
    {
        return Website::create(array_merge([
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
        ], $overrides));
    }

    private function check(Website $website, array $attributes): Check
    {
        return Check::create(array_merge([
            'website_id' => $website->id,
            'check_key' => 'test:'.$website->id.':'.uniqid(),
            'started_at' => now(),
            'finished_at' => now(),
            'availability_state' => 'UP',
        ], $attributes));
    }

    private function extraction(Check $check, array $data = []): ?CheckExtraction
    {
        if ($data === []) {
            return null;
        }

        return CheckExtraction::create(array_merge([
            'check_id' => $check->id,
            'website_id' => $check->website_id,
            'keywords' => [],
            'external_domains' => [],
            'suspicious_patterns' => [],
        ], $data));
    }

    public function test_clean_check_returns_security_ok(): void
    {
        $website = $this->website();
        $baseline = WebsiteBaseline::create([
            'website_id' => $website->id,
            'version' => 1,
            'is_active' => true,
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'body'),
            'hash_algorithm' => 'sha256',
            'captured_at' => now(),
        ]);
        $website->current_baseline_id = $baseline->id;
        $website->save();

        $check = $this->check($website, [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'body'),
            'availability_state' => 'UP',
        ]);

        $result = $this->engine()->evaluate($website, $check, null, $baseline, collect());

        $this->assertSame('OK', $result->securityState);
        $this->assertSame(0, $result->score);
    }

    public function test_content_hash_drift_produces_info(): void
    {
        $website = $this->website();
        $baseline = WebsiteBaseline::create([
            'website_id' => $website->id,
            'version' => 1,
            'is_active' => true,
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'body'),
            'hash_algorithm' => 'sha256',
            'captured_at' => now(),
        ]);
        $website->current_baseline_id = $baseline->id;
        $website->save();

        $check = $this->check($website, [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'changed'),
            'availability_state' => 'UP',
        ]);

        $result = $this->engine()->evaluate($website, $check, null, $baseline, collect());

        $this->assertSame('INFO', $result->securityState);
        $this->assertSame(1, $result->score);
    }

    public function test_single_heavy_signal_is_guard_capped(): void
    {
        $website = $this->website();
        $rule = DetectionRule::where('rule_id', 'RULE-CNT-002')->first();
        WebsiteRuleSetting::create([
            'website_id' => $website->id,
            'detection_rule_id' => $rule->id,
            'weight_override' => 40,
        ]);

        $baseline = WebsiteBaseline::create([
            'website_id' => $website->id,
            'version' => 1,
            'is_active' => true,
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'body'),
            'hash_algorithm' => 'sha256',
            'captured_at' => now(),
        ]);
        $website->current_baseline_id = $baseline->id;
        $website->save();

        $check = $this->check($website, [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Situs Slot Gacor Maxwin Terpercaya',
            'content_hash' => hash('sha256', 'changed'),
            'availability_state' => 'UP',
        ]);

        $result = $this->engine()->evaluate($website, $check, null, $baseline, collect());

        $this->assertSame('SUSPECT', $result->securityState);
        $this->assertTrue($result->guardCapped);
    }

    public function test_cross_category_incident(): void
    {
        $website = $this->website();
        $baseline = WebsiteBaseline::create([
            'website_id' => $website->id,
            'version' => 1,
            'is_active' => true,
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'body'),
            'hash_algorithm' => 'sha256',
            'external_link_count' => 2,
            'external_domains' => ['example.org', 'example.net'],
            'captured_at' => now(),
        ]);
        $website->current_baseline_id = $baseline->id;
        $website->save();

        $check = $this->check($website, [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Situs Slot Gacor Maxwin Terpercaya',
            'content_hash' => hash('sha256', 'changed'),
            'availability_state' => 'UP',
        ]);
        $extraction = $this->extraction($check, [
            'external_domains' => ['example.org', 'example.net', 'situs-judi.example'],
        ]);

        $result = $this->engine()->evaluate($website, $check, $extraction, $baseline, collect());

        $this->assertSame('INCIDENT', $result->securityState);
        $this->assertFalse($result->guardCapped);
        $this->assertGreaterThanOrEqual(15, $result->score);
    }

    public function test_down_check_skips_content_rules(): void
    {
        $website = $this->website();
        $baseline = WebsiteBaseline::create([
            'website_id' => $website->id,
            'version' => 1,
            'is_active' => true,
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'body'),
            'hash_algorithm' => 'sha256',
            'captured_at' => now(),
        ]);
        $website->current_baseline_id = $baseline->id;
        $website->save();

        $check = $this->check($website, [
            'http_status' => null,
            'error_type' => 'timeout',
            'availability_state' => 'DOWN',
        ]);

        $extraction = $this->extraction($check, [
            'keywords' => ['maxwin', 'togel'],
            'external_domains' => ['evil.example'],
        ]);

        $result = $this->engine()->evaluate($website, $check, $extraction, $baseline, collect());

        $this->assertTrue(in_array('availability', array_map(fn ($s) => $s->category, $result->signals), true));
        $this->assertFalse(in_array('content-keyword', array_map(fn ($s) => $s->category, $result->signals), true));
    }

    public function test_ignored_keyword_suppresses_signal(): void
    {
        $website = $this->website();
        WebsiteRuleSetting::create([
            'website_id' => $website->id,
            'detection_rule_id' => DetectionRule::where('rule_id', 'RULE-KW-001')->value('id'),
            'ignored_keywords' => ['maxwin', 'togel'],
        ]);

        $baseline = WebsiteBaseline::create([
            'website_id' => $website->id,
            'version' => 1,
            'is_active' => true,
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'body'),
            'hash_algorithm' => 'sha256',
            'captured_at' => now(),
        ]);
        $website->current_baseline_id = $baseline->id;
        $website->save();

        $check = $this->check($website, [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'body'),
            'availability_state' => 'UP',
        ]);
        $extraction = $this->extraction($check, [
            'keywords' => ['maxwin', 'togel'],
        ]);

        $result = $this->engine()->evaluate($website, $check, $extraction, $baseline, collect());

        $this->assertSame('OK', $result->securityState);
    }
}
