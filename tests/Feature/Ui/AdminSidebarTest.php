<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 4 — collapsible sidebar shell.
 *
 * The horizontal admin nav was replaced by a collapsible, accessible sidebar
 * with a top bar (icon-only theme switcher + initials profile dropdown) and a
 * mobile off-canvas drawer. These assertions are deliberately semantic — they
 * match labels, roles, `aria-*` and real route URLs, never a CSS value — so
 * incidental styling changes do not break them.
 *
 * NOTE: `assertSee(..., false)` is used throughout because the rendered markup
 * contains double quotes inside unescaped Blade `x-bind` attributes, which
 * breaks `DOMDocument`-based escaping assumptions for `assertSee` defaults.
 */
final class AdminSidebarTest extends TestCase
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

    private function html(string $route, array $params = []): string
    {
        return (string) $this->actingAs($this->admin)
            ->get(route($route, $params))
            ->assertOk()
            ->getContent();
    }

    /**
     * Pull a single element's opening tag by a marker attribute so attribute
     * assertions never leak into a neighbouring element.
     */
    private function elementWith(string $html, string $marker, string $tag = 'a'): string
    {
        $pos = strpos($html, $marker);
        $this->assertNotFalse($pos, "expected markup carrying [{$marker}]");

        $open = strrpos(substr($html, 0, $pos), '<'.$tag);
        $this->assertNotFalse($open, "expected a <{$tag}> tag carrying [{$marker}]");

        $close = strpos($html, '>', $pos);
        $this->assertNotFalse($close);

        return substr($html, $open, $close - $open + 1);
    }

    // --- Navigation items + real routes ------------------------------------

    public function test_sidebar_renders_every_real_nav_item_with_its_route(): void
    {
        $html = $this->html('admin.dashboard');

        $expected = [
            'Dashboard' => route('admin.dashboard'),
            'Websites' => route('admin.websites.index'),
            'Incidents' => route('admin.incidents.index'),
            'Notification' => route('admin.notifications.index'),
            'Delivery log' => route('admin.notification-logs.index'),
            'Status pages' => route('admin.status-pages.index'),
            'Profile' => route('admin.profile.edit'),
            'Settings' => route('admin.settings.edit'),
            'Documentation' => route('admin.documentation'),
        ];

        foreach ($expected as $label => $url) {
            $this->assertStringContainsString('>'.$label.'<', $html, "nav label [{$label}] must render");
            $this->assertStringContainsString('href="'.$url.'"', $html, "nav item [{$label}] must link to {$url}");
        }
    }

    public function test_sidebar_is_a_labelled_landmark_and_icons_have_accessible_names(): void
    {
        $html = $this->html('admin.dashboard');

        // Sidebar nav landmark is labelled (aside is labelled too for the rail).
        $this->assertStringContainsString('aria-label="Primary"', $html, 'the sidebar nav landmark must be labelled');

        // Every nav link exposes its label as an accessible name — the only text
        // node inside the icon-only collapsed state.
        foreach (['Dashboard', 'Websites', 'Incidents', 'Notification', 'Delivery log', 'Status pages', 'Profile', 'Settings', 'Documentation'] as $label) {
            $this->assertStringContainsString(
                'aria-label="'.$label.'"',
                $html,
                "the [{$label}] nav item must carry an accessible name for collapsed mode"
            );
        }
    }

    // --- Collapse toggle ----------------------------------------------------

    public function test_collapse_toggle_is_present_with_aria_and_accessible_name(): void
    {
        $html = $this->html('admin.dashboard');

        $trigger = $this->elementWith($html, 'data-sidebar-collapse', 'button');

        $this->assertStringContainsString('aria-expanded', $trigger, 'collapse toggle must expose aria-expanded');
        $this->assertStringContainsString('Collapse sidebar', $trigger, 'collapse toggle must have an accessible name');
    }

    public function test_sidebar_component_is_wired_to_a_registered_alpine_component(): void
    {
        $html = $this->html('admin.dashboard');

        // The state lives in a registered Alpine component, never an inline object.
        $this->assertStringContainsString('x-data="sidebar"', $html, 'the shell must bind the registered `sidebar` component');

        // The component (and its localStorage persistence) is registered in app.js.
        $js = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("Alpine.data('sidebar'", $js, 'the `sidebar` component must be registered in app.js');
        $this->assertStringContainsString("localStorage.getItem('sentinel.sidebar')", $js, 'collapse persistence is not wired');
        $this->assertStringContainsString("Alpine.data('profileMenu'", $js, 'the `profileMenu` component must be registered in app.js');
    }

    // --- Active state -------------------------------------------------------

    #[DataProvider('activePageProvider')]
    public function test_active_nav_item_is_marked_with_aria_current(string $route): void
    {
        $html = $this->html($route);

        $item = $this->elementWith($html, 'aria-current="page"', 'a');

        // aria-current is set on the item, and the active state is reinforced by
        // a non-colour indicator (left bar) rather than colour alone.
        $this->assertStringContainsString('aria-current="page"', $item);
        $this->assertStringContainsString('data-active-indicator', $html, 'active state must not rely on colour alone');
    }

    public static function activePageProvider(): array
    {
        return [
            'dashboard' => ['admin.dashboard'],
            'websites' => ['admin.websites.index'],
            'settings' => ['admin.settings.edit'],
            'documentation' => ['admin.documentation'],
        ];
    }

    public function test_exactly_one_nav_item_is_marked_active_on_the_dashboard(): void
    {
        $html = $this->html('admin.dashboard');

        // The desktop rail and the mobile drawer each render the nav, so the
        // active page appears (at most) once per copy and never more.
        $this->assertSame(2, substr_count($html, 'aria-current="page"'));
    }

    // --- Profile dropdown ---------------------------------------------------

    public function test_profile_dropdown_shows_identity_links_and_post_logout(): void
    {
        $html = $this->html('admin.dashboard');

        $trigger = $this->elementWith($html, 'data-profile-trigger', 'button');
        $this->assertStringContainsString('aria-haspopup="menu"', $trigger, 'profile trigger must advertise a menu popup');
        $this->assertStringContainsString('aria-expanded', $trigger, 'profile trigger must expose aria-expanded');
        $this->assertStringContainsString('aria-label="Account menu"', $trigger, 'profile trigger must have an accessible name');

        // Populated from auth()->user().
        $this->assertStringContainsString('Ada Admin', $html);
        $this->assertStringContainsString('ada@example.com', $html);

        // Real destinations.
        $this->assertStringContainsString('href="'.route('admin.profile.edit').'"', $html);
        $this->assertStringContainsString('href="'.route('admin.settings.edit').'"', $html);
        $this->assertStringContainsString('View / Edit Profile', $html);

        // Logout stays a real POST form (not a GET link).
        $this->assertStringContainsString('action="'.route('logout').'"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html, 'the logout form must carry the CSRF token');
    }

    public function test_profile_dropdown_is_a_registered_alpine_component(): void
    {
        $html = $this->html('admin.dashboard');

        $this->assertStringContainsString('x-data="profileMenu"', $html, 'the profile dropdown must bind the registered `profileMenu` component');
    }

    // --- Mobile drawer ------------------------------------------------------

    public function test_mobile_drawer_toggle_has_an_accessible_name_and_controls_the_drawer(): void
    {
        $html = $this->html('admin.dashboard');

        $toggle = $this->elementWith($html, 'data-drawer-toggle', 'button');

        $this->assertStringContainsString('aria-label="Open navigation"', $toggle);
        $this->assertStringContainsString('aria-controls="admin-nav-drawer"', $toggle);
        $this->assertStringContainsString('aria-expanded', $toggle);
    }

    public function test_mobile_drawer_is_a_labelled_modal_dialog(): void
    {
        $html = $this->html('admin.dashboard');

        $this->assertStringContainsString('id="admin-nav-drawer"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
    }

    // --- Smoke: routes still resolve ---------------------------------------

    #[DataProvider('adminRoutesProvider')]
    public function test_admin_routes_still_resolve(string $route, array $params = []): void
    {
        $this->actingAs($this->admin)->get(route($route, $params))->assertOk();
    }

    public static function adminRoutesProvider(): array
    {
        return [
            'dashboard' => ['admin.dashboard'],
            'websites' => ['admin.websites.index'],
            'incidents' => ['admin.incidents.index'],
            'notifications' => ['admin.notifications.index'],
            'notification-logs' => ['admin.notification-logs.index'],
            'status-pages' => ['admin.status-pages.index'],
            'profile' => ['admin.profile.edit'],
            'settings' => ['admin.settings.edit'],
            'documentation' => ['admin.documentation'],
        ];
    }
}
