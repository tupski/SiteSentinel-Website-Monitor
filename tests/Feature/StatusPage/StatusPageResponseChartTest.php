<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Check;
use App\Models\StatusPage;
use Illuminate\Support\Carbon;

/**
 * Public response-time chart + service-card presentation (STATUS-PAGE.md §4.3,
 * §8.1; Requirement 23 UI rework).
 *
 * One bar per CHECKED website (taller = slower), a labelled bottom (time) axis,
 * NO label/value table, the status badge to the right of the site name, and a
 * dark-mode-readable active period pill. The exact `checks.duration_ms` figure
 * is never emitted — only a coarse rounded figure + a coarse time bucket.
 */
final class StatusPageResponseChartTest extends StatusPageTestCase
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

    private function timedCheck(int $websiteId, string $at, int $durationMs, string $state = 'UP'): void
    {
        Check::create([
            'website_id' => $websiteId,
            'check_key' => 'resp-'.$websiteId.'-'.$at,
            'started_at' => Carbon::parse($at, 'UTC'),
            'finished_at' => Carbon::parse($at, 'UTC'),
            'availability_state' => $state,
            'duration_ms' => $durationMs,
        ]);
    }

    public function test_chart_renders_one_bar_per_checked_website_with_a_time_axis(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:30:00', 'UTC'));

        $alpha = $this->makeWebsite(['status_alias' => 'Alpha']);
        $bravo = $this->makeWebsite(['status_alias' => 'Bravo']);
        $charlie = $this->makeWebsite(['status_alias' => 'Charlie']);
        // Delta has NO timed check — it must be omitted from the chart.
        $this->makeWebsite(['status_alias' => 'Delta']);

        $this->timedCheck($alpha->id, '2026-10-04 11:00:00', 1234);
        $this->timedCheck($bravo->id, '2026-10-04 10:00:00', 300);
        $this->timedCheck($charlie->id, '2026-10-04 12:00:00', 600);

        $html = (string) $this->get($this->showUrl())->getContent();

        // The page-level response-time chart section renders.
        $this->assertStringContainsString('data-status-response-chart', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Time checked (UTC)', $html);

        // Exactly one bar per CHECKED website (three), never one for Delta.
        $section = substr($html, (int) strpos($html, 'data-status-response-chart'));
        $this->assertSame(3, substr_count($section, '<rect'), 'one bar per checked website expected');

        // Each bar is labelled by its website; a taller bar means a slower
        // response, and the coarse rounded millisecond figure is shown.
        $this->assertStringContainsString('Alpha: 1250 ms', $html);
        $this->assertStringContainsString('Bravo: 300 ms', $html);
        $this->assertStringContainsString('Charlie: 600 ms', $html);
        $this->assertStringNotContainsString('<title>Delta:', $html);

        // The bottom axis is the coarse time each site was checked.
        $this->assertStringContainsString('11:00', $section);
        $this->assertStringContainsString('10:00', $section);

        // The exact millisecond figure is never emitted.
        $this->assertStringNotContainsString('1234', $html);
    }

    public function test_label_value_table_is_gone_from_the_public_page(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:30:00', 'UTC'));

        $alpha = $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->timedCheck($alpha->id, '2026-10-04 11:00:00', 500);

        $html = (string) $this->get($this->showUrl())->getContent();

        // The chart's visible label/value fallback table must not render on the
        // public status page (the tooltips + bottom axis carry the meaning).
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('<caption', $html);
        $this->assertStringNotContainsString('>Label<', $html);
    }

    public function test_status_badge_sits_to_the_right_of_the_site_name(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:30:00', 'UTC'));

        $this->makeWebsite([
            'status_alias' => 'Alpha',
            'status_availability' => 'UP',
            'status_security' => 'SUSPECT',
        ]);

        $html = (string) $this->get($this->showUrl())->getContent();

        // Within one service card the name is rendered first and the status
        // badge follows it (inline, right-aligned next to the name).
        $this->assertMatchesRegularExpression(
            '/data-status-service="s-1".*?<h3[^>]*>Alpha<\/h3>.*?data-service-label/s',
            $html,
            'the status badge must render to the right of the site name',
        );
    }

    public function test_active_period_pill_uses_theme_aware_foreground_token(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);

        $html = (string) $this->get($this->showUrl())->getContent();

        // The active pill pairs `bg-primary` with the flip-aware foreground
        // token, so in dark mode (where --primary is light) the label is dark
        // and stays readable. A hard-coded `text-white` would vanish.
        $this->assertStringContainsString('bg-primary text-primary-foreground', $html);
        $this->assertStringNotContainsString('bg-primary text-white', $html);
    }

    public function test_json_exposes_only_the_coarse_response_signal(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:30:00', 'UTC'));

        $alpha = $this->makeWebsite(['status_alias' => 'Alpha']);
        $delta = $this->makeWebsite(['status_alias' => 'Delta']);
        $this->timedCheck($alpha->id, '2026-10-04 11:00:00', 1234);
        // Delta has an availability check but no timing.
        Check::create([
            'website_id' => $delta->id,
            'check_key' => 'resp-delta-untimed',
            'started_at' => Carbon::parse('2026-10-04 11:00:00', 'UTC'),
            'finished_at' => Carbon::parse('2026-10-04 11:00:00', 'UTC'),
            'availability_state' => 'UP',
        ]);

        $response = $this->getJson(route('status.json', ['statusPage' => $this->defaultPage()->slug]));
        $response->assertOk();

        $services = collect($response->json('services'))->keyBy('displayName');

        // Coarse rounded figure + coarse time bucket only.
        $this->assertSame(1250, $services['Alpha']['responseMs']);
        $this->assertSame('11:00', $services['Alpha']['checkedAt']);

        // A website with no timed check carries neither key.
        $this->assertArrayNotHasKey('responseMs', $services['Delta']);
        $this->assertArrayNotHasKey('checkedAt', $services['Delta']);

        // The exact millisecond figure is never serialized.
        $this->assertStringNotContainsString('1234', (string) $response->getContent());
    }
}
