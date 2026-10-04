<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;

/**
 * Requirement 25/26 — the status-page auto-refresh + local-time footer logic
 * lives in `app.js` as registered Alpine components (AGENTS.md §7: Alpine
 * component logic is never inline in a Blade `x-data="{...}"` object). This
 * mirrors the `flashMessage`/`sidebar` registration guards.
 */
final class StatusAutoRefreshJsTest extends TestCase
{
    private function appJs(): string
    {
        $source = file_get_contents(resource_path('js/app.js'));
        $this->assertIsString($source);

        return $source;
    }

    private function showView(): string
    {
        $source = file_get_contents(resource_path('views/status/show.blade.php'));
        $this->assertIsString($source);

        return $source;
    }

    public function test_status_refresh_component_is_registered_with_lifecycle_hooks(): void
    {
        $js = $this->appJs();

        $this->assertStringContainsString("Alpine.data('statusRefresh'", $js);

        // Lifecycle: init wires the timer; destroy() must clean it up.
        $this->assertStringContainsString('init()', $js);
        $this->assertStringContainsString('destroy()', $js);

        // Non-overlap guard, visibility pause, drift-free countdown, persistence.
        $this->assertStringContainsString('loading', $js);
        $this->assertStringContainsString('visibilitychange', $js);
        $this->assertStringContainsString('document.visibilityState', $js);
        $this->assertStringContainsString('setInterval', $js);
        $this->assertStringContainsString('clearInterval', $js);
        $this->assertStringContainsString('localStorage', $js);

        // The refresh re-fetches the EXISTING JSON projection route.
        $this->assertStringContainsString('fetch(', $js);
        $this->assertStringContainsString('status.json', $this->showView());
    }

    public function test_status_local_time_component_is_registered(): void
    {
        $js = $this->appJs();

        $this->assertStringContainsString("Alpine.data('statusLocalTime'", $js);
        // Local conversion uses the visitor's own locale/timezone.
        $this->assertStringContainsString('Intl.DateTimeFormat', $js);
    }

    public function test_chart_tooltip_component_is_registered(): void
    {
        $js = $this->appJs();

        // The SVG chart hover tooltip is a registered Alpine component (never
        // inline in Blade, per AGENTS.md §7).
        $this->assertStringContainsString("Alpine.data('chartTooltip'", $js);
        $this->assertStringContainsString('show(', $js);
        $this->assertStringContainsString('hide()', $js);
    }

    public function test_status_view_binds_registered_components_not_inline_objects(): void
    {
        $view = $this->showView();

        $this->assertStringContainsString('x-data="statusRefresh(', $view);
        $this->assertStringContainsString('x-data="statusLocalTime(', $view);

        // The logic is never inlined as an `x-data="{...}"` object.
        $this->assertStringNotContainsString('x-data="{', $view);

        // Region ids used by the partial re-render + footer timestamp.
        $this->assertStringContainsString('id="status-banner"', $view);
        $this->assertStringContainsString('id="status-services"', $view);
        $this->assertStringContainsString('id="status-last-update"', $view);
    }

    public function test_status_view_offers_the_required_interval_options(): void
    {
        $view = $this->showView();

        // 1 / 5 / 10 / 30 / 60 minutes, default 1 minute (60s).
        $this->assertStringContainsString("'value' => 60", $view);
        $this->assertStringContainsString("'value' => 300", $view);
        $this->assertStringContainsString("'value' => 600", $view);
        $this->assertStringContainsString("'value' => 1800", $view);
        $this->assertStringContainsString("'value' => 3600", $view);
    }
}
