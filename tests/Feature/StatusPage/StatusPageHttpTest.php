<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPage;
use Illuminate\Support\Facades\Hash;

/**
 * HTTP surface contract for /status/{slug} + admin pages (STATUS-PAGE.md §8,
 * §10.3, §11.3, §11.4; §12.1).
 *
 * Semantic markup, correct headers (no-store vs public max-age, noindex),
 * safe redirects, no admin controls in public output, admin validation, and
 * the legacy /status redirect.
 */
final class StatusPageHttpTest extends StatusPageTestCase
{
    private function showUrl(?StatusPage $page = null): string
    {
        return route('status.show', ['statusPage' => ($page ?? $this->defaultPage())->slug]);
    }

    public function test_public_html_has_semantic_markup_and_labels(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha', 'status_availability' => 'UP', 'status_security' => 'OK']);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $response = $this->get($this->showUrl());
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('<section', $html);
        $this->assertStringContainsString('aria-label="Services"', $html);
        $this->assertStringContainsString('Operational', $html);
        $this->assertStringContainsString('Alpha', $html);
    }

    public function test_public_mode_sets_public_cache_control_and_noindex(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->setMode(StatusPage::MODE_PUBLIC);

        $response = $this->get($this->showUrl($page));
        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

        $json = $this->getJson(route('status.json', ['statusPage' => $page->slug]));
        $json->assertOk();
        $jsonCacheControl = (string) $json->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $jsonCacheControl);
        $this->assertStringContainsString('max-age=60', $jsonCacheControl);
        $this->assertSame('noindex, nofollow', $json->headers->get('X-Robots-Tag'));
    }

    public function test_private_admin_view_is_no_store(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->setMode(StatusPage::MODE_PRIVATE);
        $this->actingAs($this->admin());

        $response = $this->get($this->showUrl($page));
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_locked_password_form_is_no_store_and_noindex(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->get($this->showUrl($page));
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_robots_txt_disallows_status(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /status', $robots);
    }

    public function test_empty_state_renders_no_services_message(): void
    {
        $page = $this->setMode(StatusPage::MODE_PUBLIC);

        $response = $this->get($this->showUrl($page));
        $response->assertOk();
        $response->assertSee('No services published.', false);

        $json = $this->getJson(route('status.json', ['statusPage' => $page->slug]));
        $json->assertOk();
        $json->assertJsonPath('services', []);
        $json->assertJsonPath('banner', 'Unknown');
    }

    public function test_public_output_has_no_admin_controls_nav_or_ids(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->setMode(StatusPage::MODE_PUBLIC);

        $html = (string) $this->get($this->showUrl($page))->getContent();

        // No admin nav / controls / logout / csrf meta in the public surface.
        $this->assertStringNotContainsString('/admin', $html);
        $this->assertStringNotContainsString('Log out', $html);
        $this->assertStringNotContainsString('csrf-token', $html);
    }

    public function test_legacy_status_redirects_to_default_page(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->defaultPage();

        $response = $this->get(route('status.legacy'));

        $response->assertRedirect(route('status.show', ['statusPage' => $page->slug]));
        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_legacy_status_redirects_404_when_no_default_page(): void
    {
        // No status_pages row exists at all.
        $this->assertSame(0, StatusPage::query()->count());

        $this->get(route('status.legacy'))->assertNotFound();
        $this->get(route('status.json-legacy'))->assertNotFound();
    }

    public function test_admin_status_page_crud_requires_auth(): void
    {
        $page = $this->makePage();

        $this->get(route('admin.status-pages.index'))->assertRedirect(route('login'));
        $this->get(route('admin.status-pages.create'))->assertRedirect(route('login'));
        $this->post(route('admin.status-pages.store'), [])->assertRedirect(route('login'));
        $this->get(route('admin.status-pages.edit', $page))->assertRedirect(route('login'));
        $this->put(route('admin.status-pages.update', $page), [])->assertRedirect(route('login'));
        $this->delete(route('admin.status-pages.destroy', $page))->assertRedirect(route('login'));
    }

    public function test_admin_can_create_a_status_page(): void
    {
        // A default page already exists, so the new page is not default.
        $this->defaultPage();
        $this->actingAs($this->admin());

        $response = $this->post(route('admin.status-pages.store'), [
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'visibility_mode' => StatusPage::MODE_PRIVATE,
        ]);

        $response->assertRedirect(route('admin.status-pages.index'));
        $this->assertDatabaseHas('status_pages', ['slug' => 'acme-status', 'is_default' => false]);
    }

    public function test_first_created_page_becomes_the_default(): void
    {
        $this->assertSame(0, StatusPage::query()->count());
        $this->actingAs($this->admin());

        $this->post(route('admin.status-pages.store'), [
            'name' => 'Only page',
            'slug' => 'only-page',
            'visibility_mode' => StatusPage::MODE_PRIVATE,
        ])->assertRedirect(route('admin.status-pages.index'));

        $this->assertDatabaseHas('status_pages', ['slug' => 'only-page', 'is_default' => true]);
    }

    public function test_admin_cannot_create_a_duplicate_slug(): void
    {
        $this->defaultPage();
        $this->actingAs($this->admin());

        $this->post(route('admin.status-pages.store'), [
            'name' => 'Dup',
            'slug' => 'status',
            'visibility_mode' => StatusPage::MODE_PRIVATE,
        ])->assertSessionHasErrors('slug');
    }

    public function test_admin_update_can_assign_websites_to_a_page(): void
    {
        $website = $this->makeWebsite(['name' => 'Alpha']);
        $page = $this->makePage(['slug' => 'tenant-a']);
        $this->actingAs($this->admin());

        $response = $this->put(route('admin.status-pages.update', $page), [
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'visibility_mode' => StatusPage::MODE_PUBLIC,
            'confirm_public' => 1,
            'published' => [$website->id],
        ]);

        $response->assertRedirect(route('admin.status-pages.edit', $page));
        $this->assertSame($page->id, $website->refresh()->status_page_id);
        // Assignment must also publish the website: the projector filters on
        // BOTH status_page_id and is_visible_on_status, so a bare assignment
        // left the website invisible on every page (regression guard).
        $this->assertTrue($website->refresh()->is_visible_on_status);
    }

    public function test_default_page_cannot_be_deleted(): void
    {
        $this->makePage(['slug' => 'other']);
        $default = $this->defaultPage();
        $this->actingAs($this->admin());

        $this->delete(route('admin.status-pages.destroy', $default))->assertSessionHasErrors('status_page');
        $this->assertDatabaseHas('status_pages', ['id' => $default->id]);
    }

    public function test_deleting_a_page_releases_websites_to_the_default(): void
    {
        $website = $this->makeWebsite(['name' => 'Alpha', 'status_page_id' => null]);
        $page = $this->makePage(['slug' => 'tenant-a']);
        $website->status_page_id = $page->id;
        $website->save();

        $this->defaultPage();
        $this->actingAs($this->admin());

        $this->delete(route('admin.status-pages.destroy', $page))
            ->assertRedirect(route('admin.status-pages.index'));

        $this->assertDatabaseMissing('status_pages', ['id' => $page->id]);
        $this->assertNull($website->refresh()->status_page_id);
    }

    public function test_admin_cannot_create_a_page_without_public_confirmation(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.status-pages.store'), [
            'name' => 'Public page',
            'slug' => 'public-page',
            'visibility_mode' => StatusPage::MODE_PUBLIC,
        ])->assertSessionHasErrors('confirm_public');
    }
}
