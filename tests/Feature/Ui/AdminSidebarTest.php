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

    /**
     * The admin top header, so "not in the header" assertions cannot leak into
     * the rest of the page (Requirement 27 relocated the collapse control).
     */
    private function headerElement(string $html): string
    {
        $start = strpos($html, '<header');
        $this->assertNotFalse($start, 'expected an admin <header>');

        $end = strpos($html, '</header>', $start);
        $this->assertNotFalse($end, 'expected a closing </header>');

        return substr($html, $start, $end - $start + strlen('</header>'));
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

    // --- Collapse toggle (Requirement 27: moved to the sidebar footer) ------

    public function test_collapse_toggle_lives_at_the_bottom_of_the_sidebar(): void
    {
        $html = $this->html('admin.dashboard');

        $trigger = $this->elementWith($html, 'data-sidebar-collapse', 'button');

        // Expanded state: a full-width control labelled "Collapse sidebar".
        $this->assertStringContainsString('aria-expanded', $trigger, 'collapse toggle must expose aria-expanded');
        $this->assertStringContainsString('aria-controls="admin-sidebar"', $trigger);
        $this->assertStringContainsString('Collapse sidebar', $trigger, 'collapse toggle must have an accessible name');
        $this->assertStringContainsString('w-full', $trigger, 'the relocated toggle is a full-width sidebar footer control');

        // Collapsed state: a directional double-chevron (expand) with an
        // accessible label/tooltip. The icons live in the button body, so they
        // are asserted against the page rather than the opening tag. The
        // component renders only SVG path data — the direction is marked with
        // an explicit `data-collapse-icon` hook instead of the icon name.
        $this->assertStringContainsString('data-collapse-icon="collapse"', $html, 'expanded state shows the collapse (left) chevron');
        $this->assertStringContainsString('data-collapse-icon="expand"', $html, 'collapsed state shows the expand (right) chevron');
        $this->assertStringContainsString('collapseLabel()', $trigger, 'the accessible name follows the collapse state');
    }

    public function test_collapse_toggle_is_no_longer_in_the_admin_header(): void
    {
        $html = $this->html('admin.dashboard');

        $header = $this->headerElement($html);

        $this->assertStringNotContainsString('data-sidebar-collapse', $header, 'the collapse control must have left the top header');
        $this->assertStringNotContainsString('toggleCollapse()', $header, 'the header must not drive the sidebar collapse');

        // Positive control: the header still carries its remaining controls.
        $this->assertStringContainsString('data-drawer-toggle', $header, 'the mobile drawer toggle stays in the header');
        $this->assertStringContainsString('x-data="themeMenu"', $header, 'the theme switcher stays in the header');

        // ...and the control now renders once per sidebar copy (desktop rail +
        // mobile drawer), never in the header.
        $this->assertSame(
            2,
            substr_count($html, 'data-sidebar-collapse'),
            'the collapse control must render only inside the two sidebar copies'
        );
    }

    public function test_sidebar_component_is_wired_to_a_registered_alpine_component(): void
    {
        $html = $this->html('admin.dashboard');

        // The state lives in a registered Alpine component, never an inline object.
        $this->assertStringContainsString('x-data="sidebar"', $html, 'the shell must bind the registered `sidebar` component');

        // The component (and its localStorage persistence) is registered in
        // app.js. Requirement 27 relocated the button only — the state property
        // (`collapsed`), the toggle method (`toggleCollapse`) and the storage
        // key (`sentinel.sidebar`) must be unchanged.
        $js = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("Alpine.data('sidebar'", $js, 'the `sidebar` component must be registered in app.js');
        $this->assertStringContainsString("localStorage.getItem('sentinel.sidebar')", $js, 'collapse persistence is not wired');
        $this->assertStringContainsString("localStorage.setItem('sentinel.sidebar'", $js, 'collapse persistence must be written back');
        $this->assertStringContainsString('toggleCollapse()', $js, 'the sidebar component must keep its toggle method');
        $this->assertStringContainsString('collapseLabel()', $js, 'the sidebar component must keep its state-aware label');
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
        // NOTE: `admin.documentation` is intentionally absent. The Documentation
        // page rework moved it OUT of the admin app shell into a dedicated
        // standalone layout (no `#admin-sidebar`), so there is no admin nav item
        // to mark active there. The sidebar still LINKS to it (see the nav tests
        // above); only the shell-active assertion no longer applies.
        return [
            'dashboard' => ['admin.dashboard'],
            'websites' => ['admin.websites.index'],
            'settings' => ['admin.settings.edit'],
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
