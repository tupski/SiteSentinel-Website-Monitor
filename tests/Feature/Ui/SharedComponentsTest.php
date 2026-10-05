<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * The shared Blade primitives render cleanly, the field component surfaces
 * validation errors, and the theme plumbing is present in every base layout
 * (ADR-033 / ADR-034).
 */
final class SharedComponentsTest extends TestCase
{
    public function test_x_modal_renders_with_title_and_slot(): void
    {
        $html = $this->blade(
            '<x-modal name="confirm" title="Confirm action"><p>Are you sure?</p></x-modal>'
        );

        $html->assertSee('role="dialog"', false);
        $html->assertSee('aria-modal="true"', false);
        $html->assertSee('Confirm action');
        $html->assertSee('Are you sure?');
    }

    /**
     * Regression guard: the modal's Alpine component must be referenced by name
     * (`x-data="modal"`) and its logic must live in `app.js`. The old inline
     * `x-data="{...}"` object embedded a selector with double quotes
     * (`[tabindex]:not([tabindex="-1"])`), which terminated the HTML attribute
     * early and made Alpine throw `Invalid or unexpected token` / `Illegal
     * invocation` on the bare `open` identifier.
     */
    public function test_x_modal_uses_registered_alpine_component_not_inline_object(): void
    {
        $html = $this->blade(
            '<x-modal name="confirm" title="Confirm action"><p>Are you sure?</p></x-modal>'
        );

        $raw = (string) $html;

        $html->assertSee('x-data="modal"', false);

        // The inline component definition must not reappear in any modal view.
        $this->assertStringNotContainsString('previousFocus:', $raw);
        $this->assertStringNotContainsString('onOpen()', $raw);

        // The focusable selector must NOT be present in rendered markup — it now
        // lives only in app.js, free of HTML-attribute quoting conflicts.
        $this->assertStringNotContainsString('[tabindex]:not([tabindex="-1"])', $raw);

        // Accessibility + behavior wiring is preserved.
        $html->assertSee('x-ref="panel"', false);
        $html->assertSee('x-on:keydown.escape.window="close()"', false);
        $html->assertSee('x-on:keydown.tab="trap($event)"', false);
        $html->assertSee('x-on:close-modal.window', false);
    }

    public function test_x_per_page_renders_the_whitelisted_options(): void
    {
        $html = $this->blade('<x-per-page />');

        $html->assertSee('id="per_page"', false);
        $html->assertSee('name="per_page"', false);
        $html->assertSeeInOrder(['10', '20', '50', '100', 'All']);
    }

    /**
     * The table primitive defaults to its own bordered card (unchanged for every
     * existing caller), and `flush` drops that nested card so a table inside a
     * section aligns flush with the section content (incident detail sections).
     */
    public function test_x_ui_table_defaults_to_a_bordered_card(): void
    {
        $html = $this->blade(
            '<x-ui.table><x-ui.table-body><tr><td>Cell</td></tr></x-ui.table-body></x-ui.table>'
        );

        $html->assertSee('overflow-hidden rounded-lg border border-border bg-surface-elevated shadow-sm', false);
        $html->assertSee('overflow-x-auto', false);
    }

    public function test_x_ui_table_flush_omits_the_nested_card(): void
    {
        $html = $this->blade(
            '<x-ui.table :flush="true"><x-ui.table-body><tr><td>Cell</td></tr></x-ui.table-body></x-ui.table>'
        );

        $raw = (string) $html;

        // No nested bordered card, but the scroll container (and the table) remain.
        $this->assertStringNotContainsString('overflow-hidden rounded-lg border border-border', $raw);
        $html->assertSee('overflow-x-auto', false);
        $html->assertSee('<table', false);
    }

    public function test_x_theme_switcher_renders_three_states(): void
    {
        $html = $this->blade('<x-theme-switcher />');

        // Icon-only dropdown (ADR-033): the three choices live in the menu panel.
        $html->assertSee('Light');
        $html->assertSee('Dark');
        $html->assertSee('System');
        $html->assertSee('x-data="themeMenu"', false);
        $html->assertSee('role="menu"', false);
        $html->assertSee("select('light')", false);
        $html->assertSee("select('dark')", false);
        $html->assertSee("select('system')", false);
    }

    public function test_x_form_field_renders_label_hint_and_help_modal(): void
    {
        $html = $this->blade(
            '<x-form.field name="example" label="Example" hint="A hint" help="A longer help text">'.
            '<input name="example" type="text"></x-form.field>'
        );

        $html->assertSee('Example');
        $html->assertSee('A hint');
        $html->assertSee('A longer help text');
        // Accessible help button that opens the help modal.
        $html->assertSee('aria-label="Help for Example"', false);
        $html->assertSee("open-modal', { name: 'help-field-example' }", false);
    }

    public function test_x_form_field_surfaces_validation_errors(): void
    {
        // Seed an error bag for the field name, then render.
        $this->app['view']->share('errors', new ViewErrorBag);

        $errors = new ViewErrorBag;
        $bag = new MessageBag;
        $bag->add('example', 'The example field is invalid.');
        $errors->put('default', $bag);
        $this->app['view']->share('errors', $errors);

        $html = $this->blade(
            '<x-form.field name="example" label="Example"><input name="example" type="text"></x-form.field>'
        );

        $html->assertSee('The example field is invalid.');
        $html->assertSee('role="alert"', false);
        $html->assertSee('field-example-error', false);
    }

    public function test_x_form_field_renders_eye_toggle_for_password_fields(): void
    {
        $html = $this->blade(
            '<x-form.field name="password" label="Password"><input id="password" name="password" type="password"></x-form.field>'
        );

        $html->assertSee('aria-label="Show password"', false);
        $html->assertSee('Toggle password visibility', false);
    }

    public function test_login_page_ships_the_theme_bootstrap_and_eye_toggle(): void
    {
        if (! file_exists(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Vite build not present — run `npm run build` (executed in CI).');
        }

        $response = $this->get('/');

        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString("localStorage.getItem('theme')", $html, 'no-FOUC bootstrap must be in <head>');
        $this->assertStringContainsString('data-theme', $html);
        $this->assertStringContainsString('aria-label="Show password"', $html);
    }

    /**
     * Requirement 22 — the dismissible flash component's logic lives in
     * `app.js` (AGENTS.md §7: Alpine component logic is never inline). This
     * guards against a regression back to an inline `x-data="{...}"` object.
     */
    public function test_flash_message_alpine_component_is_registered_in_app_js(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString("Alpine.data('flashMessage'", $source);
        $this->assertStringContainsString('visible: true', $source);
    }
}
