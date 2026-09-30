<?php

declare(strict_types=1);

namespace App\Services\Detection;

use Illuminate\Support\Facades\Config;

/**
 * Deterministic, bounded content extraction from monitored HTML
 * (DETECTION-RULES.md §8.6, §8.7, §8.8).
 *
 * Security posture (SECURITY.md §6, AGENTS.md §9):
 *  - the HTML is treated purely as data — never executed, never fetched;
 *  - every pattern is bounded and non-catastrophic (no nested quantifiers);
 *  - work is capped by the probe's response body limit before we ever get here;
 *  - extracted URLs are recorded as evidence only and are never dereferenced.
 *
 * Output shape (stored verbatim in `check_extractions`):
 *
 *  keywords           => array<string,int>  tier-1/2/3 term => occurrence count
 *  external_domains   => array<int,string>  off-site registrable domains
 *  suspicious_patterns => array<string,mixed>
 *      visible_text_length  int
 *      hidden_text_length   int
 *      hidden_keywords      array<int,string>
 *      hidden_anchors       array<int,string>  domains behind CSS-hidden anchors
 *      doorway              bool
 *      new_script_src       bool
 *      obfuscated_inline    bool
 */
final class ContentExtractor
{
    private const MAX_TERM_HITS = 5000;

    /**
     * @return array{keywords: array<string,int>, external_domains: array<int,string>, suspicious_patterns: array<string,mixed>}
     */
    public function extract(string $html, ?string $baseUrl = null): array
    {
        $visibleText = $this->visibleText($html);
        $hiddenText = $this->hiddenText($html);
        $baseHost = BaselineComparator::domainFromUrl($baseUrl);

        return [
            'keywords' => $this->keywordCounts($visibleText),
            'external_domains' => $this->externalDomains($html, $baseHost),
            'suspicious_patterns' => [
                'visible_text_length' => mb_strlen($visibleText),
                'hidden_text_length' => mb_strlen($hiddenText),
                'hidden_keywords' => $this->termsIn($hiddenText, $this->tier1()),
                'hidden_anchors' => $this->hiddenAnchorDomains($html, $baseHost),
                'doorway' => $this->looksLikeDoorway($html),
                'new_script_src' => $this->hasThirdPartyScript($html, $baseHost),
                'obfuscated_inline' => $this->hasInlineObfuscation($html),
            ],
        ];
    }

    /**
     * Visible text: strip comments, script/style, then markup; decode entities.
     */
    public function visibleText(string $html): string
    {
        $text = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        $text = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $text) ?? $text;
        // Remove CSS-hidden blocks from visible text so they count only as hidden.
        $text = preg_replace(
            '/<[^>]*style\s*=\s*"[^"]*(display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0|font-size\s*:\s*0)[^"]*"[^>]*>.*?<\/[a-z]+>/is',
            ' ',
            $text,
        ) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Text inside elements hidden from users (the canonical injection vector).
     */
    public function hiddenText(string $html): string
    {
        $pattern = '/<([a-z][a-z0-9]*)\b[^>]*style\s*=\s*"[^"]*'
            .'(?:display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0|font-size\s*:\s*0)'
            .'[^"]*"[^>]*>(.*?)<\/\1>/is';

        if (preg_match_all($pattern, $html, $matches) === false) {
            return '';
        }

        $collected = '';
        foreach ($matches[2] as $chunk) {
            $plain = html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $collected .= ' '.$plain;
        }

        return trim(preg_replace('/\s+/u', ' ', $collected) ?? $collected);
    }

    /**
     * @return array<string,int>
     */
    public function keywordCounts(string $text): array
    {
        $haystack = mb_strtolower($text);
        $counts = [];

        foreach (array_merge($this->tier1(), $this->tier2(), $this->tier3()) as $term) {
            $needle = mb_strtolower($term);
            if ($needle === '') {
                continue;
            }
            $count = substr_count($haystack, $needle);
            if ($count > 0) {
                $counts[$term] = min($count, self::MAX_TERM_HITS);
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,string>
     */
    private function termsIn(string $text, array $terms): array
    {
        $haystack = mb_strtolower($text);
        $found = [];
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($haystack, mb_strtolower($term))) {
                $found[] = $term;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return array<int,string>
     */
    public function externalDomains(string $html, string $baseHost): array
    {
        $domains = [];
        if (preg_match_all('/href\s*=\s*"([^"]+)"/i', $html, $matches) !== false) {
            foreach ($matches[1] as $href) {
                $domain = BaselineComparator::domainFromUrl($href);
                if ($domain !== '' && $domain !== $baseHost) {
                    $domains[$domain] = true;
                }
            }
        }

        $result = array_keys($domains);
        sort($result);

        return $result;
    }

    /**
     * Domains behind anchors that are hidden via inline CSS (RULE-LNK-004).
     *
     * @return array<int,string>
     */
    public function hiddenAnchorDomains(string $html, string $baseHost): array
    {
        $pattern = '/<a\b[^>]*style\s*=\s*"[^"]*'
            .'(?:display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0|font-size\s*:\s*0)'
            .'[^"]*"[^>]*href\s*=\s*"([^"]+)"[^>]*>/is';

        $domains = [];
        if (preg_match_all($pattern, $html, $matches) !== false) {
            foreach ($matches[1] as $href) {
                $domain = BaselineComparator::domainFromUrl($href);
                if ($domain !== '' && $domain !== $baseHost) {
                    $domains[$domain] = true;
                }
            }
        }

        // Also handle href-before-style ordering.
        $pattern2 = '/<a\b[^>]*href\s*=\s*"([^"]+)"[^>]*style\s*=\s*"[^"]*'
            .'(?:display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0|font-size\s*:\s*0)[^"]*"[^>]*>/is';
        if (preg_match_all($pattern2, $html, $matches2) !== false) {
            foreach ($matches2[1] as $href) {
                $domain = BaselineComparator::domainFromUrl($href);
                if ($domain !== '' && $domain !== $baseHost) {
                    $domains[$domain] = true;
                }
            }
        }

        $result = array_keys($domains);
        sort($result);

        return $result;
    }

    private function looksLikeDoorway(string $html): bool
    {
        $haystack = mb_strtolower($html);
        $markers = 0;
        foreach (['link alternatif', 'daftar', 'situs slot', 'maxwin'] as $marker) {
            if (str_contains($haystack, $marker)) {
                $markers++;
            }
        }

        return $markers >= 3;
    }

    private function hasThirdPartyScript(string $html, string $baseHost): bool
    {
        if (preg_match_all('/<script\b[^>]*src\s*=\s*"([^"]+)"/i', $html, $matches) === false) {
            return false;
        }

        foreach ($matches[1] as $src) {
            $domain = BaselineComparator::domainFromUrl($src);
            if ($domain !== '' && $domain !== $baseHost) {
                return true;
            }
        }

        return false;
    }

    private function hasInlineObfuscation(string $html): bool
    {
        return str_contains($html, 'eval(')
            || str_contains($html, 'fromCharCode')
            || str_contains($html, 'unescape(')
            || str_contains($html, 'atob(')
            || str_contains($html, "\u{200B}");
    }

    /**
     * @return array<int,string>
     */
    private function tier1(): array
    {
        $tier = Config::get('sentinel.detection_keywords.tier1', []);

        return is_array($tier) ? $tier : [];
    }

    /**
     * @return array<int,string>
     */
    private function tier2(): array
    {
        $tier = Config::get('sentinel.detection_keywords.tier2', []);

        return is_array($tier) ? $tier : [];
    }

    /**
     * @return array<int,string>
     */
    private function tier3(): array
    {
        $tier = Config::get('sentinel.detection_keywords.tier3', []);

        return is_array($tier) ? $tier : [];
    }
}
