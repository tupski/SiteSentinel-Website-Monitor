<?php

declare(strict_types=1);

namespace Tests\Feature\Detection;

use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\DetectionRule;
use App\Models\Website;
use App\Models\WebsiteRuleSetting;
use App\Services\Detection\DetectionResult;
use App\Services\Detection\RuleEngine;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-website `threshold_override` behaviour (FR-46, DETECTION-RULES 6.9).
 *
 * The override moves the classification band boundaries for one website. It must
 * never bypass the correlation guard.
 */
final class ThresholdOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DetectionRuleSeeder::class);
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

    /** A check whose only signal is RULE-AV-002 (4 x 1.5 = 6). */
    private function evaluateScoreSix(Website $website): DetectionResult
    {
        $check = Check::create([
            'website_id' => $website->id,
            'check_key' => 'threshold:'.$website->id.':'.uniqid(),
            'started_at' => now(),
            'finished_at' => now(),
            'http_status' => 404,
            'availability_state' => 'DOWN',
        ]);

        return app(RuleEngine::class)->evaluate($website, $check, null, null, collect());
    }

    public function test_default_boundaries_are_canonical_when_no_override_exists(): void
    {
        $result = $this->evaluateScoreSix($this->website());

        // 6 >= info(1) but < warning(8)
        $this->assertSame(6, $result->score);
        $this->assertSame('INFO', $result->securityState);
    }

    public function test_lowering_the_override_promotes_a_score_into_suspect(): void
    {
        $website = $this->website();
        WebsiteRuleSetting::create([
            'website_id' => $website->id,
            'detection_rule_id' => DetectionRule::where('rule_id', 'RULE-AV-002')->value('id'),
            'threshold_override' => 5,
        ]);

        $result = $this->evaluateScoreSix($website);

        // warning boundary 8 -> 5, so score 6 now clears SUSPECT.
        $this->assertSame(6, $result->score);
        $this->assertSame('SUSPECT', $result->securityState);
        $this->assertFalse($result->guardCapped, 'A single category is not guard-capped below INCIDENT.');
    }

    public function test_raising_the_override_demotes_a_score_back_to_ok(): void
    {
        $website = $this->website();
        WebsiteRuleSetting::create([
            'website_id' => $website->id,
            'detection_rule_id' => DetectionRule::where('rule_id', 'RULE-AV-002')->value('id'),
            'threshold_override' => 20,
        ]);

        $result = $this->evaluateScoreSix($website);

        // info 1 -> 13 and warning 8 -> 20; score 6 no longer clears info.
        $this->assertSame(6, $result->score);
        $this->assertSame('OK', $result->securityState);
    }

    public function test_override_never_bypasses_the_correlation_guard(): void
    {
        $website = $this->website();

        // Force a single-category score well past the critical boundary.
        WebsiteRuleSetting::create([
            'website_id' => $website->id,
            'detection_rule_id' => DetectionRule::where('rule_id', 'RULE-KW-004')->value('id'),
            'weight_override' => 100,
            'threshold_override' => 1,
        ]);

        $check = Check::create([
            'website_id' => $website->id,
            'check_key' => 'threshold-guard:'.$website->id,
            'started_at' => now(),
            'finished_at' => now(),
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Maxwin Maxwin',
            'content_hash' => hash('sha256', 'x'),
            'response_size_bytes' => 2000,
            'availability_state' => 'UP',
        ]);
        $extraction = CheckExtraction::create([
            'check_id' => $check->id,
            'website_id' => $website->id,
            'keywords' => ['maxwin' => 2],
            'external_domains' => [],
            'suspicious_patterns' => ['visible_text_length' => 1000],
        ]);

        $result = app(RuleEngine::class)->evaluate($website, $check, $extraction, null, collect());

        $this->assertGreaterThanOrEqual(15, $result->score);
        $this->assertSame('SUSPECT', $result->securityState);
        $this->assertTrue($result->guardCapped, 'threshold_override must not bypass the guard.');
    }

    public function test_null_and_non_positive_overrides_fall_back_to_canonical_defaults(): void
    {
        $website = $this->website();
        $ruleId = DetectionRule::where('rule_id', 'RULE-AV-002')->value('id');

        foreach ([null, 0, -5] as $value) {
            WebsiteRuleSetting::where('website_id', $website->id)->delete();
            WebsiteRuleSetting::create([
                'website_id' => $website->id,
                'detection_rule_id' => $ruleId,
                'threshold_override' => $value,
            ]);

            $result = $this->evaluateScoreSix($website);

            $this->assertSame(
                'INFO',
                $result->securityState,
                'Override value '.var_export($value, true).' must not change the canonical result.',
            );
        }
    }

    public function test_override_is_scoped_to_its_own_website(): void
    {
        $withOverride = $this->website();
        WebsiteRuleSetting::create([
            'website_id' => $withOverride->id,
            'detection_rule_id' => DetectionRule::where('rule_id', 'RULE-AV-002')->value('id'),
            'threshold_override' => 5,
        ]);

        $other = Website::create([
            'name' => 'Other',
            'url' => 'https://other.example.com',
            'scheme' => 'https',
            'host' => 'other.example.com',
            'is_active' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
            'monitor_ssl' => true,
            'monitor_redirects' => true,
            'monitor_content' => true,
            'monitor_security' => true,
        ]);

        $scoped = $this->evaluateScoreSix($withOverride);
        $unscoped = $this->evaluateScoreSix($other);

        $this->assertSame('SUSPECT', $scoped->securityState, 'The overridden website must use the lowered boundary.');
        $this->assertSame('INFO', $unscoped->securityState, 'Another website must keep the canonical boundary.');
        $this->assertSame($scoped->score, $unscoped->score, 'Both scores are identical; only the boundary differs.');
    }
}
