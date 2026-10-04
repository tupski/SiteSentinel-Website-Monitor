<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Check;
use App\Models\StatusPage;
use App\Services\StatusPage\PublicStatusPeriod;
use App\Services\StatusPage\StatusPageCache;
use Illuminate\Support\Carbon;

/**
 * Public availability history + period filter (STATUS-PAGE.md §4.3, §7.2;
 * PRD.md FR-79 "recent availability history").
 *
 * The public page may show per-bucket AVAILABILITY only. It must never emit
 * the exact response time, the security state, or any §4.2 never-public field.
 * The period is an allowlisted enum; an unknown value falls back to the
 * default and can never induce an unbounded query.
 */
final class StatusPageHistoryTest extends StatusPageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setMode(StatusPage::MODE_PUBLIC);
    }

    private function jsonUrl(string $period = ''): string
    {
        $url = route('status.json', ['statusPage' => $this->defaultPage()->slug]);

        return $period !== '' ? $url.'?period='.$period : $url;
    }

    private function check(int $websiteId, string $at, string $state): void
    {
        Check::create([
            'website_id' => $websiteId,
            'check_key' => 'hist-'.$websiteId.'-'.$at.'-'.$state,
            'started_at' => Carbon::parse($at, 'UTC'),
            'finished_at' => Carbon::parse($at, 'UTC'),
            'availability_state' => $state,
        ]);
    }

    public function test_default_period_is_24h_and_is_in_the_dto(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);

        $response = $this->getJson($this->jsonUrl());
        $response->assertOk();
        $response->assertJsonPath('period', PublicStatusPeriod::P24H);
        $response->assertJsonPath('periodLabel', 'Last 24 hours');
    }

    public function test_service_carries_uptime_and_bucketed_history(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:00:00', 'UTC'));

        $site = $this->makeWebsite(['status_alias' => 'Alpha', 'status_availability' => 'UP']);
        $this->check($site->id, '2026-10-04 10:05:00', 'UP');
        $this->check($site->id, '2026-10-04 10:35:00', 'DOWN');
        $this->check($site->id, '2026-10-04 11:05:00', 'UP');

        $services = $this->servicesJson();
        $alpha = $services[0];

        $this->assertTrue($alpha['uptime']['available']);
        $this->assertSame(3, $alpha['uptime']['total']);
        $this->assertSame(2, $alpha['uptime']['up']);
        $this->assertSame(1, $alpha['uptime']['down']);
        $this->assertSame(66.67, $alpha['uptime']['percent']);

        // Two hourly buckets: 10:00 (1/2 = 50%) and 11:00 (1/1 = 100%).
        $labels = array_column($alpha['history'], 'label');
        $this->assertSame(['2026-10-04 10:00', '2026-10-04 11:00'], $labels);
        // JSON round-trips a whole-number float as an int, so compare loosely.
        $this->assertEquals(50.0, $alpha['history'][0]['value']);
        $this->assertEquals(100.0, $alpha['history'][1]['value']);
        $this->assertSame(2, $alpha['history'][0]['total']);
    }

    public function test_exact_response_time_is_never_exposed_by_history(): void
    {
        $site = $this->makeWebsite(['status_alias' => 'Alpha']);

        Check::create([
            'website_id' => $site->id,
            'check_key' => 'hist-duration',
            'started_at' => now('UTC'),
            'finished_at' => now('UTC'),
            'duration_ms' => 4321,
            'availability_state' => 'UP',
        ]);

        $json = (string) $this->getJson($this->jsonUrl())->getContent();

        // The §4.3 boundary: the exact millisecond figure is never emitted.
        $this->assertStringNotContainsString('4321', $json);
        $this->assertStringNotContainsString('duration_ms', $json);
    }

    public function test_unknown_period_falls_back_to_default(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);

        $response = $this->getJson($this->jsonUrl('99y'));
        $response->assertOk();
        $response->assertJsonPath('period', PublicStatusPeriod::P24H);
    }

    public function test_period_filters_the_history_window(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:00:00', 'UTC'));

        $site = $this->makeWebsite(['status_alias' => 'Alpha']);
        // 3 days ago: inside 7d, outside 24h.
        $this->check($site->id, '2026-10-01 12:00:00', 'UP');
        // 1 hour ago: inside both.
        $this->check($site->id, '2026-10-04 11:00:00', 'UP');

        $day = $this->getJson($this->jsonUrl(PublicStatusPeriod::P24H))->json('services.0.history');
        $week = $this->getJson($this->jsonUrl(PublicStatusPeriod::P7D))->json('services.0.history');

        $this->assertCount(1, $day, '24h window must exclude the 3-day-old check');
        $this->assertCount(2, $week, '7d window must include the 3-day-old check');
    }

    public function test_history_html_renders_period_links_and_charts(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:00:00', 'UTC'));

        $site = $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->check($site->id, '2026-10-04 11:00:00', 'UP');

        $html = (string) $this->get(route('status.show', ['statusPage' => $this->defaultPage()->slug]))->getContent();

        // Server-rendered period filter links (no JS required).
        $this->assertStringContainsString('period=24h', $html);
        $this->assertStringContainsString('period=7d', $html);
        $this->assertStringContainsString('period=30d', $html);
        $this->assertStringContainsString('period=90d', $html);

        // The chart renders a bar with a native tooltip title.
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('<rect', $html);
        $this->assertStringContainsString('<title>', $html);
    }

    public function test_no_availability_data_renders_an_honest_empty_state(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);

        $services = $this->servicesJson();
        $this->assertFalse($services[0]['uptime']['available']);
        $this->assertNull($services[0]['uptime']['percent']);
        $this->assertSame([], $services[0]['history']);
    }

    public function test_period_never_leaks_into_cache_key_collision(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->defaultPage();
        $cache = app(StatusPageCache::class);

        $this->assertNotSame(
            $cache->key($page, PublicStatusPeriod::P24H),
            $cache->key($page, PublicStatusPeriod::P7D),
        );
    }
}
