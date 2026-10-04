<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;

/**
 * Phase 2 shared UI primitives (ADR-033 / ADR-034).
 *
 * These tests assert STRUCTURAL hooks — variants, accessibility wiring, the
 * horizontally-scrollable table container — never raw colour values, which are
 * brittle and belong to the token layer. Where meaningful we assert that a
 * component carries `dark:` classes as a non-brittle proxy for dark support.
 */
final class UiPrimitivesTest extends TestCase
{
    public function test_button_renders_default_primary_variant(): void
    {
        $html = $this->blade('<x-ui.button>Save</x-ui.button>');

        $html->assertSee('<button', false);
        $html->assertSee('type="button"', false);
        $html->assertSee('bg-primary', false);
        $html->assertSee('focus-visible:ring-focus', false);
        $html->assertSee('disabled:pointer-events-none', false);
        $html->assertSee('Save');
    }

    public function test_button_variants_render_distinct_markers(): void
    {
        $this->blade('<x-ui.button variant="primary">P</x-ui.button>')
            ->assertSee('bg-primary', false);

        $this->blade('<x-ui.button variant="secondary">S</x-ui.button>')
            ->assertSee('bg-surface-elevated', false);

        $this->blade('<x-ui.button variant="outline">O</x-ui.button>')
            ->assertSee('border-border-muted', false);

        $this->blade('<x-ui.button variant="ghost">G</x-ui.button>')
            ->assertSee('text-text-muted', false);

        $html = $this->blade('<x-ui.button variant="danger">D</x-ui.button>');
        $html->assertSee('bg-danger', false);
        $html->assertSee('text-danger-foreground', false);
    }

    public function test_button_sizes_render_distinct_padding(): void
    {
        $this->blade('<x-ui.button size="sm">S</x-ui.button>')
            ->assertSee('px-3 py-1.5 text-sm', false);

        $this->blade('<x-ui.button size="lg">L</x-ui.button>')
            ->assertSee('px-5 py-2.5 text-base', false);
    }

    public function test_button_renders_an_anchor_when_href_is_given(): void
    {
        $html = $this->blade('<x-ui.button :href="\'/admin\'">Go</x-ui.button>');

        $html->assertSee('<a href="/admin"', false);
        $html->assertDontSee('<button', false);
    }

    public function test_icon_only_button_passes_through_aria_label_and_is_square(): void
    {
        $html = $this->blade(
            '<x-ui.button iconOnly size="md" aria-label="Close dialog">×</x-ui.button>'
        );

        $html->assertSee('aria-label="Close dialog"', false);
        $html->assertSee('h-9 w-9', false);
    }

    public function test_card_renders_header_actions_and_body(): void
    {
        $html = $this->blade(
            '<x-ui.card title="Websites" subtitle="Monitored">'.
            '<x-slot name="actions"><a href="#">Add</a></x-slot>'.
            '<p>Body</p></x-ui.card>'
        );

        $html->assertSee('Websites');
        $html->assertSee('Monitored');
        $html->assertSee('Add');
        $html->assertSee('Body');
        $html->assertSee('bg-surface-elevated', false);
        $html->assertSee('border-border', false);
    }

    public function test_card_can_disable_padding(): void
    {
        $html = $this->blade('<x-ui.card :padding="false"><p>Body</p></x-ui.card>');

        $html->assertSee('Body');
        $this->assertStringNotContainsString('p-4', (string) $html);
    }

    public function test_table_wraps_itself_in_a_horizontal_scroll_container(): void
    {
        $html = $this->blade(
            '<x-ui.table>'.
            '<x-ui.table-head><tr><th>Name</th></tr></x-ui.table-head>'.
            '<x-ui.table-body><tr><td>Example</td></tr></x-ui.table-body>'.
            '</x-ui.table>'
        );

        // Requirement §12 — tables must never cause page-level horizontal overflow.
        $html->assertSee('overflow-x-auto', false);
        $html->assertSee('<table', false);
        $html->assertSee('bg-surface-muted', false);
        $html->assertSee('divide-border', false);
        $html->assertSee('Example');
    }

    public function test_table_empty_state_renders_spanning_message(): void
    {
        $html = $this->blade(
            '<x-ui.table><x-ui.table-body>'.
            '<x-ui.table-empty :columns="3" title="No records" description="Nothing here yet." />'.
            '</x-ui.table-body></x-ui.table>'
        );

        $html->assertSee('colspan="3"', false);
        $html->assertSee('No records');
        $html->assertSee('Nothing here yet.');
    }

    public function test_badge_renders_each_variant_distinctly(): void
    {
        $this->blade('<x-ui.badge>Neutral</x-ui.badge>')
            ->assertSee('bg-surface-muted', false);

        $this->blade('<x-ui.badge variant="success">Up</x-ui.badge>')
            ->assertSee('bg-success-muted', false);

        $this->blade('<x-ui.badge variant="warning">Warn</x-ui.badge>')
            ->assertSee('bg-warning-muted', false);

        $this->blade('<x-ui.badge variant="danger">Down</x-ui.badge>')
            ->assertSee('bg-danger-muted', false);

        $this->blade('<x-ui.badge variant="info">Info</x-ui.badge>')
            ->assertSee('bg-info-muted', false);
    }

    public function test_alert_renders_variants_title_and_role(): void
    {
        $html = $this->blade(
            '<x-ui.alert variant="danger" title="Failed">Something went wrong.</x-ui.alert>'
        );

        $html->assertSee('role="alert"', false);
        $html->assertSee('bg-danger-muted', false);
        $html->assertSee('Failed');
        $html->assertSee('Something went wrong.');
    }

    /**
     * Requirement 22 — backward compatibility. An alert is dismissible only
     * when explicitly opted in, so every existing (persistent) usage must
     * render exactly as before: no close button, no Alpine hooks, no `x-cloak`.
     */
    public function test_alert_is_persistent_by_default(): void
    {
        $html = $this->blade('<x-ui.alert variant="danger">Something went wrong.</x-ui.alert>');

        $html->assertDontSee('Dismiss notification', false);
        $html->assertDontSee('x-data="flashMessage"', false);
        $html->assertDontSee('x-cloak', false);
        $this->assertStringNotContainsString('<button', (string) $html);
    }

    public function test_dismissible_alert_renders_accessible_close_button(): void
    {
        $html = $this->blade('<x-ui.alert variant="success" :dismissible="true">Saved.</x-ui.alert>');

        // Alpine wiring — the component is registered in app.js, never inline.
        $html->assertSee('x-data="flashMessage"', false);
        $html->assertSee('x-show="visible"', false);
        $html->assertSee('x-cloak', false);

        // Real, keyboard-reachable button with an accessible name.
        $html->assertSee('<button', false);
        $html->assertSee('type="button"', false);
        $html->assertSee('aria-label="Dismiss notification"', false);

        // Dismissal is a local click handler that must not bubble to a parent.
        $html->assertSee('x-on:click.stop="dismiss()"', false);

        // Semantic hover/focus tokens (correct in both themes, no `dark:`).
        $html->assertSee('hover:bg-surface-hover', false);
        $html->assertSee('focus-visible:ring-focus', false);
    }

    public function test_dismissible_alert_accepts_a_custom_close_label(): void
    {
        $html = $this->blade(
            '<x-ui.alert variant="success" :dismissible="true" dismiss-label="Close message">Saved.</x-ui.alert>'
        );

        $html->assertSee('aria-label="Close message"', false);
    }

    public function test_empty_state_renders_icon_title_description_and_action(): void
    {
        $html = $this->blade(
            '<x-ui.empty-state title="Nothing yet" description="Add your first website.">'.
            '<x-slot name="icon"><svg></svg></x-slot>'.
            '<x-slot name="action"><a href="/add">Add</a></x-slot>'.
            '</x-ui.empty-state>'
        );

        $html->assertSee('Nothing yet');
        $html->assertSee('Add your first website.');
        $html->assertSee('text-text-subtle', false);
        $html->assertSee('/add');
    }

    public function test_input_select_and_textarea_share_the_same_dark_safe_vocabulary(): void
    {
        $input = $this->blade('<x-ui.input name="q" placeholder="Search" />');
        $input->assertSee('bg-surface-elevated', false);
        $input->assertSee('text-text', false);
        $input->assertSee('placeholder:text-text-subtle', false);
        $input->assertSee('focus:ring-focus', false);
        $input->assertSee('disabled:cursor-not-allowed', false);

        $select = $this->blade('<x-ui.select name="s"><option>One</option></x-ui.select>');
        $select->assertSee('<select', false);
        $select->assertSee('bg-surface-elevated', false);

        $textarea = $this->blade('<x-ui.textarea name="t">Body</x-ui.textarea>');
        $textarea->assertSee('<textarea', false);
        $textarea->assertSee('bg-surface-elevated', false);
    }

    public function test_input_error_state_is_signalled(): void
    {
        $html = $this->blade('<x-ui.input name="q" :invalid="true" />');

        $html->assertSee('aria-invalid="true"', false);
        $html->assertSee('border-danger', false);
    }

    public function test_checkbox_and_radio_use_theme_accent_so_they_stay_visible(): void
    {
        $checkbox = $this->blade('<x-ui.checkbox name="c" label="Enable" />');
        $checkbox->assertSee('type="checkbox"', false);
        $checkbox->assertSee('text-primary', false);
        $checkbox->assertSee('Enable');

        $radio = $this->blade('<x-ui.radio name="r" label="Pick" />');
        $radio->assertSee('type="radio"', false);
        $radio->assertSee('text-primary', false);
    }

    public function test_skeleton_renders_requested_number_of_lines(): void
    {
        $html = $this->blade('<x-ui.skeleton :lines="3" />');

        $html->assertSee('animate-pulse', false);
        $html->assertSee('bg-surface-muted', false);
        $html->assertSee('aria-hidden="true"', false);

        $this->assertSame(3, substr_count((string) $html, 'animate-pulse'));
    }

    public function test_key_components_reference_tokens_that_flip_in_dark_mode(): void
    {
        // Non-brittle proxy for dark support: the semantic token utilities are
        // defined on :root / .dark in app.css, so their presence means the
        // component inherits correct dark values without a `dark:` duplicate.
        $button = (string) $this->blade('<x-ui.button>Go</x-ui.button>');
        $this->assertStringContainsString('bg-primary', $button);

        $card = (string) $this->blade('<x-ui.card title="T"><p>B</p></x-ui.card>');
        $this->assertStringContainsString('border-border', $card);
        $this->assertStringContainsString('bg-surface-elevated', $card);

        $table = (string) $this->blade('<x-ui.table><x-ui.table-body></x-ui.table-body></x-ui.table>');
        $this->assertStringContainsString('bg-surface-elevated', $table);
        $this->assertStringContainsString('overflow-x-auto', $table);
    }
}
