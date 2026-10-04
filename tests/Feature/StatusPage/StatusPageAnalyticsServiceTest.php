<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Check;
use App\Services\StatusPage\StatusPageAnalytics;
use Illuminate\Support\Carbon;

/**
 * Requirement 23 — StatusPageAnalytics aggregation from REAL data.
 *
 * Covers the sample-based uptime definition, per-website uptime, incident
 * counts/frequency, the availability + response-time series, and the explicit
 * "insufficient data" contract (no fabricated 0%).
 */
final class StatusPageAnalyticsServiceTest extends StatusPageTestCase
{
    private function analytics(): StatusPageAnalytics
    {
        return app(StatusPageAnalytics::class);
    }

    private function makeCheck(int $websiteId, string $state, Carbon $startedAt, ?int $durationMs = null): Check
    {
        return Check::create([
            'website_id' => $websiteId,
            'check_key' => 'k-'.uniqid('', true),
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addSecond(),
            'duration_ms' => $durationMs,
            'availability_state' => $state,
            'security_state' => 'OK',
            'score' => 0,
        ]);
    }

    public function test_period_allowlist_defaults_and_accepts_known_values(): void
    {
        $this->assertSame('7d', StatusPageAnalytics::resolvePeriod(null));
        $this->assertSame('7d', StatusPageAnalytics::resolvePeriod('nonsense'));
        $this->assertSame('7d', StatusPageAnalytics::resolvePeriod(123));
        $this->assertSame('24h', StatusPageAnalytics::resolvePeriod('24h'));
        $this->assertSame('90d', StatusPageAnalytics::resolvePeriod('90D'));
    }

    public function test_overall_and_per_website_uptime_are_sample_based(): void
    {
        $page = $this->defaultPage();
        $alpha = $this->makeWebsite(['status_page_id' => $page->id, 'status_alias' => 'Alpha']);
        $beta = $this->makeWebsite(['status_page_id' => $page->id, 'status_alias' => 'Beta']);

        $now = Carbon::now('UTC');

        // Alpha: 3 UP + 1 DOWN → 75%. Beta: 1 UP + 1 DOWN → 50%.
        $this->makeCheck($alpha->id, 'UP', $now->copy()->subDays(1));
        $this->makeCheck($alpha->id, 'UP', $now->copy()->subDays(2));
        $this->makeCheck($alpha->id, 'UP', $now->copy()->subDays(3));
        $this->makeCheck($alpha->id, 'DOWN', $now->copy()->subDays(4));
        $this->makeCheck($beta->id, 'UP', $now->copy()->subDays(1));
        $this->makeCheck($beta->id, 'DOWN', $now->copy()->subDays(2));

        $report = $this->analytics()->report($page, '7d', $now);

        // Overall: 4 UP / 6 total = 66.67%.
        $this->assertTrue($report['overallUptime']['available']);
        $this->assertSame(66.67, $report['overallUptime']['percent']);
        $this->assertSame(6, $report['overallUptime']['total']);

        $byName = collect($report['websites'])->keyBy('name');
        $this->assertSame(75.0, $byName['Alpha']['uptime']['percent']);
        $this->assertSame(50.0, $byName['Beta']['uptime']['percent']);
    }

    public function test_insufficient_data_when_no_checks_exist_never_fabricates(): void
    {
        $page = $this->defaultPage();
        $this->makeWebsite(['status_page_id' => $page->id, 'status_alias' => 'Alpha']);

        $report = $this->analytics()->report($page, '7d', Carbon::now('UTC'));

        $this->assertFalse($report['overallUptime']['available']);
        $this->assertNull($report['overallUptime']['percent']);
        $this->assertSame(0, $report['overallUptime']['total']);
        $this->assertFalse($report['hasChecks']);
        $this->assertFalse($report['responseTime']['available']);
        $this->assertNull($report['responseTime']['averageMs']);
        $this->assertSame([], $report['availabilitySeries']);
    }

    public function test_checks_outside_the_period_are_excluded(): void
    {
        $page = $this->defaultPage();
        $website = $this->makeWebsite(['status_page_id' => $page->id]);
        $now = Carbon::now('UTC');

        $this->makeCheck($website->id, 'UP', $now->copy()->subHours(2));
        // 10 days ago: outside a 7-day window.
        $this->makeCheck($website->id, 'DOWN', $now->copy()->subDays(10));

        $report = $this->analytics()->report($page, '7d', $now);

        $this->assertSame(1, $report['overallUptime']['total']);
        $this->assertSame(100.0, $report['overallUptime']['percent']);
    }

    public function test_incident_counts_frequency_and_daily_series(): void
    {
        $page = $this->defaultPage();
        $website = $this->makeWebsite(['status_page_id' => $page->id]);
        $now = Carbon::now('UTC');

        $this->makeIncident($website, ['severity' => 'CRITICAL', 'status' => 'DETECTED', 'type' => 'availability', 'detected_at' => $now->copy()->subDays(1)]);
        $this->makeIncident($website, ['severity' => 'WARNING', 'status' => 'RESOLVED', 'type' => 'security', 'detected_at' => $now->copy()->subDays(1)]);
        $this->makeIncident($website, ['severity' => 'INFO', 'status' => 'ACKNOWLEDGED', 'type' => 'security', 'detected_at' => $now->copy()->subDays(3)]);

        $report = $this->analytics()->report($page, '7d', $now);
        $incidents = $report['incidents'];

        $this->assertSame(3, $incidents['total']);
        $this->assertSame(2, $incidents['open']); // DETECTED + ACKNOWLEDGED
        $this->assertSame(1, $incidents['bySeverity']['CRITICAL']);
        $this->assertSame(1, $incidents['bySeverity']['WARNING']);
        $this->assertSame(1, $incidents['bySeverity']['INFO']);
        $this->assertSame(2, $incidents['byType']['security']);
        $this->assertSame(1, $incidents['byType']['availability']);

        // Frequency across 7 days: 3 / 7 per day, 3 per week.
        $this->assertSame(0.43, $incidents['perDay']);
        $this->assertSame(3.0, $incidents['perWeek']);

        // Daily series has two distinct days holding 2 + 1 incidents.
        $this->assertCount(2, $incidents['dailySeries']);
        $this->assertSame(3, collect($incidents['dailySeries'])->sum('value'));
    }

    public function test_response_time_series_derived_from_duration_ms(): void
    {
        $page = $this->defaultPage();
        $website = $this->makeWebsite(['status_page_id' => $page->id]);
        $now = Carbon::now('UTC');

        $this->makeCheck($website->id, 'UP', $now->copy()->subDay(), 100);
        $this->makeCheck($website->id, 'UP', $now->copy()->subDay()->subHour(), 200);
        $this->makeCheck($website->id, 'UP', $now->copy()->subDays(2), 400);

        $report = $this->analytics()->report($page, '7d', $now);
        $rt = $report['responseTime'];

        $this->assertTrue($rt['available']);
        // Average across three samples: (100 + 200 + 400) / 3 = 233.33 → 233.
        $this->assertSame(233, $rt['averageMs']);
        $this->assertSame(3, $rt['samples']);
        $this->assertCount(2, $rt['series']);
    }

    public function test_websites_of_another_page_are_never_included(): void
    {
        $pageA = $this->makePage(['slug' => 'tenant-a']);
        $pageB = $this->makePage(['slug' => 'tenant-b']);

        $alpha = $this->makeWebsite(['status_page_id' => $pageA->id, 'status_alias' => 'Alpha']);
        $beta = $this->makeWebsite(['status_page_id' => $pageB->id, 'status_alias' => 'Beta']);

        $now = Carbon::now('UTC');
        $this->makeCheck($alpha->id, 'UP', $now->copy()->subDay());
        $this->makeCheck($beta->id, 'DOWN', $now->copy()->subDay());

        $report = $this->analytics()->report($pageA, '7d', $now);

        $this->assertSame(1, $report['websiteCount']);
        $this->assertSame(100.0, $report['overallUptime']['percent']);
    }
}
