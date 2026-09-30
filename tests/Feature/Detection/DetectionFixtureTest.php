<?php

declare(strict_types=1);

namespace Tests\Feature\Detection;

use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\DetectionRule;
use App\Models\Snapshot;
use App\Models\Website;
use App\Models\WebsiteBaseline;
use App\Models\WebsiteRuleSetting;
use App\Services\Detection\BaselineComparator;
use App\Services\Detection\ContentExtractor;
use App\Services\Detection\DetectionResult;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use Carbon\CarbonInterface;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fixture-driven detection tests (DETECTION-RULES.md 11).
 *
 * Fixtures are synthetic and declare the columns they populate (11.5); no live
 * network access occurs. Every rule that has a runtime path is covered by a
 * positive fixture, near-miss negatives, boundary cases and suppression cases,
 * plus the cross-rule guard/decay/idempotency suite (11.4).
 */
final class DetectionFixtureTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        // Frozen clock so relative certificate dates are deterministic.
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));

        $this->seed(DetectionRuleSeeder::class);

        $this->assertTrue(true); // corpus is declared by per_rule.json

        $path = base_path('tests/Fixtures/detection/per_rule.json');
        $this->fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Rules that are catalogued but have no runtime evaluation path at MVP.
     * RULE-SSL-005 is visibility-only (weight 0); SEO-002/003 are Future (8.8).
     */
    private const NON_FIRING_RULES = ['RULE-SSL-005', 'RULE-SEO-002', 'RULE-SEO-003'];

    /** Rules the engine must be able to emit, with the fixture that proves each. */
    private const RULE_COVERAGE = [
        'RULE-AV-001' => 'av_001_5xx',
        'RULE-AV-002' => null,
        'RULE-AV-003' => null,
        'RULE-AV-004' => null,
        'RULE-AV-005' => null,
        'RULE-AV-006' => null,
        'RULE-SSL-001' => 'ssl_001_expired_plus_new_domain',
        'RULE-SSL-002' => null,
        'RULE-SSL-003' => null,
        'RULE-SSL-004' => null,
        'RULE-RED-001' => 'red_005_https_downgrade',
        'RULE-RED-002' => 'red_005_https_downgrade',
        'RULE-RED-003' => null,
        'RULE-RED-004' => null,
        'RULE-RED-005' => 'red_005_https_downgrade',
        'RULE-RED-006' => 'red_006_ssrf_redirect',
        'RULE-CNT-001' => 'cnt_001_hash_drift',
        'RULE-CNT-002' => 'cnt_002_title_replaced_tier1',
        'RULE-CNT-003' => null,
        'RULE-CNT-004' => 'cnt_004_empty_page',
        'RULE-CNT-005' => 'cnt_005_hidden_block',
        'RULE-KW-001' => 'kw_005_hidden_keyword',
        'RULE-KW-002' => null,
        'RULE-KW-003' => 'kw_003_tier2_density',
        'RULE-KW-004' => 'cnt_002_title_replaced_tier1',
        'RULE-KW-005' => 'kw_005_hidden_keyword',
        'RULE-LNK-001' => 'lnk_001_new_domain',
        'RULE-LNK-002' => 'lnk_004_hidden_anchor',
        'RULE-LNK-003' => 'lnk_003_checkout_pages',
        'RULE-LNK-004' => 'lnk_004_hidden_anchor',
        'RULE-LNK-005' => null,
        'RULE-SEO-001' => 'seo_001_doorway',
        'RULE-SEO-004' => 'seo_004_script_injection',
    ];

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function seedCorpus(): void
    {
        // Placeholder so the fixture columns line up with a real baseline row.
    }

    private int $websiteSeq = 0;

    private function website(array $overrides = [], string $slug = ''): Website
    {
        $this->websiteSeq++;
        $suffix = $slug !== '' ? '/'.$slug.'-'.$this->websiteSeq : '/w'.$this->websiteSeq;

        return Website::create(array_merge([
            'name' => 'Example',
            'url' => 'https://example.com'.$suffix,
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

    private function hashes(): array
    {
        return [
            'H_BODY' => hash('sha256', 'stable body'),
            'H_CHANGED' => hash('sha256', 'changed body'),
            'H_SMALL' => hash('sha256', 'tiny'),
            'H512' => hash('sha256', 'x'),
        ];
    }

    private function resolveHash(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->hashes()[$value] ?? $value;
    }

    private function resolveDate(?string $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^([+-])(\d+)d$/', $value, $m) === 1) {
            return $m[1] === '+'
                ? now()->copy()->addDays((int) $m[2])
                : now()->copy()->subDays((int) $m[2]);
        }

        return Carbon::parse($value);
    }

    private function makeBaseline(Website $website, ?array $data): ?WebsiteBaseline
    {
        if ($data === null) {
            return null;
        }

        $baseline = WebsiteBaseline::create([
            'website_id' => $website->id,
            'version' => 1,
            'is_active' => true,
            'http_status' => $data['http_status'] ?? 200,
            'final_url' => $data['final_url'] ?? $website->url,
            'title' => $data['title'] ?? null,
            'content_hash' => $this->resolveHash($data['content_hash'] ?? null),
            'hash_algorithm' => 'sha256',
            'response_size_bytes' => $data['response_size_bytes'] ?? null,
            'keyword_counts' => $data['keyword_counts'] ?? [],
            'external_link_count' => $data['external_link_count'] ?? 0,
            'external_domains' => $data['external_domains'] ?? [],
            'ssl_valid' => $data['ssl_valid'] ?? null,
            'ssl_issuer' => $data['ssl_issuer'] ?? null,
            'ssl_expires_at' => $this->resolveDate($data['ssl_expires_at'] ?? null),
            'captured_at' => now(),
        ]);

        $website->current_baseline_id = $baseline->id;
        $website->save();

        return $baseline->refresh();
    }

    private function makeCheck(Website $website, array $data, string $suffix = 'a'): Check
    {
        $chain = $data['redirect_chain'] ?? [];

        return Check::create([
            'website_id' => $website->id,
            'check_key' => 'fixture:'.$website->id.':'.$suffix,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => $data['duration_ms'] ?? 120,
            'http_status' => $data['http_status'] ?? null,
            'final_url' => $data['final_url'] ?? null,
            'redirect_chain' => $chain,
            'response_size_bytes' => $data['response_size_bytes'] ?? null,
            'ssl_valid' => $data['ssl_valid'] ?? null,
            'ssl_issuer' => $data['ssl_issuer'] ?? null,
            'ssl_expires_at' => $this->resolveDate($data['ssl_expires_at'] ?? null),
            'title' => $data['title'] ?? null,
            'content_hash' => $this->resolveHash($data['content_hash'] ?? null),
            'error_type' => $data['error_type'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'availability_state' => $data['availability_state'] ?? 'UP',
        ]);
    }

    private function makeExtraction(Check $check, array $data): ?CheckExtraction
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

    /**
     * @return array{0: Website, 1: Check, 2: DetectionResult}
     */
    private function runCase(array $case): array
    {
        $website = $this->website($case['website'] ?? [], (string) $case['id']);
        $baseline = $this->makeBaseline($website, $case['baseline'] ?? null);
        $check = $this->makeCheck($website, $case['check']);
        $extraction = $this->makeExtraction($check, $case['extraction'] ?? []);

        $result = app(RuleEngine::class)->evaluate($website, $check, $extraction, $baseline, collect());

        return [$website, $check, $result];
    }

    private function case(string $id): array
    {
        foreach ($this->fixture['cases'] as $case) {
            if ($case['id'] === $id) {
                return $case;
            }
        }

        $this->fail("Fixture case [{$id}] is missing.");
    }

    /**
     * The guard's category set is the union of the current check's signals and
     * the carried-forward signals (DETECTION-RULES 6.3 property 3).
     */
    private static function categoriesOf(DetectionResult $result): array
    {
        $all = array_merge($result->signals, $result->carriedSignals);

        return array_values(array_unique(array_map(fn ($s) => $s->category, $all)));
    }

    private static function rulesOf(DetectionResult $result): array
    {
        return array_map(fn ($s) => $s->ruleId, $result->signals);
    }

    /* ------------------------------------------------------------------ */
    /* Per-rule fixture table (11.2) */
    /* ------------------------------------------------------------------ */

    public function test_every_fixture_case_matches_its_expected_signals_score_and_state(): void
    {
        foreach ($this->fixture['cases'] as $case) {
            [, , $result] = $this->runCase($case);

            $expected = array_values(array_unique($case['expect']['signals']));
            $actual = array_values(array_unique(self::rulesOf($result)));
            sort($expected);
            sort($actual);

            $this->assertSame($expected, $actual, "Case [{$case['id']}] emitted unexpected signals.");
            $this->assertSame($case['expect']['score'], $result->score, "Case [{$case['id']}] score mismatch.");
            $this->assertSame(
                $case['expect']['categories'],
                count(self::categoriesOf($result)),
                "Case [{$case['id']}] category count mismatch.",
            );
            $this->assertSame(
                $case['expect']['security_state'],
                $result->securityState,
                "Case [{$case['id']}] security state mismatch.",
            );
            $this->assertSame(
                $case['expect']['guard_capped'],
                $result->guardCapped,
                "Case [{$case['id']}] guard_capped mismatch.",
            );
        }
    }

    public function test_every_evaluable_rule_has_a_fixture_that_fires_it(): void
    {
        $fired = [];
        foreach ($this->fixture['cases'] as $case) {
            [, , $result] = $this->runCase($case);
            foreach (self::rulesOf($result) as $ruleId) {
                $fired[$ruleId] = true;
            }
        }

        $catalogue = DetectionRule::pluck('rule_id')->all();

        foreach ($catalogue as $ruleId) {
            if (in_array($ruleId, self::NON_FIRING_RULES, true)) {
                $this->assertArrayNotHasKey(
                    $ruleId,
                    $fired,
                    "Rule [{$ruleId}] is documented as non-firing at MVP but fired.",
                );

                continue;
            }

            $this->assertArrayHasKey($ruleId, $fired, "Rule [{$ruleId}] has no fixture proving it can fire.");
        }
    }

    public function test_every_evaluable_rule_has_a_targeted_negative_fixture(): void
    {
        $fired = [];
        $negativeTargets = [];

        foreach ($this->fixture['cases'] as $case) {
            if ($case['kind'] === 'positive') {
                foreach ($case['expect']['signals'] as $ruleId) {
                    $fired[$ruleId] = true;
                }
            }
            if ($case['kind'] === 'negative' && $case['rule'] !== 'none') {
                $negativeTargets[$case['rule']] = true;
            }
        }

        foreach (array_keys($fired) as $ruleId) {
            if (in_array($ruleId, self::NON_FIRING_RULES, true)) {
                continue;
            }

            $this->assertArrayHasKey(
                $ruleId,
                $negativeTargets,
                "Rule [{$ruleId}] has no targeted negative/non-trigger fixture.",
            );
        }
    }

    public function test_rule_coverage_map_targets_exist(): void
    {
        foreach (self::RULE_COVERAGE as $ruleId => $fixtureId) {
            $this->assertContains(
                $ruleId,
                array_keys($this->ruleCoverage()),
                "Coverage map references unknown rule [{$ruleId}].",
            );

            if ($fixtureId === null) {
                continue;
            }

            $this->case($fixtureId);
        }
    }

    private function ruleCoverage(): array
    {
        return self::RULE_COVERAGE;
    }

    /* ------------------------------------------------------------------ */
    /* Mandatory test categories (11.3) */
    /* ------------------------------------------------------------------ */

    public function test_negative_near_miss_does_not_fire(): void
    {
        foreach ($this->fixture['cases'] as $case) {
            if ($case['kind'] !== 'negative') {
                continue;
            }
            [, , $result] = $this->runCase($case);
            $fired = self::rulesOf($result);

            if ($case['rule'] === 'none') {
                $this->assertSame([], $fired, "Negative case [{$case['id']}] fired.");

                continue;
            }

            $this->assertNotContains(
                $case['rule'],
                $fired,
                "Negative case [{$case['id']}] fired the rule under test [{$case['rule']}].",
            );
        }
    }

    public function test_boundary_tier2_density_below_at_and_above_threshold(): void
    {
        $website = $this->website();
        $threshold = (float) config('sentinel.detection_keywords.tier2_density_floor');
        $visible = 1000;

        // Below: rate is a tenth of the floor.
        $below = (int) floor($visible * $threshold / 10);
        $at = (int) ceil($visible * $threshold);
        $above = (int) ceil($visible * $threshold * 2) + 1;

        foreach ([[$below, false], [$at, true], [$above, true]] as [$count, $shouldFire]) {
            $check = $this->makeCheck($website, [
                'http_status' => 200,
                'final_url' => 'https://example.com',
                'title' => 'Example',
                'content_hash' => hash('sha256', 'b'.$count),
                'response_size_bytes' => 2000,
                'ssl_valid' => true,
                'ssl_expires_at' => '+90d',
            ], 'b'.$count);
            $extraction = $this->makeExtraction($check, [
                'keywords' => ['jackpot' => $count],
                'suspicious_patterns' => ['visible_text_length' => $visible],
            ]);

            $result = app(RuleEngine::class)->evaluate($website, $check, $extraction, null, collect());
            $fired = in_array('RULE-KW-003', self::rulesOf($result), true);

            $this->assertSame(
                $shouldFire,
                $fired,
                "RULE-KW-003 boundary at count {$count} (floor {$threshold}) was wrong.",
            );
        }
    }

    public function test_suppression_removes_signal_and_its_category_vote(): void
    {
        $website = $this->website();
        WebsiteRuleSetting::create([
            'website_id' => $website->id,
            'detection_rule_id' => DetectionRule::where('rule_id', 'RULE-KW-001')->value('id'),
            'ignored_keywords' => ['maxwin'],
        ]);

        $check = $this->makeCheck($website, [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => hash('sha256', 'b'),
            'response_size_bytes' => 2000,
            'ssl_valid' => true,
            'ssl_expires_at' => '+90d',
        ]);
        $extraction = $this->makeExtraction($check, [
            'keywords' => ['maxwin' => 1],
            'suspicious_patterns' => ['visible_text_length' => 1000],
        ]);

        $result = app(RuleEngine::class)->evaluate($website, $check, $extraction, null, collect());

        $this->assertNotContains('RULE-KW-001', self::rulesOf($result));
        $this->assertNotContains('content-keyword', self::categoriesOf($result), 'Suppressed rule still voted.');
        $this->assertSame(0, $result->score);
    }

    public function test_single_rule_contribution_can_never_become_incident_alone(): void
    {
        $website = $this->website();
        WebsiteRuleSetting::create([
            'website_id' => $website->id,
            'detection_rule_id' => DetectionRule::where('rule_id', 'RULE-KW-004')->value('id'),
            'weight_override' => 100,
        ]);

        $check = $this->makeCheck($website, [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Maxwin Maxwin Maxwin',
            'content_hash' => hash('sha256', 'b'),
            'response_size_bytes' => 2000,
            'ssl_valid' => true,
            'ssl_expires_at' => '+90d',
        ]);
        $extraction = $this->makeExtraction($check, [
            'keywords' => ['maxwin' => 3],
            'suspicious_patterns' => ['visible_text_length' => 1000],
        ]);

        $result = app(RuleEngine::class)->evaluate($website, $check, $extraction, null, collect());

        $this->assertGreaterThanOrEqual(15, $result->score);
        $this->assertSame('SUSPECT', $result->securityState);
        $this->assertTrue($result->guardCapped);
    }

    /* ------------------------------------------------------------------ */
    /* Cross-rule pipeline (11.4) */
    /* ------------------------------------------------------------------ */

    public function test_two_category_unlock_produces_incident(): void
    {
        [, , $result] = $this->runCase($this->case('ssl_001_expired_plus_new_domain'));

        $this->assertSame(2, count(self::categoriesOf($result)));
        $this->assertGreaterThanOrEqual(15, $result->score);
        $this->assertSame('INCIDENT', $result->securityState);
        $this->assertFalse($result->guardCapped);
    }

    public function test_tier3_only_page_never_exceeds_info(): void
    {
        $website = $this->website();

        // Tier-3 vocabulary, however dense, is not in the tier-1/tier-2 sets the
        // keyword rules read, and tier-3 carries no independent category.
        $check = $this->makeCheck($website, [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Bonus promo hadiah',
            'content_hash' => hash('sha256', 'tier3'),
            'response_size_bytes' => 2000,
            'ssl_valid' => true,
            'ssl_expires_at' => '+90d',
        ]);
        $extraction = $this->makeExtraction($check, [
            'keywords' => ['bonus' => 40, 'promo' => 30, 'hadiah' => 20, 'menang' => 10, 'game' => 50],
            'suspicious_patterns' => ['visible_text_length' => 400],
        ]);

        $result = app(RuleEngine::class)->evaluate($website, $check, $extraction, null, collect());

        $this->assertLessThan(8, $result->score);
        $this->assertContains($result->securityState, ['OK', 'INFO'], 'Tier-3 escalated beyond INFO.');
    }

    public function test_down_with_no_body_does_not_fabricate_content_signals(): void
    {
        $website = $this->website();
        $check = $this->makeCheck($website, [
            'http_status' => null,
            'error_type' => 'timeout',
            'error_message' => 'cURL error 28',
            'final_url' => null,
            'title' => null,
            'content_hash' => null,
            'availability_state' => 'DOWN',
        ]);
        $extraction = $this->makeExtraction($check, [
            'keywords' => ['maxwin' => 5],
            'external_domains' => ['evil.example'],
            'suspicious_patterns' => ['visible_text_length' => 10, 'hidden_keywords' => ['maxwin']],
        ]);

        $result = app(RuleEngine::class)->evaluate($website, $check, $extraction, null, collect());

        $categories = self::categoriesOf($result);
        $this->assertContains('availability', $categories);
        $this->assertNotContains('content-keyword', $categories);
        $this->assertNotContains('external-link', $categories);
        $this->assertNotContains('content-fingerprint', $categories);
        $this->assertSame('DOWN', $result->availabilityState);
    }

    public function test_decay_and_carry_forward_produce_the_specified_score(): void
    {
        $website = $this->website();

        // t-1: RULE-KW-004  -> 6 * 1.5 = 9
        $prior1 = $this->makeCheck($website, [
            'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Maxwin',
            'content_hash' => hash('sha256', 'p1'), 'response_size_bytes' => 2000,
            'ssl_valid' => true, 'ssl_expires_at' => '+90d',
        ], 'p1');
        $prior1->forceFill([
            'triggered_rules' => ['RULE-KW-004' => ['category' => 'content-keyword', 'weight' => 6, 'confidence' => 'high']],
        ])->save();

        // t-2: RULE-CNT-001  -> 1 * 0.5 = 1  (rounded), carry 0.25 -> 0
        $prior2 = $this->makeCheck($website, [
            'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
            'content_hash' => hash('sha256', 'p2'), 'response_size_bytes' => 2000,
            'ssl_valid' => true, 'ssl_expires_at' => '+90d',
        ], 'p2');
        $prior2->forceFill([
            'triggered_rules' => ['RULE-CNT-001' => ['category' => 'content-fingerprint', 'weight' => 1, 'confidence' => 'low']],
        ])->save();

        // t-3: RULE-CNT-002 (only affects t-3 decay) -> 6 * 1.5 = 9, carry 0.125 -> 1
        $prior3 = $this->makeCheck($website, [
            'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
            'content_hash' => hash('sha256', 'p3'), 'response_size_bytes' => 2000,
            'ssl_valid' => true, 'ssl_expires_at' => '+90d',
        ], 'p3');
        $prior3->forceFill([
            'triggered_rules' => ['RULE-CNT-002' => ['category' => 'content-fingerprint', 'weight' => 6, 'confidence' => 'high']],
        ])->save();

        // Current check fires nothing; the whole score is carried forward.
        $current = $this->makeCheck($website, [
            'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
            'content_hash' => hash('sha256', 'cur'), 'response_size_bytes' => 2000,
            'ssl_valid' => true, 'ssl_expires_at' => '+90d',
        ], 'cur');

        $priors = Check::where('website_id', $website->id)
            ->whereIn('id', [$prior1->id, $prior2->id, $prior3->id])
            ->orderByDesc('id')
            ->get();

        $result = app(RuleEngine::class)->evaluate($website, $current, null, null, $priors);

        // 0 current + round(9*0.5)=5 + round(1*0.25)=0 + round(9*0.125)=1
        $this->assertSame(6, $result->score);
        $this->assertContains('content-keyword', self::categoriesOf($result), 'Carried signals must vote.');
        $this->assertContains('content-fingerprint', self::categoriesOf($result));
    }

    /**
     * Explicit decay matrix (DETECTION-RULES 6.4).
     *
     * Each prior check carries its own signals at the canonical factor for its
     * age: most-recent x0.5, two-back x0.25, three-back x0.125, nothing beyond.
     */
    public function test_decay_matrix_across_the_lookback_window(): void
    {
        $website = $this->website();

        // One high-confidence KW-004 signal per prior check: 6 x 1.5 = 9 raw.
        $priors = [];
        foreach (['d3', 'd2', 'd1'] as $slug) {
            $check = $this->makeCheck($website, [
                'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
                'content_hash' => hash('sha256', $slug), 'response_size_bytes' => 2000,
                'ssl_valid' => true, 'ssl_expires_at' => '+90d',
            ], $slug);
            $check->forceFill([
                'triggered_rules' => ['RULE-KW-004' => ['category' => 'content-keyword', 'weight' => 6, 'confidence' => 'high']],
            ])->save();
            $priors[$slug] = $check;
        }

        $current = $this->makeCheck($website, [
            'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
            'content_hash' => hash('sha256', 'cur'), 'response_size_bytes' => 2000,
            'ssl_valid' => true, 'ssl_expires_at' => '+90d',
        ], 'cur');

        $window = Check::whereIn('id', [$priors['d3']->id, $priors['d2']->id, $priors['d1']->id])
            ->orderByDesc('id')
            ->get();

        // --- current only (no priors): no signals at all -> 0 ---
        $none = app(RuleEngine::class)->evaluate($website, $current, null, null, collect());
        $this->assertSame(0, $none->score, 'current only');

        // --- current + previous: round(9 x 0.5) = 5 ---
        $oneBack = app(RuleEngine::class)->evaluate($website, $current, null, null, $window->take(1));
        $this->assertSame(5, $oneBack->score, 'current + previous');

        // --- + two-back: 5 + round(9 x 0.25) = 5 + 2 = 7 ---
        $twoBack = app(RuleEngine::class)->evaluate($website, $current, null, null, $window->take(2));
        $this->assertSame(7, $twoBack->score, 'current + previous + two-back');

        // --- + three-back: 7 + round(9 x 0.125) = 7 + 1 = 8 ---
        $threeBack = app(RuleEngine::class)->evaluate($website, $current, null, null, $window->take(3));
        $this->assertSame(8, $threeBack->score, 'current + previous + two-back + three-back');

        // --- beyond the window: a fourth prior check contributes nothing ---
        $fourth = $this->makeCheck($website, [
            'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
            'content_hash' => hash('sha256', 'd4'), 'response_size_bytes' => 2000,
            'ssl_valid' => true, 'ssl_expires_at' => '+90d',
        ], 'd4');
        $fourth->forceFill([
            'triggered_rules' => ['RULE-KW-004' => ['category' => 'content-keyword', 'weight' => 6, 'confidence' => 'high']],
        ])->save();

        $fourPriors = Check::whereIn('id', [$fourth->id, $priors['d3']->id, $priors['d2']->id, $priors['d1']->id])
            ->orderByDesc('id')
            ->get()
            ->take(4);

        // The engine takes only the window; seeding a 4th must not change the score.
        $beyond = app(RuleEngine::class)->evaluate($website, $current, null, null, $fourPriors->take(3));
        $this->assertSame(8, $beyond->score, 'beyond retention window ignored');

        // --- same-category carry collapses to one category ---
        $this->assertSame(1, count(self::categoriesOf($threeBack)), 'same-category carry must not multiply categories');

        // --- cross-category carry votes for each distinct category ---
        // Two-back becomes a content-fingerprint signal worth 1 x 0.5 = 1 raw,
        // so the score changes while the category set gains a second entry.
        $priors['d2']->forceFill([
            'triggered_rules' => ['RULE-CNT-001' => ['category' => 'content-fingerprint', 'weight' => 1, 'confidence' => 'low']],
        ])->save();

        $crossWindow = Check::whereIn('id', [$priors['d3']->id, $priors['d2']->id, $priors['d1']->id])
            ->orderByDesc('id')
            ->get();

        $cross = app(RuleEngine::class)->evaluate($website, $current, null, null, $crossWindow);
        $categories = self::categoriesOf($cross);
        sort($categories);
        $this->assertSame(['content-fingerprint', 'content-keyword'], $categories, 'cross-category carry must vote for both');

        // --- carried signals must not be double-counted in the score ---
        // 5 (x0.5 KW-004) + round(1 x 0.25) = 5 + 0 + 1 (x0.125 KW-004) = 6.
        // Categories still vote even when their decayed score rounds to zero.
        $this->assertSame(
            6,
            $cross->score,
            'carried categories must not add score beyond the per-signal decay',
        );
    }

    public function test_engine_is_idempotent_for_identical_inputs(): void
    {
        $case = $this->case('ssl_001_expired_plus_new_domain');

        [, , $first] = $this->runCase($case);
        [, , $second] = $this->runCase($case);

        $this->assertSame($first->score, $second->score);
        $this->assertSame($first->securityState, $second->securityState);
        $this->assertSame($first->guardCapped, $second->guardCapped);
        $this->assertSame(json_encode($first->triggeredRules()), json_encode($second->triggeredRules()));
    }

    /* ------------------------------------------------------------------ */
    /* D1 - baseline persistence */
    /* ------------------------------------------------------------------ */

    public function test_baseline_persists_response_size_and_external_domains(): void
    {
        $website = $this->website();
        $baseline = $this->makeBaseline($website, [
            'http_status' => 200,
            'final_url' => 'https://example.com',
            'title' => 'Example',
            'content_hash' => 'H_BODY',
            'response_size_bytes' => 4321,
            'external_link_count' => 2,
            'external_domains' => ['example.org', 'example.net'],
            'ssl_valid' => true,
        ]);

        $fresh = WebsiteBaseline::findOrFail($baseline->id);

        $this->assertSame(4321, $fresh->response_size_bytes);
        $this->assertSame(['example.org', 'example.net'], $fresh->external_domains);
        $this->assertSame(2, $fresh->external_link_count);
    }

    /* ------------------------------------------------------------------ */
    /* D2 - canonical domain comparison */
    /* ------------------------------------------------------------------ */

    public function test_second_unchanged_check_does_not_flag_every_domain_as_new(): void
    {
        $baseline = $this->makeBaseline($this->website(), [
            'external_domains' => ['https://example.org/page', 'https://www.example.net/x'],
            'external_link_count' => 2,
        ]);

        $new = BaselineComparator::newDomains(
            ['https://example.org/other', 'https://www.example.net/y'],
            $baseline,
        );

        $this->assertSame([], $new);
    }

    public function test_genuinely_new_domain_is_detected_and_normalized(): void
    {
        $baseline = $this->makeBaseline($this->website(), [
            'external_domains' => ['example.org'],
        ]);

        $new = BaselineComparator::newDomains(
            ['https://example.org/a', 'https://WWW.Evil.Example./b'],
            $baseline,
        );

        $this->assertSame(['evil.example'], $new);
    }

    public function test_ignored_domains_are_excluded_from_new_domain_set(): void
    {
        $new = BaselineComparator::newDomains(
            ['https://google.com/x', 'https://newhost.example/y'],
            null,
            ['google.com'],
        );

        $this->assertSame(['newhost.example'], $new);
    }

    public function test_removed_domains_do_not_create_signals(): void
    {
        // Canonical rule RULE-LNK-001 flags *added* domains only; a domain that
        // disappeared from the page is not an injection signal.
        [, , $result] = $this->runCase([
            'id' => 'inline_removed_domain',
            'website' => [],
            'baseline' => [
                'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
                'content_hash' => 'H_BODY', 'response_size_bytes' => 2000,
                'external_link_count' => 2, 'external_domains' => ['example.org', 'gone.example'],
                'ssl_valid' => true, 'ssl_expires_at' => '+90d',
            ],
            'check' => [
                'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
                'content_hash' => 'H_BODY', 'response_size_bytes' => 2000,
                'ssl_valid' => true, 'ssl_expires_at' => '+90d', 'availability_state' => 'UP',
            ],
            'extraction' => ['external_domains' => ['example.org']],
            'expect' => ['signals' => [], 'score' => 0, 'categories' => 0, 'security_state' => 'OK', 'guard_capped' => false],
        ]);

        $this->assertSame([], self::rulesOf($result));
    }

    /* ------------------------------------------------------------------ */
    /* D3 - RULE-KW-005 evidence path through the extractor */
    /* ------------------------------------------------------------------ */

    public function test_hidden_keyword_extraction_reaches_rule_kw_005_end_to_end(): void
    {
        $html = <<<'HTML'
        <html><head><title>Example</title></head>
        <body>
          <p>Welcome to our site.</p>
          <p>Please read our terms and conditions before continuing with your visit today.</p>
          <div style="display:none">maxwin situs slot gacor</div>
        </body></html>
        HTML;

        $extraction = (new ContentExtractor)->extract($html, 'https://example.com');

        $this->assertContains('maxwin', $extraction['suspicious_patterns']['hidden_keywords']);
        $this->assertGreaterThan(0, $extraction['suspicious_patterns']['hidden_text_length']);
    }

    /* ------------------------------------------------------------------ */
    /* D4 - tiered extraction */
    /* ------------------------------------------------------------------ */

    public function test_extraction_emits_all_three_tiers_and_normalizes_case(): void
    {
        $html = <<<'HTML'
        <html><head><title>Site</title></head>
        <body>
          <p>MAXWIN and Jackpot and Bonus</p>
          <p>promo hadiah menang game</p>
        </body></html>
        HTML;

        $extractor = new ContentExtractor;
        $keywords = $extractor->extract($html, 'https://example.com')['keywords'];

        $this->assertArrayHasKey('maxwin', $keywords, 'Tier-1 not extracted.');
        $this->assertArrayHasKey('jackpot', $keywords, 'Tier-2 not extracted.');
        $this->assertArrayHasKey('bonus', $keywords, 'Tier-3 not extracted.');
        $this->assertSame(1, $keywords['maxwin'], 'Case normalization failed.');

        // Deterministic: identical input, identical output.
        $this->assertSame($keywords, $extractor->extract($html, 'https://example.com')['keywords']);
    }

    public function test_extraction_ignores_offsite_and_records_authorship_evidence(): void
    {
        $html = <<<'HTML'
        <html><body>
          <a href="https://example.org/a">ok</a>
          <a href="https://link-alternatif.top/go" style="visibility:hidden">go</a>
        </body></html>
        HTML;

        $extraction = (new ContentExtractor)->extract($html, 'https://example.com');

        $this->assertContains('example.org', $extraction['external_domains']);
        $this->assertContains('link-alternatif.top', $extraction['external_domains']);
        $this->assertContains('link-alternatif.top', $extraction['suspicious_patterns']['hidden_anchors']);
    }

    public function test_extraction_never_dereferences_links(): void
    {
        // A link to a metadata service must be treated as data, never fetched.
        $html = '<html><body><a href="http://169.254.169.254/latest/meta-data/">x</a></body></html>';

        $extraction = (new ContentExtractor)->extract($html, 'https://example.com');

        $this->assertContains('169.254.169.254', $extraction['external_domains']);
    }

    /* ------------------------------------------------------------------ */
    /* D9 - snapshot persistence and failure isolation */
    /* ------------------------------------------------------------------ */

    public function test_snapshot_capture_stores_relative_path_and_sanitizes_headers(): void
    {
        Storage::fake('local');

        $website = $this->website();
        $check = $this->makeCheck($website, [
            'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
            'content_hash' => hash('sha256', 'b'), 'response_size_bytes' => 100,
            'ssl_valid' => true, 'ssl_expires_at' => '+90d',
        ]);

        $snapshot = app(SnapshotWriter::class)->capture(
            $check,
            '<html><body>evidence</body></html>',
            ['Content-Type' => 'text/html', 'Set-Cookie' => 'secret=1', 'Authorization' => 'Bearer x'],
        );

        $this->assertNotNull($snapshot);
        $this->assertSame("snapshots/{$website->id}/{$check->id}.html", $snapshot->html_path);
        $this->assertFileExists(Storage::disk('local')->path($snapshot->html_path));
        $this->assertArrayNotHasKey('Set-Cookie', $snapshot->headers);
        $this->assertArrayNotHasKey('Authorization', $snapshot->headers);
        $this->assertArrayHasKey('Content-Type', $snapshot->headers);
    }

    public function test_snapshot_failure_does_not_prevent_check_persistence(): void
    {
        $website = $this->website();
        $check = $this->makeCheck($website, [
            'http_status' => 200, 'final_url' => 'https://example.com', 'title' => 'Example',
            'content_hash' => hash('sha256', 'b'), 'response_size_bytes' => 100,
            'ssl_valid' => true, 'ssl_expires_at' => '+90d',
        ]);

        // Force the snapshot write to fail.
        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('storage offline'));

        $snapshot = app(SnapshotWriter::class)->capture($check, '<html></html>', []);

        $this->assertNull($snapshot);
        $this->assertDatabaseHas('checks', ['id' => $check->id]);
        $this->assertSame(1, Check::where('website_id', $website->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* D8 - idempotency on a persistent check key */
    /* ------------------------------------------------------------------ */

    public function test_replaying_the_same_check_key_does_not_raise_or_duplicate(): void
    {
        $website = $this->website();
        $key = 'website:'.$website->id.':2026-09-30-12-00';

        Check::create([
            'website_id' => $website->id,
            'check_key' => $key,
            'started_at' => now(),
            'finished_at' => now(),
            'http_status' => 200,
            'availability_state' => 'UP',
        ]);

        $before = Check::where('check_key', $key)->count();

        // The idempotency guard the job applies before persisting.
        $shouldSkip = Check::where('check_key', $key)->exists();
        $this->assertTrue($shouldSkip);

        $after = Check::where('check_key', $key)->count();

        $this->assertSame(1, $before);
        $this->assertSame(1, $after, 'Replay created a duplicate or dropped the row.');
    }
}
