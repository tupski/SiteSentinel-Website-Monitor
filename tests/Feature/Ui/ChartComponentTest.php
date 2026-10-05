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

    public function test_each_bar_carries_a_native_tooltip_title(): void
    {
        $html = $this->blade(
            '<x-ui.chart type="bar" title="Availability" :series="$series" />',
            ['series' => [
                ['label' => '2026-10-04 10:00', 'value' => 100],
                ['label' => '2026-10-04 11:00', 'value' => 50],
            ]]
        );

        $raw = (string) $html;

        // A per-bar <title> gives a tooltip without JS and is read by AT.
        $this->assertStringContainsString('<title>2026-10-04 10:00: 100</title>', $raw);
        $this->assertStringContainsString('<title>2026-10-04 11:00: 50</title>', $raw);

        // The styled hover tooltip is wired to the registered Alpine component.
        $this->assertStringContainsString('x-data="chartTooltip()"', $raw);
        $this->assertStringContainsString('x-on:mouseenter="show(', $raw);
        $this->assertStringContainsString('x-text="label"', $raw);
    }

    public function test_tooltips_can_be_disabled(): void
    {
        $html = $this->blade(
            '<x-ui.chart type="bar" title="Availability" :series="$series" :tooltips="false" />',
            ['series' => [['label' => 'a', 'value' => 1]]]
        );

        $raw = (string) $html;

        // The native title remains (accessible); the Alpine hover layer is off.
        $this->assertStringContainsString('<title>a: 1</title>', $raw);
        $this->assertStringNotContainsString('chartTooltip', $raw);
    }

    public function test_bottom_axis_renders_per_point_labels_and_an_axis_caption(): void
    {
        $html = $this->blade(
            '<x-ui.chart type="bar" title="Response time" axis-label="Time checked (UTC)" :series="$series" />',
            ['series' => [
                ['label' => 'Alpha', 'axis' => '10:00', 'value' => 200],
                ['label' => 'Bravo', 'axis' => '11:00', 'value' => 400],
            ]]
        );

        $raw = (string) $html;

        // Each point renders its own bottom-axis tick (the time it was checked).
        $this->assertStringContainsString('>10:00</text>', $raw);
        $this->assertStringContainsString('>11:00</text>', $raw);
        // The axis caption labels what the bottom axis means.
        $this->assertStringContainsString('Time checked (UTC)', $raw);
    }

    public function test_data_table_can_be_disabled(): void
    {
        $html = $this->blade(
            '<x-ui.chart type="bar" title="Response time" :table="false" :series="$series" />',
            ['series' => [['label' => 'Alpha', 'value' => 200]]]
        );

        $raw = (string) $html;

        // The SVG still renders, but the label/value fallback table is gone.
        $this->assertStringContainsString('<svg', $raw);
        $this->assertStringNotContainsString('<table', $raw);
        $this->assertStringNotContainsString('<caption', $raw);
    }
}
