<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;

/**
 * Server-rendered inline SVG chart component (ADR-037).
 *
 * The component must render a responsive, accessible <svg> from a real series
 * (with a <title> and a visible data-table fallback), and must render an
 * explicit empty state — never a fabricated series — when there is no data.
 */
final class ChartComponentTest extends TestCase
{
    public function test_renders_a_responsive_svg_with_title_and_fallback_table(): void
    {
        $html = $this->blade(
            '<x-ui.chart type="bar" title="Incidents per day" :series="$series" />',
            ['series' => [
                ['label' => '2026-01-01', 'value' => 2],
                ['label' => '2026-01-02', 'value' => 5],
            ]]
        );

        $raw = (string) $html;

        $html->assertSee('<svg', false);
        $html->assertSee('viewBox', false);
        $html->assertSee('preserveAspectRatio', false);
        $html->assertSee('max-w-full', false);
        $html->assertSee('role="img"', false);
        $html->assertSee('<title>', false);
        $html->assertSee('Incidents per day', false);

        // Visible fallback table with the underlying numbers.
        $html->assertSee('<table', false);
        $html->assertSee('<caption', false);
        $this->assertStringContainsString('2026-01-02', $raw);
        $this->assertStringContainsString('>5<', $raw);
    }

    public function test_line_type_renders_a_polyline(): void
    {
        $html = $this->blade(
            '<x-ui.chart type="line" title="Availability" :series="$series" />',
            ['series' => [['label' => 'd1', 'value' => 100], ['label' => 'd2', 'value' => 90]]]
        );

        $html->assertSee('<polyline', false);
    }

    public function test_empty_series_renders_an_empty_state_without_svg_data(): void
    {
        $html = $this->blade(
            '<x-ui.chart title="Incidents per day" :series="[]" />',
            ['series' => []]
        );

        $raw = (string) $html;

        // An explicit empty state — never a placeholder/fabricated series.
        $html->assertSee('No data for the selected period.', false);
        $this->assertStringNotContainsString('<polyline', $raw);
        $this->assertStringNotContainsString('<rect', $raw);
    }
}
