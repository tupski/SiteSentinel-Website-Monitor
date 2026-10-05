<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Documentation page rework — Laravel-framework-docs-style shell.
 *
 * The admin documentation page moved OUT of the admin app shell into a
 * dedicated, standalone layout with a 3/2/1-column responsive structure:
 *
 *  - xl: grouped left sidebar + content + right "On this page" TOC
 *  - lg: grouped left sidebar + content
 *  - < lg: single column, sidebar + TOC inside an off-canvas drawer
 *
 * These assertions are semantic (regions, `data-*` hooks, aria) rather than
 * pixel/CSS-value based, so incidental restyling does not break them.
 */
final class DocumentationLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function html(): string
    {
        $user = User::factory()->create();

        return (string) $this->actingAs($user)
            ->get(route('admin.documentation'))
            ->assertOk()
            ->getContent();
    }

    // --- Dedicated layout (NOT the admin shell) ---------------------------

    public function test_page_uses_the_dedicated_docs_layout_not_the_admin_shell(): void
    {
        $html = $this->html();

        // The dedicated layout owns the docs shell + grid + drawer markers.
        $this->assertStringContainsString('id="docs-shell"', $html, 'docs shell root must render');
        $this->assertStringContainsString('data-docs-grid', $html, 'docs content grid must render');
        $this->assertStringContainsString('id="docs-nav-drawer"', $html, 'docs drawer must render');

        // None of the admin app-shell chrome may leak into the docs page.
        foreach ([
            'id="admin-shell"' => 'admin app-shell root',
            'id="admin-sidebar"' => 'admin sidebar',
            'id="admin-nav-drawer"' => 'admin navigation drawer',
            'id="admin-content"' => 'admin content region',
        ] as $needle => $why) {
            $this->assertStringNotContainsString($needle, $html, "docs page must not embed the {$why}");
        }
    }

    public function test_dedicated_layout_carries_its_own_header_chrome(): void
    {
        $html = $this->html();

        // Search, version selector, theme toggle and a return-to-app link.
        $this->assertStringContainsString('id="docs-search"', $html);
        $this->assertStringContainsString('id="docs-version"', $html);
        $this->assertStringContainsString('data-theme-trigger', $html, 'theme toggle must render');
        $this->assertStringContainsString(route('admin.dashboard'), $html, 'return-to-app link must render');
        $this->assertStringContainsString(route('admin.documentation'), $html, 'brand link must point at the docs route');
    }

    // --- 3-column responsive structure ------------------------------------

    public function test_three_column_regions_exist_with_expected_responsive_classes(): void
    {
        $html = $this->html();

        // Grid: 1 col default → 2 cols at lg (with a 16rem sidebar) → 3 cols at xl
        // (with a 16rem TOC on the right).
        $this->assertMatchesRegularExpression(
            '/data-docs-grid[^>]*lg:grid-cols-\[16rem_minmax\(0,1fr\)\]/',
            $html,
            'grid must define the lg 2-column track (sidebar + content)',
        );
        $this->assertMatchesRegularExpression(
            '/data-docs-grid[^>]*xl:grid-cols-\[16rem_minmax\(0,1fr\)_16rem\]/',
            $html,
            'grid must define the xl 3-column track (sidebar + content + TOC)',
        );

        // Sidebar + TOC are hidden below lg/xl and shown at the breakpoints.
        $this->assertMatchesRegularExpression('/data-docs-sidebar[^>]*hidden[^>]*lg:block/', $html);
        $this->assertMatchesRegularExpression('/data-docs-toc[^>]*hidden[^>]*xl:block/', $html);

        // Content region is the accessible main target.
        $this->assertStringContainsString('id="docs-content"', $html);
    }

    public function test_mobile_navigation_toggle_is_present_and_wired(): void
    {
        $html = $this->html();

        $pos = strpos($html, 'data-docs-menu-toggle');
        $this->assertNotFalse($pos, 'mobile navigation toggle must render');

        $start = strrpos(substr($html, 0, $pos), '<button');
        $end = strpos($html, '>', $pos);
        $button = substr($html, $start, $end - $start + 1);

        $this->assertStringContainsString('aria-controls="docs-nav-drawer"', $button);
        $this->assertStringContainsString('aria-expanded', $button);
        $this->assertStringContainsString('lg:hidden', $button, 'toggle is mobile/tablet only');
    }

    // --- Grouped navigation + TOC + anchors -------------------------------

    public function test_grouped_navigation_renders_every_configured_group_and_item(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-docs-nav', $html);

        foreach ((array) config('documentation.groups') as $group) {
            if (! empty($group['label'])) {
                $this->assertStringContainsString(
                    e($group['label']),
                    $html,
                    "group label [{$group['label']}] must render",
                );
            }

            foreach ($group['items'] as $item) {
                $this->assertStringContainsString(
                    'data-docs-nav-link',
                    $html,
                );
                $this->assertStringContainsString(
                    'data-target="'.$item['id'].'"',
                    $html,
                    "nav must link to section [{$item['id']}]",
                );
            }
        }
    }

    public function test_right_hand_table_of_contents_renders_one_entry_per_section(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-docs-toc-nav', $html);
        $this->assertStringContainsString('On this page', $html);

        $sectionCount = 0;
        foreach ((array) config('documentation.groups') as $group) {
            $sectionCount += count($group['items']);
        }

        // The TOC is rendered twice by design: once inside the mobile drawer and
        // once in the desktop right column. Each copy carries one entry per
        // documented section.
        $this->assertSame(
            $sectionCount * 2,
            substr_count($html, 'data-docs-toc-link'),
            'each TOC copy must carry exactly one entry per documented section',
        );

        // And every section id must be represented.
        foreach ((array) config('documentation.groups') as $group) {
            foreach ($group['items'] as $item) {
                $this->assertGreaterThanOrEqual(
                    2,
                    substr_count($html, 'data-target="'.$item['id'].'"'),
                    "TOC/nav must reference section [{$item['id']}]",
                );
            }
        }
    }

    public function test_every_documented_section_has_an_anchor_heading_and_a_matching_section_region(): void
    {
        $html = $this->html();

        foreach ((array) config('documentation.groups') as $group) {
            foreach ($group['items'] as $item) {
                $id = $item['id'];

                // The anchor heading itself: `<h2 id="...">` + a `#` fragment link.
                $this->assertMatchesRegularExpression(
                    '/<h2 id="'.preg_quote($id, '/').'"/',
                    $html,
                    "heading anchor [{$id}] must exist",
                );
                $this->assertStringContainsString('href="#'.$id.'"', $html, "fragment link [{$id}] must exist");

                // The content region the sidebar/TOC link targets.
                $this->assertStringContainsString(
                    'id="'.$id.'" data-doc-section',
                    $html,
                    "content section [{$id}] must be a data-doc-section region",
                );
            }
        }
    }

    // --- Content preserved + client behaviour -----------------------------

    public function test_existing_documentation_content_is_still_reachable(): void
    {
        $html = $this->html();

        // The Phase 7 content contract must survive the rework.
        $this->assertStringContainsString('Telegram Bot setup', $html);
        $this->assertStringContainsString('Chat ID', $html);
        $this->assertStringContainsString('Topic thread ID (optional)', $html);
        $this->assertStringContainsString('Secret (SMTP password or bot token)', $html);
    }

    public function test_client_search_and_drawer_behaviour_is_defined_in_the_js_bundle(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("Alpine.data('docsShell'", $js, 'the docsShell component must be registered');
        $this->assertStringContainsString('applyFilter', $js, 'client-side filtering must be implemented');
        $this->assertStringContainsString('IntersectionObserver', $js, 'scroll-spy must use an observer');
        $this->assertStringContainsString('openDrawer', $js, 'the mobile drawer must be implemented');
    }

    // --- Anti-drift -------------------------------------------------------

    public function test_nav_config_ids_are_unique_and_have_titles(): void
    {
        $seen = [];

        foreach ((array) config('documentation.groups') as $group) {
            foreach ($group['items'] as $item) {
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('title', $item);
                $this->assertNotContains($item['id'], $seen, "duplicate docs section id [{$item['id']}]");
                $seen[] = $item['id'];
            }
        }

        $this->assertNotEmpty($seen);
    }
}
