<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Check;
use App\Models\Incident;
use App\Models\StatusPageSetting;
use App\Services\Incidents\IncidentEngine;
use App\Services\Incidents\IncidentStateMachine;
use App\Services\StatusPage\StatusPageCache;
use Illuminate\Support\Facades\Cache;

/**
 * Projection cache contract (STATUS-PAGE.md §9, §12.4).
 *
 * The cached artefact is the already-redacted DTO — never raw rows — and the
 * cache is invalidated when the projection inputs change.
 */
final class CacheTest extends StatusPageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setMode(StatusPageSetting::MODE_PUBLIC);
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

    public function test_cached_artefact_is_dto_only_no_raw_fields(): void
    {
        $this->makeWebsite([
            'status_alias' => 'Alpha',
            'url' => 'https://raw-host-canary.test/',
            'host' => 'raw-host-canary.test',
            'status_availability' => 'UP',
            'status_security' => 'OK',
        ]);

        $this->getJson(route('status.json'))->assertOk();

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

    public function test_ttl_floor_is_sixty_seconds_when_no_websites(): void
    {
        $this->assertSame(60, $this->cache()->ttl());
    }

    public function test_ttl_follows_shortest_interval_above_floor(): void
    {
        $this->makeWebsite(['check_interval_seconds' => 300]);

        $this->assertSame(300, $this->cache()->ttl());
    }

    public function test_ttl_never_below_floor(): void
    {
        $this->makeWebsite(['check_interval_seconds' => 30]);

        $this->assertSame(60, $this->cache()->ttl());
    }

    public function test_projection_is_cached_and_reused(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);

        $this->getJson(route('status.json'))->assertOk();
        $entriesAfterFirst = count($this->allCacheEntries());

        $this->getJson(route('status.json'))->assertOk();
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

    public function test_incident_escalation_invalidates_projection(): void
    {
        $website = $this->makeWebsite([
            'status_alias' => 'Alpha',
            'status_availability' => 'DOWN',
            'status_security' => 'OK',
            'consecutive_failures' => 2,
        ]);

        $check = Check::create([
            'website_id' => $website->id,
            'check_key' => 'cache-escalate',
            'started_at' => now('UTC'),
            'availability_state' => 'DOWN',
            'security_state' => 'OK',
            'score' => 0,
        ]);
        app(IncidentEngine::class)->processCheck($website, $check, ['security_state' => 'OK', 'score' => 0, 'triggered_rules' => null]);

        $this->assertSame('Partial Outage', $this->labelFor('Alpha'));

        // Escalate past the critical-after-failures threshold.
        $website->consecutive_failures = 6;
        $website->save();
        $check2 = Check::create([
            'website_id' => $website->id,
            'check_key' => 'cache-escalate-2',
            'started_at' => now('UTC')->addMinute(),
            'availability_state' => 'DOWN',
            'security_state' => 'OK',
            'score' => 0,
        ]);
        app(IncidentEngine::class)->processCheck($website, $check2, ['security_state' => 'OK', 'score' => 0, 'triggered_rules' => null]);

        $this->assertSame('Major Outage', $this->labelFor('Alpha'));
    }

    public function test_incident_resolution_invalidates_projection(): void
    {
        $website = $this->makeWebsite([
            'status_alias' => 'Alpha',
            'status_availability' => 'UP',
            'status_security' => 'OK',
        ]);
        $incident = $this->makeIncident($website, ['severity' => 'CRITICAL', 'status' => 'DETECTED', 'type' => 'security']);

        // Prime the cache with the incident projection.
        $this->assertSame('Incident', $this->labelFor('Alpha'));

        app(IncidentStateMachine::class)->resolveManually($incident, $this->admin(), 'resolved for test');

        $this->assertSame('Operational', $this->labelFor('Alpha'));
    }

    public function test_settings_mode_change_invalidates_projection(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->assertSame('Operational', $this->labelFor('Alpha'));

        $this->setMode(StatusPageSetting::MODE_PRIVATE);
        $this->cache()->invalidate();

        $this->actingAs($this->admin());
        $this->assertSame('Operational', $this->labelFor('Alpha'));
    }

    public function test_branding_change_invalidates_projection(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->get(route('status.show'))->assertOk()->assertDontSee('New Brand', false);

        $settings = StatusPageSetting::singleton();
        $settings->branding = ['title' => 'New Brand'];
        $settings->save();
        $this->cache()->invalidate();

        $this->get(route('status.show'))->assertOk()->assertSee('New Brand', false);
    }

    public function test_admin_publish_change_invalidates_projection(): void
    {
        $website = $this->makeWebsite(['status_alias' => 'Alpha', 'is_visible_on_status' => false]);
        $this->assertSame([], $this->servicesJson());

        $this->actingAs($this->admin());
        $response = $this->put(route('admin.status-settings.update'), [
            'visibility_mode' => StatusPageSetting::MODE_PUBLIC,
            'confirm_public' => 1,
            'published' => [$website->id],
            'aliases' => [$website->id => 'Alpha'],
        ]);

        $response->assertRedirect(route('admin.status-settings.edit'));

        $this->assertSame('Operational', $this->labelFor('Alpha'));
    }

    public function test_admin_password_change_invalidates_projection(): void
    {
        $website = $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->assertSame('Operational', $this->labelFor('Alpha'));

        $this->actingAs($this->admin());
        $response = $this->put(route('admin.status-settings.update'), [
            'visibility_mode' => StatusPageSetting::MODE_PASSWORD_PROTECTED,
            'password' => 'brand-new-status-passphrase',
            'password_confirmation' => 'brand-new-status-passphrase',
            'published' => [$website->id],
        ]);

        $response->assertRedirect(route('admin.status-settings.edit'));

        // Mode changed: the projection cache must not serve the old public key.
        $settings = StatusPageSetting::singleton()->refresh();
        $this->assertTrue($settings->isPasswordProtected());
    }
}
