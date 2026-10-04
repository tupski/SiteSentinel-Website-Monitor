<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3d — regression guard for the removal of the legacy `html.dark` bridge.
 *
 * Phase 2 shipped a compatibility shim in resources/css/app.css that re-mapped a
 * handful of light-only Tailwind utilities (`bg-white`, `bg-slate-50`,
 * `text-slate-*`, `border-slate-*`, `divide-slate-200`) onto dark colours via
 * `html.dark` descendant selectors. Phases 3a–3c migrated every view onto the
 * flip-aware semantic tokens, so the bridge was deleted.
 *
 * These assertions are deliberately semantic, not file-shape brittle:
 *   - the bridge may not silently return (no `html.dark <utility>` overrides),
 *   - the token layer must keep defining the dark values, so dark mode cannot
 *     quietly degrade into the light palette,
 *   - served views must not embed bare light-only surface wrappers.
 */
final class NoLegacyDarkBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function appCss(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    public function test_app_css_defines_no_html_dark_component_overrides(): void
    {
        $css = $this->appCss();

        // The Phase-2 bridge: `html.dark .bg-white {…}`, `html.dark .text-slate-700`,
        // `html.dark .divide-slate-200 > ...`. None may survive.
        preg_match_all('/html\.dark\s+\.[A-Za-z0-9_-]+/', $css, $matches);

        $this->assertSame(
            [],
            $matches[0],
            'Legacy html.dark utility bridge selectors must not exist in app.css: '.implode(', ', $matches[0])
        );
    }

    public function test_app_css_keeps_the_dark_token_layer(): void
    {
        $css = $this->appCss();

        // The theme switch hinges on a `.dark` scope that redefines the surface
        // tokens. Guard the load-bearing ones rather than every single token.
        $this->assertMatchesRegularExpression(
            '/\.dark\s*\{[^}]*--surface-elevated:\s*#[0-9a-fA-F]{3,8}/s',
            $css,
            'The `.dark` scope must keep overriding --surface-elevated.'
        );

        // `viewport`-level dark activation is what the no-FOUC bootstrap flips.
        $this->assertStringContainsString('@custom-variant dark', $css);
    }

    public function test_served_views_carry_no_bare_light_only_surface_wrappers(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $pages = [
            'login' => (string) $this->get(route('login'))->assertOk()->getContent(),
            'dashboard' => (string) $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent(),
            'websites.index' => (string) $this->actingAs($admin)->get(route('admin.websites.index'))->assertOk()->getContent(),
        ];

        foreach ($pages as $name => $html) {
            // A bare `bg-white` class token (not `bg-white/…` shades) is the
            // hallmark of an unmigrated light-only surface.
            $this->assertDoesNotMatchRegularExpression(
                '/\bbg-white\b/',
                $html,
                "Page [{$name}] still renders a bare `bg-white` surface wrapper."
            );

            // Positive control: the page is actually token-aware.
            $this->assertStringContainsString('bg-surface-elevated', $html, "Page [{$name}] is not using the surface token.");
        }
    }
}
