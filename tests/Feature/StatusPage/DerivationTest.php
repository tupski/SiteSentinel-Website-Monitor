<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Check;
use App\Models\StatusPageSetting;

/**
 * Public label derivation (STATUS-PAGE.md §5.3, §6.6, §12.4).
 *
 * Only the allowlisted coarse labels may ever appear. SUSPECT must never map
 * to a security-flavoured label; INFO never lifts a label; UP + INCIDENT is
 * not an outage.
 */
final class DerivationTest extends StatusPageTestCase
{
    /** @var array<int, string> */
    private const ALLOWED = [
        'Operational',
        'Degraded',
        'Incident',
        'Partial Outage',
        'Major Outage',
        'Unknown',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setMode(StatusPageSetting::MODE_PUBLIC);
    }

    /**
     * @param  array<string, mixed>  $website
     */
    private function labelForWebsite(array $website, array $incident = []): string
    {
        $site = $this->makeWebsite(array_merge(['status_alias' => 'Alpha'], $website));
        if ($incident !== []) {
            $this->makeIncident($site, $incident);
        }

        return (string) $this->labelFor('Alpha');
    }

    public function test_operational_table_row(): void
    {
        $this->assertSame('Operational', $this->labelForWebsite([
            'status_availability' => 'UP',
            'status_security' => 'OK',
        ]));
    }

    public function test_info_never_lifts_a_label(): void
    {
        $this->assertSame('Operational', $this->labelForWebsite([
            'status_availability' => 'UP',
            'status_security' => 'INFO',
        ]));
    }

    public function test_suspect_maps_to_degraded_never_a_security_label(): void
    {
        $label = $this->labelForWebsite([
            'status_availability' => 'UP',
            'status_security' => 'SUSPECT',
        ]);

        $this->assertSame('Degraded', $label);
        $this->assertNotContains('SUSPECT', [$label]);
        $this->assertNotContains('Security', [$label]);
        $this->assertStringNotContainsStringIgnoringCase('security', $label);
    }

    public function test_up_with_warning_incident_is_degraded(): void
    {
        $this->assertSame('Degraded', $this->labelForWebsite(
            ['status_availability' => 'UP', 'status_security' => 'OK'],
            ['severity' => 'WARNING', 'type' => 'security'],
        ));
    }

    public function test_up_with_incident_security_is_incident_not_outage(): void
    {
        $label = $this->labelForWebsite([
            'status_availability' => 'UP',
            'status_security' => 'INCIDENT',
        ]);

        $this->assertSame('Incident', $label);
        $this->assertNotContains('Outage', [$label]);
    }

    public function test_up_with_critical_incident_is_incident_not_outage(): void
    {
        $label = $this->labelForWebsite(
            ['status_availability' => 'UP', 'status_security' => 'OK'],
            ['severity' => 'CRITICAL', 'type' => 'security'],
        );

        $this->assertSame('Incident', $label);
        $this->assertNotContains('Outage', [$label]);
    }

    public function test_down_with_warning_incident_is_partial_outage(): void
    {
        $this->assertSame('Partial Outage', $this->labelForWebsite(
            ['status_availability' => 'DOWN', 'status_security' => 'OK'],
            ['severity' => 'WARNING', 'type' => 'availability'],
        ));
    }

    public function test_down_without_critical_incident_is_partial_outage(): void
    {
        $this->assertSame('Partial Outage', $this->labelForWebsite([
            'status_availability' => 'DOWN',
            'status_security' => 'OK',
        ]));
    }

    public function test_down_with_critical_incident_is_major_outage(): void
    {
        $this->assertSame('Major Outage', $this->labelForWebsite(
            ['status_availability' => 'DOWN', 'status_security' => 'OK'],
            ['severity' => 'CRITICAL', 'type' => 'availability'],
        ));
    }

    public function test_resolved_incident_returns_to_operational(): void
    {
        $site = $this->makeWebsite([
            'status_alias' => 'Alpha',
            'status_availability' => 'UP',
            'status_security' => 'OK',
        ]);
        $this->makeIncident($site, ['severity' => 'CRITICAL', 'status' => 'RESOLVED']);

        $this->assertSame('Operational', $this->labelFor('Alpha'));
    }

    public function test_acknowledged_incident_still_counts_as_open(): void
    {
        $site = $this->makeWebsite([
            'status_alias' => 'Alpha',
            'status_availability' => 'UP',
            'status_security' => 'OK',
        ]);
        $this->makeIncident($site, ['severity' => 'CRITICAL', 'status' => 'ACKNOWLEDGED']);

        $this->assertSame('Incident', $this->labelFor('Alpha'));
    }

    public function test_empty_set_is_unknown_banner(): void
    {
        $response = $this->getJson(route('status.json'));
        $response->assertOk();
        $response->assertJsonPath('banner', 'Unknown');
        $response->assertJsonPath('services', []);
    }

    public function test_mixed_banner_is_the_worst_label(): void
    {
        $this->makeWebsite(['status_alias' => 'A', 'status_availability' => 'UP', 'status_security' => 'OK']);
        $this->makeWebsite(['status_alias' => 'B', 'status_availability' => 'UP', 'status_security' => 'SUSPECT']);
        $this->makeWebsite(['status_alias' => 'C', 'status_availability' => 'UP', 'status_security' => 'INCIDENT']);
        $this->makeWebsite(['status_alias' => 'D', 'status_availability' => 'DOWN', 'status_security' => 'OK']);

        $response = $this->getJson(route('status.json'));
        $response->assertOk();
        $response->assertJsonPath('banner', 'Partial Outage');
    }

    public function test_major_outage_outranks_everything(): void
    {
        $site = $this->makeWebsite(['status_alias' => 'A', 'status_availability' => 'UP', 'status_security' => 'OK']);
        $this->makeIncident($site, ['severity' => 'CRITICAL', 'type' => 'availability']);
        $site->status_availability = 'DOWN';
        $site->save();

        $this->makeWebsite(['status_alias' => 'B', 'status_availability' => 'UP', 'status_security' => 'INCIDENT']);

        $response = $this->getJson(route('status.json'));
        $response->assertOk();
        $response->assertJsonPath('banner', 'Major Outage');
    }

    public function test_every_label_is_allowlisted(): void
    {
        $this->makeWebsite(['status_alias' => 'A', 'status_availability' => 'UP', 'status_security' => 'OK']);
        $this->makeWebsite(['status_alias' => 'B', 'status_availability' => 'UP', 'status_security' => 'SUSPECT']);
        $this->makeWebsite(['status_alias' => 'C', 'status_availability' => 'UP', 'status_security' => 'INCIDENT']);
        $this->makeWebsite(['status_alias' => 'D', 'status_availability' => 'DOWN', 'status_security' => 'OK']);
        $this->makeWebsite(['status_alias' => 'E', 'last_checked_at' => null]);

        foreach ($this->servicesJson() as $service) {
            $this->assertContains($service['publicLabel'], self::ALLOWED);
        }
    }

    public function test_visibility_dependent_filtering(): void
    {
        $this->makeWebsite(['status_alias' => 'Visible', 'is_visible_on_status' => true]);
        $this->makeWebsite(['status_alias' => 'Hidden', 'is_visible_on_status' => false]);

        $services = $this->servicesJson();
        $names = array_column($services, 'displayName');

        $this->assertContains('Visible', $names);
        $this->assertNotContains('Hidden', $names);
    }

    public function test_history_section_toggles_with_config(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);

        config(['sentinel.status_page.history_enabled' => false]);
        $this->get(route('status.show'))->assertOk()->assertDontSee('History', false);

        config(['sentinel.status_page.history_enabled' => true]);
        $this->get(route('status.show'))->assertOk()->assertSee('History', false);
    }

    public function test_websites_are_ordered_by_alias_then_name(): void
    {
        $this->makeWebsite(['status_alias' => 'Zulu', 'name' => 'Zulu']);
        $this->makeWebsite(['status_alias' => 'Alpha', 'name' => 'Alpha']);
        $this->makeWebsite(['status_alias' => 'Mike', 'name' => 'Mike']);

        $services = $this->servicesJson();
        $this->assertSame(['Alpha', 'Mike', 'Zulu'], array_column($services, 'displayName'));
    }

    public function test_response_band_is_coarse_only(): void
    {
        $site = $this->makeWebsite(['status_alias' => 'Alpha']);

        Check::create([
            'website_id' => $site->id,
            'check_key' => 'band-check',
            'started_at' => now('UTC'),
            'finished_at' => now('UTC'),
            'duration_ms' => 120,
        ]);

        $services = $this->servicesJson();
        $this->assertSame('normal', $services[0]['responseBand']);
        // A coarse band only — never the exact millisecond figure.
        $this->assertStringNotContainsString('120', (string) json_encode($services[0]));
    }
}
