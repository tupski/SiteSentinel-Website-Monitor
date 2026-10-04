<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Requirement 28 (Phase H, ADR-038) — in-app notification centre bell +
 * dropdown. These assertions are deliberately semantic (labels, roles,
 * `aria-*`, data hooks and the registered Alpine component name), never a CSS
 * value, so incidental styling changes do not break them.
 *
 * NOTE: `assertSee(..., false)` / raw string checks are used because the
 * rendered markup contains double quotes inside unescaped Blade `x-bind`
 * attributes.
 */
final class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Ada Admin',
            'email' => 'ada@example.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function html(string $route = 'admin.dashboard'): string
    {
        return (string) $this->actingAs($this->admin)
            ->get(route($route))
            ->assertOk()
            ->getContent();
    }

    /**
     * Slice a single element's opening tag by a marker attribute so attribute
     * assertions never leak into a neighbouring element.
     */
    private function elementWith(string $html, string $marker, string $tag = 'button'): string
    {
        $pos = strpos($html, $marker);
        $this->assertNotFalse($pos, "expected markup carrying [{$marker}]");

        $open = strrpos(substr($html, 0, $pos), '<'.$tag);
        $this->assertNotFalse($open, "expected a <{$tag}> tag carrying [{$marker}]");

        $close = strpos($html, '>', $pos);
        $this->assertNotFalse($close);

        return substr($html, $open, $close - $open + 1);
    }

    // --- H1: bell placement + accessibility ---------------------------------

    public function test_bell_renders_between_the_theme_switcher_and_the_profile_menu(): void
    {
        $html = $this->html();

        $theme = strpos($html, 'x-data="themeMenu"');
        $bell = strpos($html, 'data-notification-trigger');
        $profile = strpos($html, 'data-profile-trigger');

        $this->assertNotFalse($theme, 'the theme switcher must render');
        $this->assertNotFalse($bell, 'the notification bell must render');
        $this->assertNotFalse($profile, 'the profile menu must render');

        $this->assertTrue($theme < $bell, 'the bell must come after the theme switcher');
        $this->assertTrue($bell < $profile, 'the bell must come before the profile menu');
    }

    public function test_bell_is_an_accessible_button_with_expanded_state(): void
    {
        $trigger = $this->elementWith($this->html(), 'data-notification-trigger');

        $this->assertStringContainsString('type="button"', $trigger);
        $this->assertStringContainsString('aria-label="Notifications"', $trigger);
        $this->assertStringContainsString('aria-haspopup', $trigger);
        $this->assertStringContainsString('aria-expanded="false"', $trigger);
        $this->assertStringContainsString('x-bind:aria-expanded', $trigger, 'aria-expanded must reflect the dropdown state');
    }

    public function test_bell_is_bound_to_the_registered_alpine_component(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('x-data="notificationCenter(', $html);

        // The component logic must live in app.js — never an inline object.
        $js = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("Alpine.data('notificationCenter'", $js);
    }

    // --- H1: unread badge ---------------------------------------------------

    public function test_badge_hooks_cap_the_display_and_hide_at_zero(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-notification-badge', $html, 'the unread badge must carry a stable hook');
        $this->assertStringContainsString('x-show="unreadCount > 0"', $html, 'the badge must hide when there is nothing unread');
        $this->assertStringContainsString('x-text="badgeText"', $html, 'the badge text follows the component state');

        $js = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("'99+'", $js, 'large counts must be capped at 99+ for display');
        $this->assertStringContainsString('unreadCount > 99', $js, 'the cap is applied only to the rendered text, not the stored count');
    }

    public function test_bell_switches_icon_when_there_are_unread_items(): void
    {
        $html = $this->html();

        // Both bell variants are present and toggled by the unread count; the
        // icon names are resolved to path data, so assert the conditional hooks.
        $this->assertStringContainsString('x-show="unreadCount === 0"', $html);
        $this->assertStringContainsString('x-show="unreadCount > 0"', $html);
    }

    // --- H2: dropdown panel -------------------------------------------------

    public function test_dropdown_exposes_header_actions_and_states(): void
    {
        $html = $this->html();

        // Footer actions.
        $this->assertStringContainsString('data-notification-mark-all', $html);
        $this->assertStringContainsString('Mark all as read', $html);
        $this->assertStringContainsString('data-notification-show-all', $html);
        $this->assertStringContainsString('Show all notifications', $html);

        // Loading / empty / error state hooks.
        $this->assertStringContainsString('data-notification-loading', $html);
        $this->assertStringContainsString('data-notification-empty', $html);
        $this->assertStringContainsString('data-notification-error', $html);
        $this->assertStringContainsString('data-notification-list', $html);

        // Loading state reuses the shared skeleton primitive.
        $this->assertStringContainsString('animate-pulse', $html);

        // The "Show all notifications" link points at the full-page view.
        $this->assertStringContainsString('href="'.route('admin.notifications.in-app.page').'"', $html);
    }

    public function test_dropdown_wires_outside_click_and_escape_close(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('x-on:click.outside="closeMenu()"', $html);
        $this->assertStringContainsString('x-on:keydown.escape.window="open && closeMenu(true)"', $html);
    }

    public function test_dropdown_logic_is_not_an_inline_x_data_object(): void
    {
        $html = $this->html();

        // The notification centre binds a registered component name only; its
        // fetching/polling logic never appears inline in the markup.
        $this->assertStringContainsString('x-data="notificationCenter(', $html);
        $this->assertStringNotContainsString('x-data="{', $html, 'no component may fall back to an inline x-data object');
    }

    // --- H2: polling + cleanup in app.js ------------------------------------

    public function test_app_js_registers_polling_with_non_overlapping_and_visibility_cleanup(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));

        // Both components registered.
        $this->assertStringContainsString("Alpine.data('notificationCenter'", $js);
        $this->assertStringContainsString("Alpine.data('notificationList'", $js);

        // Polling is independent of the panel: it uses the cheap unread-count
        // endpoint and a configurable interval.
        $this->assertStringContainsString('unreadCountUrl', $js);
        $this->assertStringContainsString('pollInterval', $js);
        $this->assertStringContainsString('setInterval', $js);

        // Non-overlapping requests (in-flight guards).
        $this->assertStringContainsString('countInFlight', $js);
        $this->assertStringContainsString('listInFlight', $js);

        // Visibility pause + resume, and teardown on destroy.
        $this->assertStringContainsString("addEventListener('visibilitychange'", $js);
        $this->assertStringContainsString("removeEventListener('visibilitychange'", $js);
        $this->assertStringContainsString('clearInterval', $js);
        $this->assertStringContainsString('destroy()', $js);

        // Relative-link validation before using a payload URL as an href.
        $this->assertStringContainsString('safeLink', $js);
    }
}
