<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DetectionRule;
use Illuminate\Database\Seeder;

final class DetectionRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['rule_id' => 'RULE-AV-001', 'name' => 'HTTP failure (5xx)', 'category' => 'availability', 'severity' => 'WARNING', 'default_weight' => 5, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-AV-002', 'name' => 'Unexpected HTTP status', 'category' => 'availability', 'severity' => 'INFO', 'default_weight' => 4, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-AV-003', 'name' => 'Timeout', 'category' => 'availability', 'severity' => 'WARNING', 'default_weight' => 5, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-AV-004', 'name' => 'DNS resolution failure', 'category' => 'availability', 'severity' => 'WARNING', 'default_weight' => 6, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-AV-005', 'name' => 'Connection / TLS handshake failure', 'category' => 'availability', 'severity' => 'WARNING', 'default_weight' => 6, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-AV-006', 'name' => 'Response time above threshold', 'category' => 'availability', 'severity' => 'INFO', 'default_weight' => 2, 'enabled' => true, 'config' => ['confidence' => 'low']],
            ['rule_id' => 'RULE-SSL-001', 'name' => 'Invalid or expired certificate', 'category' => 'ssl', 'severity' => 'CRITICAL', 'default_weight' => 8, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-SSL-002', 'name' => 'Hostname mismatch', 'category' => 'ssl', 'severity' => 'WARNING', 'default_weight' => 8, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-SSL-003', 'name' => 'Chain or issuer problem', 'category' => 'ssl', 'severity' => 'INFO', 'default_weight' => 3, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-SSL-004', 'name' => 'Certificate nearing expiry', 'category' => 'ssl', 'severity' => 'INFO', 'default_weight' => 2, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-SSL-005', 'name' => 'SSL monitoring disabled', 'category' => 'ssl', 'severity' => 'INFO', 'default_weight' => 0, 'enabled' => true, 'config' => ['confidence' => 'low']],
            ['rule_id' => 'RULE-RED-001', 'name' => 'Unexpected redirect present', 'category' => 'redirect', 'severity' => 'WARNING', 'default_weight' => 6, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-RED-002', 'name' => 'Final URL domain differs from expected', 'category' => 'redirect', 'severity' => 'INFO', 'default_weight' => 5, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-RED-003', 'name' => 'Redirect to suspicious or external domain', 'category' => 'redirect', 'severity' => 'WARNING', 'default_weight' => 3, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-RED-004', 'name' => 'Redirect chain length anomaly', 'category' => 'redirect', 'severity' => 'INFO', 'default_weight' => 3, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-RED-005', 'name' => 'HTTPS to HTTP downgrade', 'category' => 'redirect', 'severity' => 'WARNING', 'default_weight' => 7, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-RED-006', 'name' => 'Redirect to private or blocked target', 'category' => 'redirect', 'severity' => 'WARNING', 'default_weight' => 9, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-CNT-001', 'name' => 'Homepage fingerprint / hash changed', 'category' => 'content-fingerprint', 'severity' => 'INFO', 'default_weight' => 1, 'enabled' => true, 'config' => ['confidence' => 'low']],
            ['rule_id' => 'RULE-CNT-002', 'name' => 'Page title changed / replaced', 'category' => 'content-fingerprint', 'severity' => 'WARNING', 'default_weight' => 6, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-CNT-003', 'name' => 'Major structural change', 'category' => 'content-fingerprint', 'severity' => 'INFO', 'default_weight' => 2, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-CNT-004', 'name' => 'Content became unreachable / empty', 'category' => 'content-fingerprint', 'severity' => 'WARNING', 'default_weight' => 5, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-CNT-005', 'name' => 'Large hidden-text / hidden-link block', 'category' => 'content-fingerprint', 'severity' => 'WARNING', 'default_weight' => 5, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-KW-001', 'name' => 'Tier-1 keyword present in visible text', 'category' => 'content-keyword', 'severity' => 'INFO', 'default_weight' => 2, 'enabled' => true, 'config' => ['confidence' => 'low']],
            ['rule_id' => 'RULE-KW-002', 'name' => 'Tier-1 keyword cluster', 'category' => 'content-keyword', 'severity' => 'WARNING', 'default_weight' => 4, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-KW-003', 'name' => 'Tier-2 keyword cluster in suspicious density', 'category' => 'content-keyword', 'severity' => 'INFO', 'default_weight' => 3, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-KW-004', 'name' => 'Suspicious keyword in title / meta / head', 'category' => 'content-keyword', 'severity' => 'WARNING', 'default_weight' => 6, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-KW-005', 'name' => 'Hidden / obfuscated keyword pattern', 'category' => 'content-keyword', 'severity' => 'WARNING', 'default_weight' => 5, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-LNK-001', 'name' => 'New external domain vs baseline', 'category' => 'external-link', 'severity' => 'INFO', 'default_weight' => 3, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-LNK-002', 'name' => 'Suspicious TLD / domain pattern', 'category' => 'external-link', 'severity' => 'INFO', 'default_weight' => 5, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-LNK-003', 'name' => 'Link farm', 'category' => 'external-link', 'severity' => 'WARNING', 'default_weight' => 5, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-LNK-004', 'name' => 'CSS-hidden anchor', 'category' => 'external-link', 'severity' => 'WARNING', 'default_weight' => 6, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-LNK-005', 'name' => 'Mass outbound link injection vs baseline', 'category' => 'external-link', 'severity' => 'WARNING', 'default_weight' => 6, 'enabled' => true, 'config' => ['confidence' => 'high']],
            ['rule_id' => 'RULE-SEO-001', 'name' => 'Spam SEO fingerprint pattern', 'category' => 'seo-pattern', 'severity' => 'WARNING', 'default_weight' => 3, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-SEO-002', 'name' => 'Cloaking indicator', 'category' => 'seo-pattern', 'severity' => 'WARNING', 'default_weight' => 5, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-SEO-003', 'name' => 'Injected sitemap / robots.txt anomaly', 'category' => 'seo-pattern', 'severity' => 'INFO', 'default_weight' => 4, 'enabled' => true, 'config' => ['confidence' => 'medium']],
            ['rule_id' => 'RULE-SEO-004', 'name' => 'Suspicious script injection', 'category' => 'seo-pattern', 'severity' => 'WARNING', 'default_weight' => 4, 'enabled' => true, 'config' => ['confidence' => 'medium']],
        ];

        foreach ($rules as $rule) {
            DetectionRule::updateOrCreate(
                ['rule_id' => $rule['rule_id']],
                $rule,
            );
        }
    }
}
