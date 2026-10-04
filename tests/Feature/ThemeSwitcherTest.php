<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 — icon-only theme switcher (ADR-033).
 *
 * The control is icon-only when closed: the theme names must never appear
 * outside the opened `role="menu"` panel. These are HTTP/assertSee checks
 * (no Dusk), matching tests/Feature/BaseLayoutTest.php.
 */
final class ThemeSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private function adminHtml(): string
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');
        $response->assertOk();

        return (string) $response->getContent();
    }

    /**
     * Extract the collapsed trigger element (`<button data-theme-trigger …>`).
     */
    private function triggerMarkup(string $html): string
    {
        $marker = strpos($html, 'data-theme-trigger');
        $this->assertNotFalse($marker, 'theme switcher trigger must render');

        // Walk back to the opening `<button` so attributes are inside the slice.
        $start = strrpos(substr($html, 0, $marker), '<button');
        $this->assertNotFalse($start, 'theme switcher trigger must be a button');

        $end = strpos($html, '</button>', $marker);
        $this->assertNotFalse($end, 'theme switcher trigger must close');

        return substr($html, $start, $end - $start + strlen('</button>'));
    }

    /**
     * Strip every tag (and therefore every attribute) so only the text a user
     * actually sees is left — the correct way to assert "no theme words".
     */
    private function visibleText(string $html): string
    {
        return strip_tags($html);
    }

    public function test_closed_trigger_is_icon_only_with_aria_wiring(): void
    {
        $html = $this->adminHtml();
        $trigger = $this->triggerMarkup($html);

        $this->assertStringContainsString('aria-haspopup="menu"', $trigger);
        $this->assertStringContainsString('aria-expanded', $trigger);
        $this->assertStringContainsString('change theme', $trigger);

        // The trigger's rendered text is empty -> icon-only (its only content
        // is inline SVG, which carries no text nodes).
        $this->assertSame('', trim($this->visibleText($trigger)));
        $this->assertStringContainsString('<svg', $trigger);
    }

    public function test_no_theme_words_are_rendered_outside_the_dropdown_panel(): void
    {
        $html = $this->adminHtml();

        // Remove the opened dropdown panel, then assert none of the theme words
        // survive anywhere else on the page as visible text.
        $panelStart = strpos($html, 'role="menu"');
        $this->assertNotFalse($panelStart, 'theme dropdown panel must render');
        $panelEnd = strpos($html, '</div>', $panelStart);
        $outside = substr($html, 0, $panelStart).substr($html, $panelEnd);
        $text = $this->visibleText($outside);

        foreach (['Light', 'Dark', 'System', 'Theme', 'Appearance'] as $word) {
            $this->assertStringNotContainsString($word, $text, "theme words must not render outside the dropdown, found '{$word}'");
        }
    }

    public function test_dropdown_exposes_all_three_theme_choices(): void
    {
        $html = $this->adminHtml();

        $this->assertStringContainsString('role="menu"', $html);
        $this->assertSame(3, substr_count($html, 'role="menuitemradio"'));
        $this->assertStringContainsString('>Light<', $html);
        $this->assertStringContainsString('>Dark<', $html);
        $this->assertStringContainsString('>System<', $html);
    }

    public function test_active_option_is_marked_beyond_colour(): void
    {
        $html = $this->adminHtml();

        // Exactly three radio options, each exposing aria-checked, and each
        // carrying a conditionally-shown check glyph (marker beyond colour).
        $this->assertSame(3, substr_count($html, 'role="menuitemradio"'));
        $this->assertSame(3, substr_count($html, 'aria-checked="false"'));
        $this->assertSame(3, substr_count($html, 'x-bind:aria-checked'));
        $this->assertStringContainsString('M4.5 12.75l6 6 9-13.5', $html);
    }
}
