<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Check;
use App\Models\StatusPage;
use App\Services\Incidents\IncidentEngine;
use App\Services\StatusPage\PublicStatusDTO;
use App\Services\StatusPage\StatusPageCache;
use App\Services\StatusPage\StatusProjector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Per-page projection cache contract (STATUS-PAGE.md §9, §12.4; ADR-031).
 *
 * The cached artefact is the already-redacted DTO — never raw rows — and the
 * cache key includes the page id/slug so pages never share a projection. The
 * cache is invalidated when the projection inputs change.
 */
final class CacheTest extends StatusPageTestCase
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
     * @return array<string, mixed>
     */
    private function allCacheEntries(): array
    {
        $store = Cache::getStore();
        $this->assertTrue(method_exists($store, 'all'));

        /** @var array<string, mixed> $entries */
        $entries = $store->all();

        return $entries;
    }

    private function jsonUrl(?StatusPage $page = null): string
    {
        return route('status.json', ['statusPage' => ($page ?? $this->defaultPage())->slug]);
    }

    public function test_cached_artefact_is_dto_only_no_raw_fields(): void
    {
        $this->makeWebsite([
            'status_alias' => 'Alpha',
            'url' => 'https://raw-host-canary.test/',
            'host' => 'raw-host-canary.test',
            'status_availability' => 'UP',
            'status_security' => 'OK',
        ]);

        $this->getJson($this->jsonUrl())->assertOk();

        $entries = $this->allCacheEntries();
        $this->assertNotEmpty($entries);

        foreach ($entries as $key => $value) {
            $blob = (string) json_encode($value);
            foreach (['raw-host-canary.test', 'status_availability', 'status_security', 'website_id', 'url'] as $raw) {
                $this->assertStringNotContainsString($raw, $blob, "Raw field '{$raw}' leaked into cache value");
                $this->assertStringNotContainsString($raw, (string) $key);
            }
        }
    }

    public function test_cache_key_includes_page_id_and_slug(): void
    {
        $page = $this->defaultPage();

        $key = $this->cache()->key($page);

        $this->assertStringContainsString((string) $page->id, $key);
        $this->assertStringContainsString(sha1($page->slug), $key);
    }

    public function test_pages_have_distinct_cache_keys(): void
    {
        $pageA = $this->makePage(['slug' => 'alpha-page']);
        $pageB = $this->makePage(['slug' => 'beta-page']);

        $this->assertNotSame($this->cache()->key($pageA), $this->cache()->key($pageB));
    }

    public function test_one_page_projection_never_contains_another_pages_websites(): void
    {
        $pageA = $this->makePage(['slug' => 'alpha-page', 'visibility_mode' => StatusPage::MODE_PUBLIC]);
        $pageB = $this->makePage(['slug' => 'beta-page', 'visibility_mode' => StatusPage::MODE_PUBLIC]);

        $this->makeWebsite(['status_alias' => 'Service A', 'status_page_id' => $pageA->id]);
        $this->makeWebsite(['status_alias' => 'Service B', 'status_page_id' => $pageB->id]);

        $a = array_column($this->servicesJson($pageA), 'displayName');
        $b = array_column($this->servicesJson($pageB), 'displayName');

        $this->assertSame(['Service A'], $a);
        $this->assertSame(['Service B'], $b);
    }

    public function test_null_assignment_appears_only_on_the_default_page(): void
    {
        $default = $this->defaultPage();
        $other = $this->makePage(['slug' => 'tenant-a', 'visibility_mode' => StatusPage::MODE_PUBLIC]);
        $this->setPageMode($default, StatusPage::MODE_PUBLIC);

        $this->makeWebsite(['status_alias' => 'Unassigned', 'status_page_id' => null]);

        $this->assertSame(['Unassigned'], array_column($this->servicesJson($default), 'displayName'));
        $this->assertSame([], array_column($this->servicesJson($other), 'displayName'));
    }

    public function test_ttl_floor_is_sixty_seconds_when_no_websites(): void
    {
        $this->assertSame(60, $this->cache()->ttl($this->defaultPage()));
    }

    public function test_ttl_follows_shortest_interval_above_floor(): void
    {
        $this->makeWebsite(['check_interval_seconds' => 300]);

        $this->assertSame(300, $this->cache()->ttl($this->defaultPage()));
    }

    public function test_ttl_never_below_floor(): void
    {
        $this->makeWebsite(['check_interval_seconds' => 30]);

        $this->assertSame(60, $this->cache()->ttl($this->defaultPage()));
    }

    /**
     * Regression: the array cache store (`serialize => false`) hid a fatal on
     * every other store. `config('cache.serializable_classes')` is `false`
     * (Laravel's gadget-chain default), so caching the DTO object made a
     * serializing store return `__PHP_Incomplete_Class` and `remember()` threw
     * a `TypeError` on the second request (STATUS-PAGE.md §9).
     */
    public function test_remember_round_trips_through_a_serializing_store(): void
    {
        $this->makeWebsite([
            'status_alias' => 'Alpha',
            'status_availability' => 'UP',
            'status_security' => 'OK',
        ]);

        // The production default is `database`, which serializes values.
        config(['cache.default' => 'database']);
        Cache::store('database')->flush();

        $page = $this->defaultPage();

        $first = $this->cache()->remember($page);
        $this->assertInstanceOf(PublicStatusDTO::class, $first);

        // A cache hit reads a serialized value back; it must still be a DTO.
        $second = $this->cache()->remember($page);
        $this->assertInstanceOf(PublicStatusDTO::class, $second);
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame('Operational', $second->banner);
        $this->assertSame(['Alpha'], array_column($second->services, 'displayName'));

        Cache::store('database')->flush();
    }

    public function test_projection_is_cached_and_reused(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $url = $this->jsonUrl();

        $this->getJson($url)->assertOk();
        $entriesAfterFirst = count($this->allCacheEntries());

        $this->getJson($url)->assertOk();
        $entriesAfterSecond = count($this->allCacheEntries());

        // A cache hit does not add entries.
        $this->assertSame($entriesAfterFirst, $entriesAfterSecond);
    }

    public function test_incident_open_invalidates_projection(): void
    {
        $website = $this->makeWebsite([
            'status_alias' => 'Alpha',
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'consecutive_failures' => 2,
        ]);

        // Prime the cache with the healthy projection.
        $this->assertSame('Operational', $this->labelFor('Alpha'));

        // Simulate the engine opening an availability incident.
        $check = Check::create([
            'website_id' => $website->id,
            'check_key' => 'cache-open',
            'started_at' => now('UTC'),
            'availability_state' => 'DOWN',
            'security_state' => 'OK',
            'score' => 0,
        ]);
        $website->status_availability = 'DOWN';
        $website->save();

        app(IncidentEngine::class)->processCheck($website, $check, ['security_state' => 'OK', 'score' => 0, 'triggered_rules' => null]);

        $this->assertNotSame('Operational', $this->labelFor('Alpha'));
        $this->assertSame('Partial Outage', $this->labelFor('Alpha'));
    }

    public function test_admin_page_change_invalidates_projection(): void
    {
        $website = $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->defaultPage();
        $this->assertSame('Operational', $this->labelFor('Alpha'));

        $this->actingAs($this->admin());
        $response = $this->put(route('admin.status-pages.update', $page), [
            'name' => $page->name,
            'slug' => $page->slug,
            'visibility_mode' => StatusPage::MODE_PASSWORD_PROTECTED,
            'password' => 'brand-new-status-passphrase',
            'password_confirmation' => 'brand-new-status-passphrase',
            'published' => [$website->id],
        ]);

        $response->assertRedirect(route('admin.status-pages.edit', $page));

        $page->refresh();
        $this->assertTrue($page->isPasswordProtected());
    }

    public function test_invalidation_bumps_a_global_epoch(): void
    {
        $page = $this->defaultPage();
        $before = $this->cache()->key($page);

        $this->cache()->invalidate();

        $this->assertNotSame($before, $this->cache()->key($page->refresh()));
    }

    public function test_precise_timestamp_is_the_projection_generation_stamp(): void
    {
        $stamp = Carbon::parse('2026-10-04 15:37:00', 'UTC');
        $this->travelTo($stamp);

        $this->makeWebsite(['status_alias' => 'Alpha']);

        $dto = app(StatusProjector::class)->project($this->defaultPage(), $stamp->copy());

        // Sub-day precision genuinely exists: the stamp is the projection time,
        // not merely the day bucket (ADR-040). It must share the day bucket's
        // day and the exact H:i:s of the moment passed to project().
        $this->assertSame('2026-10-04', $dto->updatedDayBucket);
        $this->assertSame('2026-10-04T15:37:00Z', $dto->updatedAt);
    }

    public function test_precise_timestamp_survives_the_cache_round_trip(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 15:37:00', 'UTC'));

        $this->makeWebsite(['status_alias' => 'Alpha']);

        // Warm the cache from the projector, then rehydrate through the plain
        // allowlist array. The precise UTC stamp must come back intact.
        $this->getJson($this->jsonUrl())->assertOk()->assertJsonPath('updatedAt', '2026-10-04T15:37:00Z');

        $cached = $this->cache()->remember($this->defaultPage());

        $this->assertSame('2026-10-04T15:37:00Z', $cached->updatedAt);
        $this->assertSame(
            ['banner', 'services', 'updatedDayBucket', 'updatedAt'],
            array_keys($cached->toArray())
        );
    }
}
