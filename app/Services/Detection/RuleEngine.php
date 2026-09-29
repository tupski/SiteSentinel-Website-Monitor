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

final class RuleEngine
{
    /** @var array<string, DetectionRule> */
    private array $ruleRegistry = [];

    /** @var array<string, array<int, WebsiteRuleSetting>> */
    private array $overrides = [];

    public function evaluate(
        Website $website,
        Check $check,
        ?CheckExtraction $extraction,
        ?WebsiteBaseline $baseline,
        Collection $priorChecks,
    ): DetectionResult {
        $this->loadRules($website);

        $signals = [];

        // Availability signals always evaluated.
        $signals = array_merge($signals, $this->evaluateAvailability($website, $check));

        // If check is down with no body, skip content-family rules.
        $downNoBody = $check->availability_state === 'DOWN' && ($check->http_status === null && $check->error_type !== null);

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

        return $this->scoreAndClassify($website, $check, $signals, $priorChecks);
    }

    private function loadRules(Website $website): void
    {
        if ($this->ruleRegistry !== []) {
            return;
        }

        $rules = DetectionRule::where('enabled', true)->get();
        foreach ($rules as $rule) {
            $this->ruleRegistry[$rule->rule_id] = $rule;
        }

        $settings = WebsiteRuleSetting::where('website_id', $website->id)->get();
        foreach ($settings as $setting) {
            $this->overrides[$setting->detection_rule_id][$website->id] = $setting;
        }
    }

    private function resolveRule(string $ruleId): ?DetectionRule
    {
        return $this->ruleRegistry[$ruleId] ?? null;
    }

    private function settingFor(DetectionRule $rule, Website $website): ?WebsiteRuleSetting
    {
        return $this->overrides[$rule->id][$website->id] ?? null;
    }

    private function ruleEnabled(string $ruleId, Website $website): bool
    {
        $rule = $this->resolveRule($ruleId);
        if (! $rule) {
            return false;
        }

        $setting = $this->settingFor($rule, $website);
        if ($setting && $setting->enabled !== null) {
            return $setting->enabled;
        }

        return true;
    }

    private function ruleWeight(string $ruleId, Website $website, int $fallbackWeight): int
    {
        $rule = $this->resolveRule($ruleId);
        if (! $rule) {
            return $fallbackWeight;
        }

        $setting = $this->settingFor($rule, $website);
        if ($setting && $setting->weight_override !== null) {
            return $setting->weight_override;
        }

        return $rule->default_weight;
    }

    private function emit(
        Website $website,
        string $ruleId,
        string $category,
        string $confidence,
        string $reason,
        array $evidence = [],
    ): ?Signal {
        if (! $this->ruleEnabled($ruleId, $website)) {
            return null;
        }

        $weight = $this->ruleWeight($ruleId, $website, 0);
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

    private function evaluateAvailability(Website $website, Check $check): array
    {
        $signals = [];

        if ($check->http_status !== null && $check->http_status >= 500 && $check->http_status <= 599) {
            $signal = $this->emit($website, 'RULE-AV-001', 'availability', 'high', 'HTTP failure (5xx)', ['status' => $check->http_status]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if (
            $check->http_status !== null
            && $check->http_status !== $website->expected_status
            && ! in_array($check->http_status, [304, 401], true)
            && ! ($check->http_status >= 500 && $check->http_status <= 599)
        ) {
            $signal = $this->emit($website, 'RULE-AV-002', 'availability', 'high', "Expected status {$website->expected_status}, got {$check->http_status}", ['status' => $check->http_status]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if ($check->error_type === 'timeout') {
            $signal = $this->emit($website, 'RULE-AV-003', 'availability', 'high', 'Request timed out', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if ($check->error_type === 'dns_failure') {
            $signal = $this->emit($website, 'RULE-AV-004', 'availability', 'high', 'DNS resolution failure', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if (in_array($check->error_type, ['connection_refused', 'connection_unreachable', 'tls_handshake_failure'], true)) {
            $signal = $this->emit($website, 'RULE-AV-005', 'availability', 'high', 'Connection or TLS handshake failure', ['error_type' => $check->error_type]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        $budget = ($website->timeout_seconds ?? 10) * 1000;
        if ($check->duration_ms !== null && $check->duration_ms >= 0.80 * $budget) {
            $signal = $this->emit($website, 'RULE-AV-006', 'availability', 'low', 'Response time above threshold', ['duration_ms' => $check->duration_ms]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function evaluateSsl(Website $website, Check $check, ?WebsiteBaseline $baseline): array
    {
        $signals = [];

        if (! $website->monitor_ssl) {
            $signal = $this->emit($website, 'RULE-SSL-005', 'ssl', 'low', 'SSL monitoring disabled', []);
            if ($signal) {
                $signals[] = $signal;
            }

            return $signals;
        }

        if ($check->ssl_valid === false) {
            $cause = 'invalid';
            if ($check->ssl_expires_at !== null && $check->ssl_expires_at->isPast()) {
                $cause = 'expired';
            }
            $signal = $this->emit($website, 'RULE-SSL-001', 'ssl', 'high', "Invalid or expired certificate: {$cause}", []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if ($check->ssl_valid === false && $check->error_message !== null && str_contains(strtolower($check->error_message), 'hostname')) {
            $signal = $this->emit($website, 'RULE-SSL-002', 'ssl', 'high', 'Hostname mismatch', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if ($check->ssl_valid === false && $check->error_message !== null && (
            str_contains(strtolower($check->error_message), 'unable to get local issuer')
            || str_contains(strtolower($check->error_message), 'incomplete chain')
        )) {
            $signal = $this->emit($website, 'RULE-SSL-003', 'ssl', 'medium', 'Chain or issuer problem', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if ($check->ssl_expires_at !== null) {
            $days = now()->diffInDays($check->ssl_expires_at, false);
            if ($days <= 7) {
                $signal = $this->emit($website, 'RULE-SSL-004', 'ssl', 'medium', 'Certificate expires within 7 days', ['days' => $days]);
                if ($signal) {
                    $signals[] = $signal;
                }
            } elseif ($days <= 14) {
                $signal = $this->emit($website, 'RULE-SSL-004', 'ssl', 'medium', 'Certificate expires within 14 days', ['days' => $days]);
                if ($signal) {
                    $signals[] = $signal;
                }
            } elseif ($days <= 30) {
                $signal = $this->emit($website, 'RULE-SSL-004', 'ssl', 'medium', 'Certificate expires within 30 days', ['days' => $days]);
                if ($signal) {
                    $signals[] = $signal;
                }
            }
        }

        if ($baseline && $baseline->ssl_issuer && $check->ssl_issuer && $baseline->ssl_issuer !== $check->ssl_issuer) {
            $signal = $this->emit($website, 'RULE-SSL-003', 'ssl', 'medium', 'SSL issuer changed vs baseline', ['from' => $baseline->ssl_issuer, 'to' => $check->ssl_issuer]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function evaluateRedirect(Website $website, Check $check, ?WebsiteBaseline $baseline): array
    {
        $signals = [];

        if (! $website->monitor_redirects) {
            return $signals;
        }

        $chain = $check->redirect_chain ?? [];
        $hops = is_array($chain) ? count($chain) : 0;

        if ($hops > 0) {
            $signal = $this->emit($website, 'RULE-RED-001', 'redirect', 'high', 'Unexpected redirect present', ['hops' => $hops]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if ($check->final_url !== null) {
            $finalDomain = $this->registrableDomain($check->final_url);
            $expected = $website->expected_final_domain ?: ($baseline ? $this->registrableDomain($baseline->final_url) : null);
            if ($expected !== null && $finalDomain !== $expected) {
                $signal = $this->emit($website, 'RULE-RED-002', 'redirect', 'medium', 'Final URL domain differs from expected', ['expected' => $expected, 'actual' => $finalDomain]);
                if ($signal) {
                    $signals[] = $signal;
                }
            }
        }

        foreach ($chain as $hop) {
            $to = $hop['to_url'] ?? ($hop['to'] ?? null);
            if ($to !== null && $this->isSuspiciousRedirectTarget($to)) {
                $signal = $this->emit($website, 'RULE-RED-003', 'redirect', 'medium', 'Redirect to suspicious or external domain', ['target' => $to]);
                if ($signal) {
                    $signals[] = $signal;
                    break;
                }
            }
        }

        $baselineHops = $baseline && $baseline->final_url ? 1 : 0;
        if ($hops > max(2, $baselineHops + 2)) {
            $signal = $this->emit($website, 'RULE-RED-004', 'redirect', 'medium', "Redirect chain length anomaly: {$hops} hops", ['hops' => $hops]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        foreach ($chain as $hop) {
            $from = $hop['from_url'] ?? ($hop['from'] ?? null);
            $to = $hop['to_url'] ?? ($hop['to'] ?? null);
            if ($from !== null && $to !== null && str_starts_with(strtolower($from), 'https:') && str_starts_with(strtolower($to), 'http:')) {
                $signal = $this->emit($website, 'RULE-RED-005', 'redirect', 'high', 'HTTPS to HTTP downgrade', ['from' => $from, 'to' => $to]);
                if ($signal) {
                    $signals[] = $signal;
                    break;
                }
            }
        }

        if ($check->error_type === 'ssrf_blocked') {
            $signal = $this->emit($website, 'RULE-RED-006', 'redirect', 'high', 'Redirect to private or blocked target', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function evaluateContentFingerprint(Website $website, Check $check, ?WebsiteBaseline $baseline, ?CheckExtraction $extraction): array
    {
        $signals = [];

        if ($baseline !== null && $check->content_hash !== null && $check->content_hash !== $baseline->content_hash) {
            $signal = $this->emit($website, 'RULE-CNT-001', 'content-fingerprint', 'low', 'Content hash changed from baseline', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        $expectedTitle = $website->expected_title ?: ($baseline ? $baseline->title : null);
        if ($expectedTitle !== null && $check->title !== null) {
            if (trim($check->title) === '') {
                $signal = $this->emit($website, 'RULE-CNT-002', 'content-fingerprint', 'high', 'Page title missing', []);
                if ($signal) {
                    $signals[] = $signal;
                }
            } elseif ($this->normalize($check->title) !== $this->normalize($expectedTitle)) {
                if ($this->containsTier1Keyword($check->title) || $this->containsTier2Keyword($check->title)) {
                    $signal = $this->emit($website, 'RULE-CNT-002', 'content-fingerprint', 'high', 'Page title replaced with spam content', ['title' => $check->title]);
                } else {
                    $signal = $this->emit($website, 'RULE-CNT-001', 'content-fingerprint', 'low', 'Page title changed', ['title' => $check->title]);
                }
                if ($signal) {
                    $signals[] = $signal;
                }
            }
        }

        if ($baseline !== null && $baseline->response_size_bytes > 0 && $check->response_size_bytes !== null) {
            $ratio = $check->response_size_bytes / $baseline->response_size_bytes;
            if ($ratio >= 1.5 || $ratio <= 0.5) {
                $signal = $this->emit($website, 'RULE-CNT-003', 'content-fingerprint', 'medium', 'Major structural change (body size ratio '.round($ratio, 2).')', ['ratio' => $ratio]);
                if ($signal) {
                    $signals[] = $signal;
                }
            }
        }

        if ($check->http_status === 200 && $check->response_size_bytes !== null && $check->response_size_bytes < 512) {
            $signal = $this->emit($website, 'RULE-CNT-004', 'content-fingerprint', 'medium', 'Content became empty or unreachable', ['size' => $check->response_size_bytes]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        $suspicious = $extraction?->suspicious_patterns ?? [];
        if (! empty($suspicious) && ($suspicious['large_hidden_block'] ?? false)) {
            $signal = $this->emit($website, 'RULE-CNT-005', 'content-fingerprint', 'medium', 'Large hidden-text or hidden-link block', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function evaluateKeywords(Website $website, Check $check, ?WebsiteBaseline $baseline, ?CheckExtraction $extraction): array
    {
        $signals = [];

        if ($extraction === null) {
            return $signals;
        }

        $ignored = $this->ignoredKeywords($website);
        $keywords = $extraction->keywords ?? [];
        $baselineCounts = $baseline?->keyword_counts ?? [];

        $newTier1 = [];
        $visibleText = is_array($keywords) ? implode(' ', $keywords) : (string) $keywords;
        foreach ($this->tier1Keywords() as $kw) {
            if (in_array($kw, $ignored, true)) {
                continue;
            }
            if (stripos($visibleText, $kw) !== false && (empty($baselineCounts) || ! isset($baselineCounts[$kw]))) {
                $newTier1[] = $kw;
            }
        }

        if (count($newTier1) > 0) {
            $signal = $this->emit($website, 'RULE-KW-001', 'content-keyword', 'low', 'Tier-1 keyword present in visible text', ['keywords' => $newTier1]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if (count($newTier1) >= 5) {
            $signal = $this->emit($website, 'RULE-KW-002', 'content-keyword', 'medium', 'Tier-1 keyword cluster detected', ['keywords' => $newTier1]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        foreach ($this->tier2Keywords() as $kw) {
            if (in_array($kw, $ignored, true)) {
                continue;
            }
            if (stripos($visibleText, $kw) !== false) {
                $signal = $this->emit($website, 'RULE-KW-003', 'content-keyword', 'medium', 'Tier-2 keyword cluster in suspicious density', ['keyword' => $kw]);
                if ($signal) {
                    $signals[] = $signal;
                    break;
                }
            }
        }

        if ($check->title !== null && ($this->containsTier1Keyword($check->title) || $this->containsTier2Keyword($check->title))) {
            $headTerms = array_filter($this->tier1Keywords(), fn ($kw) => stripos($check->title, $kw) !== false);
            if (! empty($headTerms)) {
                $signal = $this->emit($website, 'RULE-KW-004', 'content-keyword', 'high', 'Suspicious keyword in title', ['keywords' => array_values($headTerms)]);
                if ($signal) {
                    $signals[] = $signal;
                }
            }
        }

        if (! empty($suspicious) && ($suspicious['hidden_keywords'] ?? false)) {
            $signal = $this->emit($website, 'RULE-KW-005', 'content-keyword', 'medium', 'Hidden or obfuscated keyword pattern', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function evaluateExternalLinks(Website $website, Check $check, ?WebsiteBaseline $baseline, ?CheckExtraction $extraction): array
    {
        $signals = [];

        if ($extraction === null) {
            return $signals;
        }

        $domains = $extraction->external_domains ?? [];
        if (! is_array($domains)) {
            $domains = [];
        }

        $baselineDomains = [];
        if ($baseline !== null && $baseline->external_link_count > 0 && isset($baseline->keyword_counts['_external_domains'])) {
            $baselineDomains = (array) $baseline->keyword_counts['_external_domains'];
        }

        $currentDomains = array_map(fn ($d) => $this->registrableDomain($d), $domains);
        $currentDomains = array_unique(array_filter($currentDomains));

        $newDomains = array_diff($currentDomains, $baselineDomains, $this->ignoredDomains());
        if (! empty($newDomains)) {
            $signal = $this->emit($website, 'RULE-LNK-001', 'external-link', 'medium', 'New external domain vs baseline', ['domains' => array_values($newDomains)]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        foreach ($currentDomains as $domain) {
            if ($this->isSuspiciousDomain($domain)) {
                $signal = $this->emit($website, 'RULE-LNK-002', 'external-link', 'medium', 'Suspicious TLD or domain pattern', ['domain' => $domain]);
                if ($signal) {
                    $signals[] = $signal;
                    break;
                }
            }
        }

        $baselineCount = $baseline ? $baseline->external_link_count : 0;
        $currentCount = count($currentDomains);
        if ($currentCount >= 50 && $currentCount >= 3 * max($baselineCount, 1)) {
            $signal = $this->emit($website, 'RULE-LNK-003', 'external-link', 'medium', 'Link farm detected', ['count' => $currentCount]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if ($currentCount >= 20 && $currentCount >= 5 * max($baselineCount, 1)) {
            $signal = $this->emit($website, 'RULE-LNK-005', 'external-link', 'high', 'Mass outbound link injection vs baseline', ['count' => $currentCount, 'baseline' => $baselineCount]);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function evaluateSeoPatterns(Website $website, Check $check, ?WebsiteBaseline $baseline, ?CheckExtraction $extraction): array
    {
        $signals = [];

        if ($extraction === null) {
            return $signals;
        }

        $suspicious = $extraction->suspicious_patterns ?? [];
        if (! is_array($suspicious)) {
            $suspicious = [];
        }

        if (! empty($suspicious['doorway'])) {
            $signal = $this->emit($website, 'RULE-SEO-001', 'seo-pattern', 'medium', 'Spam SEO doorway pattern detected', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if (! empty($suspicious['new_script_src']) || ! empty($suspicious['obfuscated_inline'])) {
            $signal = $this->emit($website, 'RULE-SEO-004', 'seo-pattern', 'medium', 'Suspicious script injection', []);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function scoreAndClassify(Website $website, Check $check, array $signals, Collection $priorChecks): DetectionResult
    {
        $score = 0;
        $categories = [];

        foreach ($signals as $signal) {
            $multiplier = RuleConfig::confidenceMultiplier($signal->confidence);
            $contribution = (int) round($signal->weight * $multiplier);
            $score += $contribution;
            $categories[$signal->category] = true;
        }

        // Carry-forward decay from prior checks (simplified: 0.5 for the immediately preceding check only).
        if ($priorChecks->isNotEmpty()) {
            $prior = $priorChecks->first();
            if ($prior->score > 0) {
                $score += (int) round($prior->score * 0.5);
            }
        }

        $infoThreshold = (int) Config::get('sentinel.scoring.threshold_info', 1);
        $warningThreshold = (int) Config::get('sentinel.scoring.threshold_warning', 8);
        $criticalThreshold = (int) Config::get('sentinel.scoring.threshold_critical', 15);
        $guardMin = (int) Config::get('sentinel.scoring.correlation_guard_min_categories', 2);

        if ($score < $infoThreshold) {
            $securityState = 'OK';
        } elseif ($score < $warningThreshold) {
            $securityState = 'INFO';
        } elseif ($score < $criticalThreshold) {
            $securityState = 'SUSPECT';
        } else {
            $distinctCategories = count($categories);
            if ($distinctCategories >= $guardMin) {
                $securityState = 'INCIDENT';
            } else {
                $securityState = 'SUSPECT';
                $guardCapped = true;
            }
        }

        $guardCapped ??= false;

        return new DetectionResult(
            availabilityState: $check->availability_state ?? 'DOWN',
            securityState: $securityState,
            score: $score,
            guardCapped: $guardCapped,
            signals: $signals,
        );
    }

    private function ignoredKeywords(Website $website): array
    {
        $global = Config::get('sentinel.keywords.ignored_global', []);
        if (! is_array($global)) {
            $global = [];
        }

        $settings = WebsiteRuleSetting::where('website_id', $website->id)
            ->whereNotNull('ignored_keywords')
            ->pluck('ignored_keywords')
            ->flatten()
            ->unique()
            ->map(fn ($k) => mb_strtolower((string) $k))
            ->all();

        return array_unique(array_merge($global, $settings));
    }

    private function ignoredDomains(): array
    {
        $domains = Config::get('sentinel.links.ignored_domains_global', []);

        return is_array($domains) ? $domains : [];
    }

    private function registrableDomain(?string $url): string
    {
        if ($url === null) {
            return '';
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === false || $host === null) {
            $host = $url;
        }
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    private function isSuspiciousRedirectTarget(string $url): bool
    {
        $domain = $this->registrableDomain($url);
        if ($domain === '') {
            return false;
        }

        $suspiciousTlds = ['.top', '.xyz', '.click', '.loan', '.bid', '.download'];
        foreach ($suspiciousTlds as $tld) {
            if (str_ends_with($domain, $tld)) {
                return true;
            }
        }

        foreach ($this->tier1Keywords() as $kw) {
            if (stripos($domain, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isSuspiciousDomain(string $domain): bool
    {
        $suspiciousTlds = ['.top', '.xyz', '.click', '.loan', '.bid', '.download'];
        foreach ($suspiciousTlds as $tld) {
            if (str_ends_with($domain, $tld)) {
                return true;
            }
        }

        return false;
    }

    private function containsTier1Keyword(string $text): bool
    {
        foreach ($this->tier1Keywords() as $kw) {
            if (stripos($text, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    private function containsTier2Keyword(string $text): bool
    {
        foreach ($this->tier2Keywords() as $kw) {
            if (stripos($text, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    private function tier1Keywords(): array
    {
        return ['maxwin', 'rtp slot', 'situs slot', 'togel', 'bandar', 'gacor', 'judi online', 'link alternatif', 'scatter hitam'];
    }

    private function tier2Keywords(): array
    {
        return ['jackpot', 'casino', 'betting', 'slot', 'rtp', 'deposit', 'withdraw', 'taruhan'];
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
