<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;

/**
 * Shared `x-ui.icon` component (ADR-037).
 *
 * The component renders a curated inline-SVG set from Heroicons-style outline
 * path data. Colour/contrast is inherited from the surrounding semantic text
 * colour (`stroke="currentColor"`), so the test asserts the structural hooks —
 * an `<svg>`, the stroke/aria defaults, and class pass-through — not raw colour
 * values.
 */
final class IconComponentTest extends TestCase
{
    /**
     * The full curated name set the component MUST support (ADR-037).
     *
     * @return list<string>
     */
    private function names(): array
    {
        return [
            'bell',
            'bell-alert',
            'chart-bar',
            'pencil',
            'link',
            'external-link',
            'chevron-left',
            'chevron-right',
            'chevron-double-left',
            'chevron-double-right',
            'check-circle',
            'x-circle',
            'exclamation-triangle',
            'x-mark',
            'pause-circle',
            'play-circle',
        ];
    }

    public function test_every_curated_name_renders_an_svg_with_path_data(): void
    {
        foreach ($this->names() as $name) {
            $html = $this->blade('<x-ui.icon name="'.$name.'" />');

            $raw = (string) $html;

            $html->assertSee('<svg', false);
            $this->assertStringContainsString('<path', $raw, "icon [{$name}] must render at least one <path>");
        }
    }

    public function test_icon_uses_current_color_and_is_aria_hidden_by_default(): void
    {
        $html = $this->blade('<x-ui.icon name="bell" />');

        $html->assertSee('stroke="currentColor"', false);
        $html->assertSee('aria-hidden="true"', false);
        $html->assertSee('focusable="false"', false);
    }

    public function test_icon_reflects_a_provided_class(): void
    {
        $html = $this->blade('<x-ui.icon name="check-circle" class="h-4 w-4 text-success" />');

        $html->assertSee('h-4 w-4 text-success', false);
    }

    public function test_unknown_icon_renders_an_empty_svg_without_error(): void
    {
        $html = $this->blade('<x-ui.icon name="does-not-exist" />');

        $html->assertSee('<svg', false);
        $this->assertStringNotContainsString('<path', (string) $html);
    }
}
