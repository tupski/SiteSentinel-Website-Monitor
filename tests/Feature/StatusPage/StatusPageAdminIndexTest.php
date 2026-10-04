<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPage;

/**
 * Requirement 24 — Status Pages admin table.
 *
 * The slug column renders a badge with the LEADING SLASH, linking to the real
 * public status route only when the page is publicly reachable; the Edit action
 * is an icon-only pencil button with an accessible label; the Analytics action
 * is an icon-only chart-bar button. Every pre-existing action is preserved.
 */
final class StatusPageAdminIndexTest extends StatusPageTestCase
{
    public function test_slug_badge_has_leading_slash_and_links_the_real_public_route(): void
    {
        $page = $this->makePage([
            'slug' => 'public-page',
            'visibility_mode' => StatusPage::MODE_PUBLIC,
        ]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('admin.status-pages.index'))
            ->assertOk()
            ->getContent();

        // Leading slash is displayed.
        $this->assertStringContainsString('/'.$page->slug, $html);

        // The link resolves through the REAL status page route, not a hand-built string.
        $this->assertStringContainsString('href="'.route('status.show', $page).'"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_slug_badge_is_not_a_link_for_a_non_public_page(): void
    {
        $page = $this->makePage([
            'slug' => 'private-page',
            'visibility_mode' => StatusPage::MODE_PRIVATE,
        ]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('admin.status-pages.index'))
            ->assertOk()
            ->getContent();

        // The slug is still shown (with the leading slash), but a Private page is
        // never linkable — no dead/404 destination is offered.
        $this->assertStringContainsString('/'.$page->slug, $html);
        $this->assertStringNotContainsString('href="'.route('status.show', $page).'"', $html);
    }

    public function test_edit_action_is_an_icon_with_an_accessible_label(): void
    {
        $this->makePage(['slug' => 'editable-page']);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('admin.status-pages.index'))
            ->assertOk()
            ->getContent();

        // Icon-only pencil button with the accessible label; the old text button is gone.
        $this->assertStringContainsString('aria-label="Edit status page"', $html);
        $this->assertStringContainsString('title="Edit status page"', $html);
        $this->assertStringContainsString('m16.862 4.487', $html, 'pencil icon path expected');

        // The edit route is preserved.
        $this->assertStringContainsString('href="'.route('admin.status-pages.edit', StatusPage::where('slug', 'editable-page')->first()).'"', $html);
    }

    public function test_analytics_action_is_a_chart_bar_icon_with_an_accessible_label(): void
    {
        $page = $this->makePage(['slug' => 'analytics-page']);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('admin.status-pages.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="View analytics"', $html);
        $this->assertStringContainsString('title="View analytics"', $html);
        // chart-bar icon path (distinct from the pencil).
        $this->assertStringContainsString('M3 13.125', $html, 'chart-bar icon path expected');
        $this->assertStringContainsString('href="'.route('admin.status-pages.analytics', $page).'"', $html);
    }

    public function test_existing_delete_action_is_preserved_for_non_default_pages(): void
    {
        $page = $this->makePage(['slug' => 'deletable-page']);
        $this->defaultPage();

        $html = (string) $this->actingAs($this->admin())
            ->get(route('admin.status-pages.index'))
            ->assertOk()
            ->getContent();

        // Delete still exists, modal-gated, with its accessible label + route.
        $this->assertStringContainsString('aria-label="Delete '.$page->name.'"', $html);
        $this->assertStringContainsString("open-modal', { name: 'delete-status-page-".$page->id."' }", $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('action="'.route('admin.status-pages.destroy', $page).'"', $html);
    }

    public function test_analytics_action_rejects_unauthenticated_and_non_admin_users(): void
    {
        $page = $this->makePage();

        $this->get(route('admin.status-pages.analytics', $page))->assertRedirect(route('login'));

        $this->actingAs($this->viewer())
            ->get(route('admin.status-pages.analytics', $page))
            ->assertForbidden();
    }
}
