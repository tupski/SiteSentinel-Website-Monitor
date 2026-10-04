<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Check;
use App\Models\Snapshot;
use App\Models\StatusPage;

/**
 * Enumeration resistance (STATUS-PAGE.md §10.2, §12.4).
 *
 * No internal identifier, path, or hash may appear in the public response, in
 * element ids/classes/keys/URLs. Row identifiers are opaque, stable, and
 * sorted; no total count is exposed.
 */
final class EnumerationTest extends StatusPageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setMode(StatusPage::MODE_PUBLIC);
    }

    private function showUrl(): string
    {
        return route('status.show', ['statusPage' => $this->defaultPage()->slug]);
    }

    private function jsonUrl(): string
    {
        return route('status.json', ['statusPage' => $this->defaultPage()->slug]);
    }

    public function test_no_website_or_incident_ids_paths_or_hashes_in_body(): void
    {
        $website = $this->makeWebsite([
            'status_alias' => 'Alpha',
            'url' => 'https://example-canary.test/',
            'host' => 'example-canary.test',
        ]);

        $check = Check::create([
            'website_id' => $website->id,
            'check_key' => 'enum-check-key',
            'started_at' => now('UTC'),
            'content_hash' => 'enum-content-hash-canary',
            'resolved_ip' => '10.88.88.88',
            'final_url' => 'https://example-canary.test/deep/path',
        ]);

        Snapshot::create([
            'website_id' => $website->id,
            'check_id' => $check->id,
            'html_path' => '/snap/enum-canary.html',
            'captured_at' => now('UTC'),
        ]);

        $this->makeIncident($website, [
            'type' => 'security',
            'severity' => 'CRITICAL',
            'triggered_rules' => ['RULE-ENUM-777' => ['weight' => 7]],
            'dedupe_key' => 'security:website:'.$website->id,
        ]);

        $html = (string) $this->get($this->showUrl())->getContent();
        $json = (string) $this->getJson($this->jsonUrl())->getContent();

        $forbidden = [
            'website_id',
            'example-canary.test',
            'enum-content-hash-canary',
            '10.88.88.88',
            '/snap/enum-canary.html',
            'RULE-ENUM-777',
            'dedupe_key',
            'enum-check-key',
            '/deep/path',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $html, "HTML leaked '{$needle}'");
            $this->assertStringNotContainsString($needle, $json, "JSON leaked '{$needle}'");
        }

        // The numeric row id must not appear in any identifier context
        // (attribute value, JSON value, key, or URL fragment).
        $id = (string) $website->id;
        foreach ([
            'id="'.$id.'"',
            '"'.$id.'"',
            '="'.$id.'"',
            'website/'.$id,
            '/'.$id.'"',
        ] as $context) {
            $this->assertStringNotContainsString($context, $html, "HTML leaked id context '{$context}'");
            $this->assertStringNotContainsString($context, $json, "JSON leaked id context '{$context}'");
        }
    }

    public function test_opaque_indices_are_stable_sorted_and_non_derived(): void
    {
        $this->makeWebsite(['status_alias' => 'Zulu', 'name' => 'Zulu']);
        $this->makeWebsite(['status_alias' => 'Alpha', 'name' => 'Alpha']);
        $this->makeWebsite(['status_alias' => 'Mike', 'name' => 'Mike']);

        $services = $this->servicesJson();

        $this->assertSame(['s-1', 's-2', 's-3'], array_column($services, 'opaqueIndex'));
        $this->assertSame(['Alpha', 'Mike', 'Zulu'], array_column($services, 'displayName'));

        // Indices are positional, not derived from the row's database id.
        foreach ($services as $service) {
            $this->assertMatchesRegularExpression('/^s-\d+$/', (string) $service['opaqueIndex']);
        }
    }

    public function test_opaque_indices_are_stable_across_requests(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->makeWebsite(['status_alias' => 'Bravo']);

        $first = array_column($this->servicesJson(), 'opaqueIndex');
        $second = array_column($this->servicesJson(), 'opaqueIndex');

        $this->assertSame($first, $second);
    }

    public function test_no_total_count_is_exposed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeWebsite(['status_alias' => 'Service '.$i]);
        }

        $json = (string) $this->getJson($this->jsonUrl())->getContent();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('services', $decoded);
        // The DTO allowlist is exactly these three keys.
        $this->assertSame(['banner', 'services', 'updatedDayBucket'], array_keys($decoded));
    }

    public function test_public_output_has_no_admin_links_or_internal_paths(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);

        $html = (string) $this->get($this->showUrl())->getContent();

        $this->assertStringNotContainsString('/admin', $html);
        $this->assertStringNotContainsString('incidents/', $html);
        $this->assertStringNotContainsString('status-settings', $html);
        // No data-id / id attributes derived from internal ids.
        $this->assertDoesNotMatchRegularExpression('/id="[^"]*website[^"]*"/i', $html);
    }

    public function test_uniform_error_shapes_do_not_leak_ids(): void
    {
        $website = $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->makeIncident($website);

        $this->setMode(StatusPage::MODE_PRIVATE);
        $html = (string) $this->get($this->showUrl())->getContent();
        $json = (string) $this->getJson($this->jsonUrl())->getContent();

        foreach (['Alpha', 'website_id', 'dedupe_key'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
            $this->assertStringNotContainsString($needle, $json);
        }
    }
}
