<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPageSetting;
use Illuminate\Support\Carbon;

/**
 * Staleness / freshness (STATUS-PAGE.md §6.4, §12.4).
 *
 * A website with no recent successful check MUST NOT be shown as Operational;
 * the stale threshold is max(2 × interval, floor).
 */
final class StalenessTest extends StatusPageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setMode(StatusPageSetting::MODE_PUBLIC);
    }

    public function test_fresh_within_threshold_renders_its_label(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));

        $this->makeWebsite([
            'status_alias' => 'Fresh',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'check_interval_seconds' => 300,
            // Threshold = max(2*300, 300) = 600s; 100s old is fresh.
            'last_checked_at' => Carbon::now('UTC')->subSeconds(100),
        ]);

        $this->assertSame('Operational', $this->labelFor('Fresh'));
    }

    public function test_fresh_at_exactly_threshold_is_still_fresh(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));

        $this->makeWebsite([
            'status_alias' => 'Boundary',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'check_interval_seconds' => 300,
            'last_checked_at' => Carbon::now('UTC')->subSeconds(600),
        ]);

        $this->assertSame('Operational', $this->labelFor('Boundary'));
    }

    public function test_stale_beyond_threshold_renders_unknown_never_operational(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));

        $this->makeWebsite([
            'status_alias' => 'Stale',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'check_interval_seconds' => 300,
            // 700s old > 600s threshold.
            'last_checked_at' => Carbon::now('UTC')->subSeconds(700),
        ]);

        $label = $this->labelFor('Stale');
        $this->assertSame('Unknown', $label);
        $this->assertNotSame('Operational', $label);
    }

    public function test_stale_uses_the_floor_for_short_intervals(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));

        $this->makeWebsite([
            'status_alias' => 'ShortInterval',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'check_interval_seconds' => 60,
            // max(2*60, 300) = 300s; 301s old is stale.
            'last_checked_at' => Carbon::now('UTC')->subSeconds(301),
        ]);

        $this->assertSame('Unknown', $this->labelFor('ShortInterval'));
    }

    public function test_short_interval_still_fresh_within_floor(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));

        $this->makeWebsite([
            'status_alias' => 'ShortInterval',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'check_interval_seconds' => 60,
            'last_checked_at' => Carbon::now('UTC')->subSeconds(200),
        ]);

        $this->assertSame('Operational', $this->labelFor('ShortInterval'));
    }

    public function test_null_last_checked_at_is_unknown(): void
    {
        $this->makeWebsite([
            'status_alias' => 'Never',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'last_checked_at' => null,
        ]);

        $this->assertSame('Unknown', $this->labelFor('Never'));
    }

    public function test_clock_travel_flips_fresh_to_stale(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));

        $this->makeWebsite([
            'status_alias' => 'Ticking',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'check_interval_seconds' => 300,
            'last_checked_at' => Carbon::now('UTC'),
        ]);

        $this->assertSame('Operational', $this->labelFor('Ticking'));

        // Advance beyond max(2*300, 300) = 600s.
        $this->travel(11)->minutes();

        $this->assertSame('Unknown', $this->labelFor('Ticking'));
    }

    public function test_stale_site_does_not_mask_a_healthy_banner(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));

        $this->makeWebsite([
            'status_alias' => 'Fresh',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'check_interval_seconds' => 300,
            'last_checked_at' => Carbon::now('UTC'),
        ]);
        $this->makeWebsite([
            'status_alias' => 'Stale',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'check_interval_seconds' => 300,
            'last_checked_at' => Carbon::now('UTC')->subSeconds(700),
        ]);

        $response = $this->getJson(route('status.json'));
        $response->assertOk();
        // Unknown outranks Operational, so the worst label is Unknown.
        $response->assertJsonPath('banner', 'Unknown');
    }
}
