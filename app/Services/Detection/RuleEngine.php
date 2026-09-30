<?php

declare(strict_types=1);

namespace App\Services\Detection;

use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\DetectionRule;
use App\Models\Website;
use App\Models\WebsiteBaseline;
use App\Models\WebsiteRuleSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * The detection rule engine (DETECTION-RULES.md §6, §8, §9).
 *
 * Responsibilities:
 *  - evaluate every applicable rule against one check's evidence;
 *  - apply the canonical decay / carry-forward arithmetic (§6.4);
 *  - classify the security dimension with the correlation guard (§6.3);
 *  - never merge the availability and security dimensions (§3).
 *
 * All arithmetic is deterministic: re-evaluating the same evidence yields the
 * same score and state (idempotency, §6.4).
 */
final class RuleEngine
{
    /** Canonical lookback window in checks (DETECTION-RULES §6.4). */
    public const LOOKBACK_CHECKS = 3;

    /** Canonical carry-forward multipliers, most-recent first (§6.4). */
    public const DECAY_FACTORS = [0.5, 0.25, 0.125];

    /** @var array<string, DetectionRule> */
    private array $ruleRegistry = [];

    /** @var array<int, WebsiteRuleSetting> keyed by detection_rule_id */
    private array $settings = [];

    private bool $rulesLoaded = false;

    /**
     * @param  Collection<int, Check>  $priorChecks  prior checks, newest first
     */
    public function evaluate(
        Website $website,
        Check $check,
        ?CheckExtraction $extraction,
        ?WebsiteBaseline $baseline,
        Collection $priorChecks,
    ): DetectionResult {
        $this->loadRules($website);

        $signals = [];

        // Availability always evaluates and feeds the correlator (§6.6).
        $signals = array_merge($signals, $this->evaluateAvailability($website, $check));

        // A DOWN check with no body must not fabricate content signals (§4, Implementation Notes).
        $downNoBody = $check->availability_state === 'DOWN'
            && $check->http_status === null
            && $check->error_type !== null;

        if ($website->monitor_ssl) {
            $signals = array_merge($signals, $this->evaluateSsl($website, $check, $baseline));
        }

        if ($website->monitor_redirects) {
            $signals = array_merge($signals, $this->evaluateRedirect($website, $check, $baseline));
        }

        if (! $downNoBody) {
            if ($website->monitor_content) {
                $signals = array_merge($signals, $this->evaluateContentFingerprint($website, $check, $baseline, $extraction));
                $signals = array_merge($signals, $this->evaluateKeywords($website, $check, $baseline, $extraction));
                $signals = array_merge($signals, $this->evaluateExternalLinks($website, $check, $baseline, $extraction));
            }

            if ($website->monitor_security) {
                $signals = array_merge($signals, $this->evaluateSeoPatterns($website, $check, $baseline, $extraction));
            }
        }

        $signals = $this->dedupeSignals($signals);

        return $this->scoreAndClassify($website, $check, $signals, $priorChecks);
    }

    /*
    |--------------------------------------------------------------------------
    | Registry & per-website overrides (DETECTION-RULES §6.9, §10)
    |--------------------------------------------------------------------------
    */

    private function loadRules(Website $website): void
    {
        if ($this->rulesLoaded) {
            return;
        }

        foreach (DetectionRule::all() as $rule) {
            $this->ruleRegistry[$rule->rule_id] = $rule;
        }

        foreach (WebsiteRuleSetting::where('website_id', $website->id)->get() as $setting) {
            $this->settings[$setting->detection_rule_id] = $setting;
        }

        $this->rulesLoaded = true;
    }

    private function rule(string $ruleId): ?DetectionRule
    {
        return $this->ruleRegistry[$ruleId] ?? null;
    }

    private function settingFor(string $ruleId): ?WebsiteRuleSetting
    {
        $rule = $this->rule($ruleId);

        return $rule ? ($this->settings[$rule->id] ?? null) : null;
    }

    /** A rule runs only if globally enabled and not disabled for this website. */
    private function ruleEnabled(string $ruleId): bool
    {
        $rule = $this->rule($ruleId);
        if ($rule === null || ! $rule->enabled) {
            return false;
        }

        $setting = $this->settingFor($ruleId);
        if ($setting !== null && $setting->enabled !== null) {
            return (bool) $setting->enabled;
        }

        return true;
    }

    /** weight_override replaces default_weight for this website (§6.9 precedence 1 > 5). */
    private function ruleWeight(string $ruleId, int $fallback): int
    {
        $setting = $this->settingFor($ruleId);
        if ($setting !== null && $setting->weight_override !== null) {
            return (int) $setting->weight_override;
        }

        $rule = $this->rule($ruleId);

        return $rule !== null ? (int) $rule->default_weight : $fallback;
    }

    private function emit(
        string $ruleId,
        string $category,
        string $confidence,
        string $reason,
        array $evidence = [],
    ): ?Signal {
        if (! $this->ruleEnabled($ruleId)) {
            return null;
        }

        $weight = $this->ruleWeight($ruleId, 0);
        if ($weight <= 0) {
            return null;
        }

        return new Signal(
            ruleId: $ruleId,
            category: $category,
            weight: $weight,
            confidence: $confidence,
            reason: $reason,
            evidence: $evidence,
        );
    }

    /**
     * Keep one signal per rule id (highest weight wins) so decay and the guard
     * cannot double-count the same rule (idempotency, §6.4).
     *
     * @param  array<int, Signal>  $signals
     * @return array<int, Signal>
     */
    private function dedupeSignals(array $signals): array
    {
        $byRule = [];
        foreach ($signals as $signal) {
            if (! isset($byRule[$signal->ruleId]) || $signal->weight > $byRule[$signal->ruleId]->weight) {
                $byRule[$signal->ruleId] = $signal;
            }
        }

        return array_values($byRule);
    }

    /*
    |--------------------------------------------------------------------------
    | Availability — RULE-AV-* (DETECTION-RULES §8.1)
    |--------------------------------------------------------------------------
    */

    /** @return array<int, Signal> */
    private function evaluateAvailability(Website $website, Check $check): array
    {
        $signals = [];

        if ($check->http_status !== null && $check->http_status >= 500 && $check->http_status <= 599) {
            $this->push($signals, $this->emit('RULE-AV-001', 'availability', 'high', 'HTTP failure (5xx)', ['status' => $check->http_status]));
        }

        if ($check->http_status !== null
            && $check->http_status !== $website->expected_status
            && ! in_array($check->http_status, [304, 401], true)
            && ! ($check->http_status >= 500 && $check->http_status <= 599)
        ) {
            $this->push($signals, $this->emit('RULE-AV-002', 'availability', 'high', "Expected status {$website->expected_status}, got {$check->http_status}", ['status' => $check->http_status]));
        }

        if ($check->error_type === 'timeout') {
            $this->push($signals, $this->emit('RULE-AV-003', 'availability', 'high', 'Request timed out'));
        }

        if ($check->error_type === 'dns_failure') {
            $this->push($signals, $this->emit('RULE-AV-004', 'availability', 'high', 'DNS resolution failure'));
        }

        if (in_array($check->error_type, ['connection_refused', 'connection_unreachable', 'tls_handshake_failure'], true)) {
            $this->push($signals, $this->emit('RULE-AV-005', 'availability', 'high', 'Connection or TLS handshake failure', ['error_type' => $check->error_type]));
        }

        $budgetMs = max(1, (int) ($website->timeout_seconds ?? 10)) * 1000;
        if ($check->duration_ms !== null && $check->duration_ms >= 0.80 * $budgetMs) {
            $this->push($signals, $this->emit('RULE-AV-006', 'availability', 'low', 'Response time above threshold', ['duration_ms' => $check->duration_ms]));
        }

        return $signals;
    }

    /*
    |--------------------------------------------------------------------------
    | SSL — RULE-SSL-* (DETECTION-RULES §8.2)
    |--------------------------------------------------------------------------
    */

    /** @return array<int, Signal> */
    private function evaluateSsl(Website $website, Check $check, ?WebsiteBaseline $baseline): array
    {
        $signals = [];

        if ($check->ssl_valid === false) {
            $expired = $check->ssl_expires_at !== null && $check->ssl_expires_at->isPast();
            $cause = $expired ? 'expired' : 'invalid';
            // Canonical: CRITICAL only when expiry is the confirmed cause; the
            // registry holds the CRITICAL severity while this path emits the
            // effective severity through the confidence/weight it carries.
            $this->push($signals, $this->emit(
                'RULE-SSL-001',
                'ssl',
                'high',
                "Invalid or expired certificate ({$cause})",
                ['cause' => $cause],
            ));
        }

        if ($check->ssl_valid === false
            && $check->error_message !== null
            && str_contains(mb_strtolower($check->error_message), 'hostname')
        ) {
            $this->push($signals, $this->emit('RULE-SSL-002', 'ssl', 'high', 'Certificate hostname mismatch'));
        }

        $chainProblem = $check->error_message !== null && (
            str_contains(mb_strtolower($check->error_message), 'issuer')
            || str_contains(mb_strtolower($check->error_message), 'chain')
        );

        if ($baseline !== null
            && $baseline->ssl_issuer
            && $check->ssl_issuer
            && $baseline->ssl_issuer !== $check->ssl_issuer
        ) {
            $chainProblem = true;
        }

        if ($chainProblem) {
            $this->push($signals, $this->emit('RULE-SSL-003', 'ssl', 'medium', 'Certificate chain or issuer anomaly', [
                'baseline_issuer' => $baseline?->ssl_issuer,
                'current_issuer' => $check->ssl_issuer,
            ]));
        }

        // RULE-SSL-004 - graduated windows (DETECTION-RULES 8.2):
        // <= 7 days CRITICAL-eligible, <= 14 WARNING, <= 30 INFO. The registry
        // severity stays at the canonical INFO default; the tier is carried in
        // evidence because the weighted scoring model is driven by
        // weight x confidence, not severity. Even at the <= 7 tier the
        // correlation guard still applies (8.2 Threshold note).
        if ($check->ssl_valid !== false && $check->ssl_expires_at !== null) {
            $secondsUntil = $check->ssl_expires_at->getTimestamp() - now()->getTimestamp();
            $days = (int) floor($secondsUntil / 86400);
            $tier = null;
            if ($days <= 7) {
                $tier = 7;
            } elseif ($days <= 14) {
                $tier = 14;
            } elseif ($days <= 30) {
                $tier = 30;
            }
            if ($tier !== null) {
                $this->push($signals, $this->emit('RULE-SSL-004', 'ssl', 'medium', "Certificate expires in {$days} day(s) (tier {$tier})", ['days' => $days, 'tier' => $tier]));
            }
        }

        return $signals;
    }

    /*
    |--------------------------------------------------------------------------
    | Redirect — RULE-RED-* (DETECTION-RULES §8.3)
    |--------------------------------------------------------------------------
    */

    /** @return array<int, Signal> */
    private function evaluateRedirect(Website $website, Check $check, ?WebsiteBaseline $baseline): array
    {
        $signals = [];
        $chain = is_array($check->redirect_chain) ? $check->redirect_chain : [];
        $hops = count($chain);

        if ($check->error_type === 'SSRF_BLOCKED' || $check->error_type === 'ssrf_blocked') {
            $this->push($signals, $this->emit('RULE-RED-006', 'redirect', 'high', 'Redirect to private or blocked target'));
        }

        foreach ($chain as $hop) {
            $from = (string) ($hop['url'] ?? $hop['from_url'] ?? $hop['from'] ?? '');
            $to = (string) ($hop['to_url'] ?? $hop['to'] ?? '');
            if ($from !== '' && $to !== '' && str_starts_with(mb_strtolower($from), 'https:') && str_starts_with(mb_strtolower($to), 'http:')) {
                $this->push($signals, $this->emit('RULE-RED-005', 'redirect', 'high', 'HTTPS to HTTP downgrade', ['from' => $from, 'to' => $to]));
                break;
            }
        }

        $expectedDomain = $website->expected_final_domain ?: ($baseline ? BaselineComparator::domainFromUrl($baseline->final_url) : '');
        $finalDomain = BaselineComparator::domainFromUrl($check->final_url);

        $baselineHops = 0;
        if ($baseline !== null && is_array($baseline->external_domains)) {
            $baselineHops = 0;
        }
        $expectedHops = $check->http_status !== null && $check->final_url === $website->url ? 0 : 1;

        if ($hops > 0 && $expectedDomain !== '' && $finalDomain !== '' && $finalDomain === $expectedDomain) {
            // Expected, stable redirect — not an "unexpected redirect".
        } elseif ($hops > 0) {
            $this->push($signals, $this->emit('RULE-RED-001', 'redirect', 'high', 'Unexpected redirect present', ['hops' => $hops]));
        }

        if ($finalDomain !== '' && $expectedDomain !== '' && $finalDomain !== $expectedDomain) {
            $this->push($signals, $this->emit('RULE-RED-002', 'redirect', 'medium', 'Final URL domain differs from expected', ['expected' => $expectedDomain, 'actual' => $finalDomain]));
        }

        foreach ($chain as $hop) {
            $to = (string) ($hop['to_url'] ?? $hop['to'] ?? '');
            $domain = BaselineComparator::domainFromUrl($to);
            if ($domain !== '' && $this->isSuspiciousDomain($domain) && $domain !== $expectedDomain) {
                $this->push($signals, $this->emit('RULE-RED-003', 'redirect', 'medium', 'Redirect to suspicious domain', ['target' => $domain]));
                break;
            }
        }

        if ($hops > max(2, $expectedHops + 2)) {
            $this->push($signals, $this->emit('RULE-RED-004', 'redirect', 'medium', "Redirect chain length anomaly ({$hops} hops)", ['hops' => $hops]));
        }

        return $signals;
    }

    /*
    |--------------------------------------------------------------------------
    | Content fingerprint — RULE-CNT-* (DETECTION-RULES §8.4)
    |--------------------------------------------------------------------------
    */

    /** @return array<int, Signal> */
    private function evaluateContentFingerprint(Website $website, Check $check, ?WebsiteBaseline $baseline, ?CheckExtraction $extraction): array
    {
        $signals = [];

        if ($baseline !== null && $check->content_hash !== null && $check->content_hash !== $baseline->content_hash) {
            $this->push($signals, $this->emit('RULE-CNT-001', 'content-fingerprint', 'low', 'Content hash changed'));
        }

        $expectedTitle = $website->expected_title ?: ($baseline?->title);
        if ($expectedTitle !== null) {
            $currentTitle = trim((string) $check->title);
            if ($currentTitle === '') {
                $this->push($signals, $this->emit('RULE-CNT-002', 'content-fingerprint', 'high', 'Page title missing'));
            } elseif (mb_strtolower($currentTitle) !== mb_strtolower(trim($expectedTitle))) {
                if ($this->containsTier1($currentTitle) || $this->containsTier2($currentTitle)) {
                    $this->push($signals, $this->emit('RULE-CNT-002', 'content-fingerprint', 'high', 'Page title replaced with spam content', ['title' => $currentTitle]));
                } else {
                    $this->push($signals, $this->emit('RULE-CNT-001', 'content-fingerprint', 'low', 'Page title changed', ['title' => $currentTitle]));
                }
            }
        }

        if ($baseline !== null
            && $baseline->response_size_bytes !== null
            && $baseline->response_size_bytes > 0
            && $check->response_size_bytes !== null
        ) {
            $ratio = $check->response_size_bytes / $baseline->response_size_bytes;
            if ($ratio >= 1.5 || $ratio <= 0.5) {
                $this->push($signals, $this->emit('RULE-CNT-003', 'content-fingerprint', 'medium', 'Major structural change (body size ratio '.round($ratio, 2).')', ['ratio' => $ratio]));
            }
        }

        if ($check->http_status === 200 && $check->response_size_bytes !== null && $check->response_size_bytes < 512) {
            $this->push($signals, $this->emit('RULE-CNT-004', 'content-fingerprint', 'medium', 'Content became empty or unreachable', ['size' => $check->response_size_bytes]));
        }

        $patterns = $extraction?->suspicious_patterns ?? [];
        $hiddenLength = (int) ($patterns['hidden_text_length'] ?? 0);
        $visibleLength = (int) ($patterns['visible_text_length'] ?? 0);
        if ($hiddenLength >= 500 && $visibleLength > 0 && ($hiddenLength / $visibleLength) >= 0.20) {
            $this->push($signals, $this->emit('RULE-CNT-005', 'content-fingerprint', 'medium', "Hidden block of {$hiddenLength} chars", ['hidden_length' => $hiddenLength]));
        }

        return $signals;
    }

    /*
    |--------------------------------------------------------------------------
    | Content keyword — RULE-KW-* (DETECTION-RULES §8.6)
    |--------------------------------------------------------------------------
    */

    /** @return array<int, Signal> */
    private function evaluateKeywords(Website $website, Check $check, ?WebsiteBaseline $baseline, ?CheckExtraction $extraction): array
    {
        $signals = [];

        if ($extraction === null) {
            return $signals;
        }

        $ignored = $this->ignoredKeywords($website);
        $counts = is_array($extraction->keywords) ? $extraction->keywords : [];
        $patterns = is_array($extraction->suspicious_patterns) ? $extraction->suspicious_patterns : [];
        $baselineCounts = is_array($baseline?->keyword_counts) ? $baseline->keyword_counts : [];

        // ---- RULE-KW-001 / RULE-KW-002: tier-1 presence and clustering ----
        $newTier1 = [];
        foreach ($this->tier1() as $term) {
            if ($this->isIgnored($term, $ignored)) {
                continue;
            }
            if (! isset($counts[$term])) {
                continue;
            }
            $baselineRate = (int) ($baselineCounts[$term] ?? 0);
            if ($baselineRate === 0) {
                $newTier1[] = $term;
            }
        }
        sort($newTier1);

        if ($newTier1 !== []) {
            $this->push($signals, $this->emit('RULE-KW-001', 'content-keyword', 'low', 'Tier-1 keyword(s) newly present', ['keywords' => $newTier1]));
        }

        if (count($newTier1) >= 5) {
            $this->push($signals, $this->emit('RULE-KW-002', 'content-keyword', 'medium', 'Tier-1 keyword cluster newly present', ['keywords' => $newTier1]));
        }

        // ---- RULE-KW-003: tier-2 density (≥3× baseline or ≥0.2% of visible text) ----
        $visibleLength = max(1, (int) ($patterns['visible_text_length'] ?? 0));
        $baselineVisible = 0;
        foreach ($baselineCounts as $term => $count) {
            $baselineVisible += (int) $count;
        }
        $baselineVisibleLength = max(1, $baselineVisible > 0 ? $baselineVisible * 10 : 0);
        $floor = (float) Config::get('sentinel.detection_keywords.tier2_density_floor', 0.002);

        $tier2Hits = [];
        foreach ($this->tier2() as $term) {
            if ($this->isIgnored($term, $ignored) || ! isset($counts[$term])) {
                continue;
            }
            $rate = ((int) $counts[$term]) / $visibleLength;
            $baselineRate = ((int) ($baselineCounts[$term] ?? 0)) / $baselineVisibleLength;
            if ($rate >= max(3 * $baselineRate, $floor)) {
                $tier2Hits[] = $term;
            }
        }
        foreach ($tier2Hits as $term) {
            $this->push($signals, $this->emit('RULE-KW-003', 'content-keyword', 'medium', 'Tier-2 keyword density anomaly', ['keyword' => $term]));
        }

        // ---- RULE-KW-004: tier-1 term in the head region (title / meta) ----
        $headText = (string) $check->title;
        $headTerms = [];
        foreach ($this->tier1() as $term) {
            if ($this->isIgnored($term, $ignored)) {
                continue;
            }
            if (str_contains(mb_strtolower($headText), mb_strtolower($term))) {
                $headTerms[] = $term;
            }
        }
        sort($headTerms);
        if ($headTerms !== []) {
            $this->push($signals, $this->emit('RULE-KW-004', 'content-keyword', 'high', 'Suspicious keyword in title', ['keywords' => $headTerms]));
        }

        // ---- RULE-KW-005: keywords hidden via CSS or obfuscation ----
        $hiddenKeywords = is_array($patterns['hidden_keywords'] ?? null) ? $patterns['hidden_keywords'] : [];
        $hiddenKeywords = array_values(array_filter(
            $hiddenKeywords,
            fn ($term) => ! $this->isIgnored((string) $term, $ignored),
        ));
        if ($hiddenKeywords !== []) {
            $this->push($signals, $this->emit('RULE-KW-005', 'content-keyword', 'medium', 'Hidden or obfuscated keyword pattern', ['keywords' => $hiddenKeywords, 'cause' => 'css_obfuscation']));
        } elseif (! empty($patterns['obfuscated_inline'])) {
            $this->push($signals, $this->emit('RULE-KW-005', 'content-keyword', 'medium', 'Obfuscated keyword encoding', ['cause' => 'obfuscation_encoding']));
        }

        return $signals;
    }

    /*
    |--------------------------------------------------------------------------
    | External links — RULE-LNK-* (DETECTION-RULES §8.7)
    |--------------------------------------------------------------------------
    */

    /** @return array<int, Signal> */
    private function evaluateExternalLinks(Website $website, Check $check, ?WebsiteBaseline $baseline, ?CheckExtraction $extraction): array
    {
        $signals = [];

        if ($extraction === null) {
            return $signals;
        }

        $current = is_array($extraction->external_domains) ? $extraction->external_domains : [];
        $patterns = is_array($extraction->suspicious_patterns) ? $extraction->suspicious_patterns : [];
        $ignored = $this->ignoredDomains();

        $newDomains = BaselineComparator::newDomains($current, $baseline, $ignored);

        if ($newDomains !== []) {
            $this->push($signals, $this->emit('RULE-LNK-001', 'external-link', 'medium', 'New external domain(s) vs baseline', ['domains' => $newDomains]));
        }

        foreach ($newDomains as $domain) {
            if ($this->isSuspiciousDomain($domain)) {
                $this->push($signals, $this->emit('RULE-LNK-002', 'external-link', 'medium', 'Suspicious TLD or domain pattern', ['domain' => $domain]));
            }
        }

        $currentCount = count(BaselineComparator::normalizeDomainSet($current));
        $baselineCount = (int) ($baseline?->external_link_count ?? 0);

        if ($currentCount >= 50 && $currentCount >= 3 * max($baselineCount, 1)) {
            $this->push($signals, $this->emit('RULE-LNK-003', 'external-link', 'medium', "Link farm ({$currentCount} external links)", ['count' => $currentCount]));
        }

        // RULE-LNK-004: newly-present off-domain anchor inside a hidden element.
        $hiddenAnchors = is_array($patterns['hidden_anchors'] ?? null) ? $patterns['hidden_anchors'] : [];
        foreach ($hiddenAnchors as $domain) {
            $normalized = BaselineComparator::normalizeDomain((string) $domain);
            if ($normalized === '' || in_array($normalized, $ignored, true)) {
                continue;
            }
            if (in_array($normalized, $newDomains, true)) {
                $this->push($signals, $this->emit('RULE-LNK-004', 'external-link', 'high', 'CSS-hidden anchor to new off-domain target', ['domain' => $normalized]));
                break;
            }
        }

        if ($currentCount >= 20 && $currentCount >= 5 * max($baselineCount, 1)) {
            $this->push($signals, $this->emit('RULE-LNK-005', 'external-link', 'high', "Mass outbound link injection ({$currentCount} vs baseline {$baselineCount})", ['count' => $currentCount, 'baseline' => $baselineCount]));
        }

        return $signals;
    }

    /*
    |--------------------------------------------------------------------------
    | SEO patterns — RULE-SEO-* (DETECTION-RULES §8.8)
    |--------------------------------------------------------------------------
    */

    /** @return array<int, Signal> */
    private function evaluateSeoPatterns(Website $website, Check $check, ?WebsiteBaseline $baseline, ?CheckExtraction $extraction): array
    {
        $signals = [];

        if ($extraction === null) {
            return $signals;
        }

        $patterns = is_array($extraction->suspicious_patterns) ? $extraction->suspicious_patterns : [];

        if (! empty($patterns['doorway'])) {
            $this->push($signals, $this->emit('RULE-SEO-001', 'seo-pattern', 'medium', 'Spam SEO doorway pattern'));
        }

        // RULE-SEO-004 (DETECTION-RULES 8.8): a script source is new when its
        // host is neither the monitored host nor a host already absorbed into
        // the baseline (baseline_script_srcs()/baseline_domains in the canonical
        // logic). Off-baseline obfuscated inline script also fires.
        $knownHosts = $this->baselineScriptHosts($baseline);
        $scriptSrcs = is_array($patterns['script_srcs'] ?? null) ? $patterns['script_srcs'] : [];

        $newSrcs = [];
        foreach ($scriptSrcs as $src) {
            $host = BaselineComparator::normalizeDomain((string) $src);
            if ($host !== '' && ! in_array($host, $knownHosts, true)) {
                $newSrcs[] = $host;
            }
        }
        sort($newSrcs);

        $obfuscated = ! empty($patterns['obfuscated_inline']);

        if ($newSrcs !== [] || $obfuscated) {
            $this->push($signals, $this->emit('RULE-SEO-004', 'seo-pattern', 'medium', 'Suspicious script injection', [
                'new_script_src' => $newSrcs,
                'obfuscated_inline' => $obfuscated,
            ]));
        }

        // RULE-SEO-002 / RULE-SEO-003 are documented as Future (DETECTION-RULES §8.8)
        // and are intentionally not implemented at MVP.

        return $signals;
    }

    /*
    |--------------------------------------------------------------------------
    | Scoring, decay and classification (DETECTION-RULES §6)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, Signal>  $signals  current-check signals (deduped)
     * @param  Collection<int, Check>  $priorChecks  prior checks, newest first
     */
    private function scoreAndClassify(Website $website, Check $check, array $signals, Collection $priorChecks): DetectionResult
    {
        $score = 0;
        $categories = [];
        $carried = [];

        foreach ($signals as $signal) {
            $score += $this->contribution($signal->weight, $signal->confidence);
            $categories[$signal->category] = true;
        }

        // Carry-forward: the previous 3 checks decay 0.5 / 0.25 / 0.125 (§6.4).
        $prior = $priorChecks->take(self::LOOKBACK_CHECKS)->values();
        foreach ($prior as $index => $priorCheck) {
            $factor = self::DECAY_FACTORS[$index] ?? 0.0;
            if ($factor <= 0.0) {
                continue;
            }

            $priorSignals = $this->reconstructSignals($priorCheck);
            foreach ($priorSignals as $priorSignal) {
                $score += (int) round($this->contribution($priorSignal->weight, $priorSignal->confidence) * $factor);
                // Carried signals count toward the guard's category set (§6.3 property 3).
                $categories[$priorSignal->category] = true;
                $carried[] = $priorSignal;
            }
        }

        $infoThreshold = max(1, (int) Config::get('sentinel.scoring.threshold_info', 1));
        $warningThreshold = max($infoThreshold, (int) Config::get('sentinel.scoring.threshold_warning', 8));
        $criticalThreshold = max($warningThreshold, (int) Config::get('sentinel.scoring.threshold_critical', 15));
        $guardMin = max(1, (int) Config::get('sentinel.scoring.correlation_guard_min_categories', 2));

        $guardCapped = false;

        // A per-website `threshold_override` moves the band boundaries for this
        // website before classification (§6.9). It can raise or lower them, but
        // it never bypasses the correlation guard: that is applied afterwards and
        // remains authoritative (§6.3, ADR-009).
        [$infoThreshold, $warningThreshold, $criticalThreshold] = $this->applyThresholdOverride(
            $website,
            $infoThreshold,
            $warningThreshold,
            $criticalThreshold,
        );

        if ($score < $infoThreshold) {
            $state = 'OK';
        } elseif ($score < $warningThreshold) {
            $state = 'INFO';
        } elseif ($score < $criticalThreshold) {
            $state = 'SUSPECT';
        } elseif (count($categories) >= $guardMin) {
            $state = 'INCIDENT';
        } else {
            // The guard is applied last and caps a single-category escalation (§6.3).
            $state = 'SUSPECT';
            $guardCapped = true;
        }

        return new DetectionResult(
            availabilityState: $check->availability_state ?? 'DOWN',
            securityState: $state,
            score: $score,
            guardCapped: $guardCapped,
            signals: $signals,
            carriedSignals: $carried,
        );
    }

    private function contribution(int $weight, string $confidence): int
    {
        return (int) round($weight * RuleConfig::confidenceMultiplier($confidence));
    }

    /**
     * Rebuild a prior check's signals from its persisted evidence so decay uses
     * per-signal arithmetic (never a score-only approximation, §6.4).
     *
     * @return array<int, Signal>
     */
    private function reconstructSignals(Check $priorCheck): array
    {
        $fired = $priorCheck->triggered_rules;
        if (! is_array($fired) || $fired === []) {
            return [];
        }

        $signals = [];
        foreach ($fired as $ruleId => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $rule = $this->rule((string) $ruleId);
            if ($rule === null) {
                continue;
            }
            $signals[] = new Signal(
                ruleId: (string) $ruleId,
                category: (string) ($meta['category'] ?? $rule->category),
                weight: (int) ($meta['weight'] ?? $rule->default_weight),
                confidence: (string) ($meta['confidence'] ?? 'medium'),
                reason: (string) ($meta['reason'] ?? 'carried forward'),
            );
        }

        return $signals;
    }

    /**
     * Apply the website-wide `threshold_override` (FR-46, §6.9).
     *
     * The override is rule-scoped in storage; the website-wide value is the
     * smallest non-null override present for the website. It replaces the
     * SUSPECT boundary only — the correlation guard remains authoritative, so a
     * lowered override can never let a single category reach INCIDENT (§6.3).
     */
    /**
     * Shift the classification band boundaries for one website (§6.9, FR-46).
     *
     * storage is rule-scoped, so the website-wide override is the smallest
     * positive value present across the website's rule settings. It is applied
     * as a delta from the canonical warning boundary, which preserves the shape
     * of the bands: `info`, `warning` and `critical` all move by the same amount.
     *
     * `null`, zero and negative values are ignored and fall back to the canonical
     * configured thresholds. The correlation guard is not affected here; it is
     * applied after classification and remains authoritative.
     *
     * @return array{0: int, 1: int, 2: int} [info, warning, critical]
     */
    private function applyThresholdOverride(
        Website $website,
        int $infoThreshold,
        int $warningThreshold,
        int $criticalThreshold,
    ): array {
        unset($website);

        $override = null;
        foreach ($this->settings as $setting) {
            $value = $setting->threshold_override;
            if ($value === null || (int) $value <= 0) {
                continue;
            }
            $override = $override === null ? (int) $value : min($override, (int) $value);
        }

        if ($override === null) {
            return [$infoThreshold, $warningThreshold, $criticalThreshold];
        }

        $delta = $override - $warningThreshold;

        if ($delta === 0) {
            return [$infoThreshold, $warningThreshold, $criticalThreshold];
        }

        // Move every boundary by the same delta and keep the bands ordered and
        // non-negative so a misconfigured override can never invert them.
        $info = max(1, $infoThreshold + $delta);
        $warning = max($info, $warningThreshold + $delta);
        $critical = max($warning, $criticalThreshold + $delta);

        return [$info, $warning, $critical];
    }

    /*
    |--------------------------------------------------------------------------
    | Keyword / domain helpers
    |--------------------------------------------------------------------------
    */

    /** @return array<int, string> */
    private function ignoredKeywords(Website $website): array
    {
        $ignored = [];

        $global = Config::get('sentinel.detection_keywords.ignored_global', []);
        if (is_array($global)) {
            foreach ($global as $term) {
                $normalized = mb_strtolower(trim((string) $term));
                if ($normalized !== '') {
                    $ignored[$normalized] = true;
                }
            }
        }

        foreach ($this->settings as $setting) {
            $keywords = $setting->ignored_keywords;
            if (! is_array($keywords)) {
                continue;
            }
            foreach ($keywords as $term) {
                $normalized = mb_strtolower(trim((string) $term));
                if ($normalized !== '') {
                    $ignored[$normalized] = true;
                }
            }
        }

        unset($website);

        return array_keys($ignored);
    }

    /** @param array<int, string> $ignored */
    private function isIgnored(string $term, array $ignored): bool
    {
        return in_array(mb_strtolower(trim($term)), $ignored, true);
    }

    /**
     * Hosts the baseline already knows about (canonical `baseline_script_srcs()`
     * / `baseline_domains` for RULE-SEO-004): the baseline's external domain
     * set. Schema-faithful - reuses the existing
     * `website_baselines.external_domains` column, no new columns.
     *
     * @return array<int, string>
     */
    private function baselineScriptHosts(?WebsiteBaseline $baseline): array
    {
        $hosts = [];

        if ($baseline !== null) {
            $domains = is_array($baseline->external_domains) ? $baseline->external_domains : [];
            foreach ($domains as $domain) {
                $normalized = BaselineComparator::normalizeDomain((string) $domain);
                if ($normalized !== '') {
                    $hosts[$normalized] = true;
                }
            }
        }

        return array_keys($hosts);
    }

    /** @return array<int, string> */
    private function ignoredDomains(): array
    {
        $domains = Config::get('sentinel.links.ignored_domains_global', []);
        if (! is_array($domains)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($domain) => BaselineComparator::normalizeDomain((string) $domain),
            $domains,
        )));
    }

    private function isSuspiciousDomain(string $domain): bool
    {
        $tlds = Config::get('sentinel.links.suspicious_tlds', []);
        if (! is_array($tlds)) {
            return false;
        }

        foreach ($tlds as $tld) {
            if ($domain !== '' && str_ends_with($domain, mb_strtolower((string) $tld))) {
                return true;
            }
        }

        foreach ($this->tier1() as $term) {
            if (str_contains($domain, str_replace(' ', '', mb_strtolower($term)))) {
                return true;
            }
        }

        return false;
    }

    private function containsTier1(string $text): bool
    {
        $haystack = mb_strtolower($text);
        foreach ($this->tier1() as $term) {
            if (str_contains($haystack, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    private function containsTier2(string $text): bool
    {
        $haystack = mb_strtolower($text);
        foreach ($this->tier2() as $term) {
            if (str_contains($haystack, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function tier1(): array
    {
        $tier = Config::get('sentinel.detection_keywords.tier1', []);

        return is_array($tier) ? array_values($tier) : [];
    }

    /** @return array<int, string> */
    private function tier2(): array
    {
        $tier = Config::get('sentinel.detection_keywords.tier2', []);

        return is_array($tier) ? array_values($tier) : [];
    }

    /** @return array<int, string> */
    private function tier3(): array
    {
        $tier = Config::get('sentinel.detection_keywords.tier3', []);

        return is_array($tier) ? array_values($tier) : [];
    }

    /** @param array<int, Signal> $signals */
    private function push(array &$signals, ?Signal $signal): void
    {
        if ($signal !== null) {
            $signals[] = $signal;
        }
    }
}
