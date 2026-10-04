<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPage;
use App\Models\Website;
use App\Services\StatusPage\StatusPageCache;

/**
 * A website mutation MUST invalidate the cached public projection
 * (STATUS-PAGE.md §9.2).
 *
 * The projector reads live website columns (`name`/`status_alias`, `is_active`,
 * `is_visible_on_status`, `check_interval_seconds`), so editing, toggling, or
 * deleting a website changes what the page must show. Without an invalidation
 * the public page serves the pre-edit projection until the TTL expires.
 *
 * Regression guard: `WebsiteController` previously never busted the cache —
 * the publication fix on the assignment screen alone did not refresh a site
 * that an operator edited or re-published from the website CRUD screen.
 */
final class WebsiteMutationInvalidationTest extends StatusPageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setMode(StatusPage::MODE_PUBLIC);
    }

    private function cache(): StatusPageCache
    {
        return app(StatusPageCache::class);
    }

    /**
     * The cache key (which encodes the global epoch) for the default page.
     */
    private function projectionKey(): string
    {
        return $this->cache()->key($this->defaultPage()->refresh());
    }

    public function test_website_update_invalidates_the_projection(): void
    {
        $website = $this->makeWebsite(['status_alias' => 'Alpha']);
        $before = $this->projectionKey();

        $response = $this->actingAs($this->admin())
            ->from(route('admin.websites.edit', $website))
            ->put(route('admin.websites.update', $website), [
                'name' => 'Alpha Renamed',
                'url' => 'https://example.org/',
                'check_interval_seconds' => 300,
                'timeout_seconds' => 10,
                'expected_status' => 200,
            ]);

        $response->assertRedirect(route('admin.websites.index'));
        $this->assertNotSame($before, $this->projectionKey());
    }

    public function test_website_toggle_invalidates_the_projection(): void
    {
        $website = $this->makeWebsite(['status_alias' => 'Alpha', 'is_active' => true]);
        $before = $this->projectionKey();

        $this->actingAs($this->admin())
            ->post(route('admin.websites.toggle', $website))
            ->assertRedirect(route('admin.websites.index'));

        $this->assertNotSame($before, $this->projectionKey());
    }

    public function test_website_delete_invalidates_the_projection(): void
    {
        $website = $this->makeWebsite(['status_alias' => 'Alpha']);
        $before = $this->projectionKey();

        $this->actingAs($this->admin())
            ->delete(route('admin.websites.destroy', $website))
            ->assertRedirect(route('admin.websites.index'));

        $this->assertNotSame($before, $this->projectionKey());
    }

    public function test_bulk_delete_invalidates_the_projection(): void
    {
        $website = $this->makeWebsite(['status_alias' => 'Alpha']);
        $before = $this->projectionKey();

        $this->actingAs($this->admin())
            ->post(route('admin.websites.bulk.delete'), ['ids' => [$website->id]])
            ->assertRedirect(route('admin.websites.index'));

        $this->assertNotSame($before, $this->projectionKey());
    }

    public function test_republishing_a_stale_row_refreshes_the_page(): void
    {
        // Simulates the operator's KR case: the row was assigned before the
        // publication fix, so `is_visible_on_status` is still false and the
        // page omits it. Re-saving from the website screen must both flip the
        // row (via the admin) and drop the stale cached projection.
        $website = $this->makeWebsite([
            'status_alias' => 'KR',
            'is_visible_on_status' => false,
            'status_availability' => 'UP',
            'status_security' => 'INCIDENT',
        ]);

        $this->assertNull($this->labelFor('KR'));

        $website->is_visible_on_status = true;
        $website->save();

        // A raw save does not publish the cache by itself; the website CRUD
        // path is what busts it. Drive that path explicitly.
        $this->actingAs($this->admin())
            ->put(route('admin.websites.update', $website), [
                'name' => 'KR',
                'url' => 'https://example.org/',
                'check_interval_seconds' => 300,
                'timeout_seconds' => 10,
                'expected_status' => 200,
            ])
            ->assertRedirect(route('admin.websites.index'));

        // The page now shows KR, labelled from the UP + INCIDENT snapshot.
        $this->assertSame('Incident', $this->labelFor('KR'));
    }
}
