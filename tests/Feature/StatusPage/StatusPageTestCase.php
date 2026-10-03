<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Incident;
use App\Models\StatusPageSetting;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared helpers for the Phase 8 status-page test suite (STATUS-PAGE.md §12).
 *
 * These tests assert observable response bodies (HTML source + JSON) only.
 * Where the cache is inspected it is through the public StatusPageCache API,
 * never raw rows.
 */
abstract class StatusPageTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Status views wire @vite; the asset manifest is irrelevant to the
        // redaction/visibility contract under test.
        $this->withoutVite();

        // Array cache is the suite store (phpunit.xml). Flush it so the
        // rate limiter and the projection cache start clean per test.
        Cache::flush();
    }

    protected function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    protected function viewer(): User
    {
        return User::factory()->create([
            'role' => 'viewer',
            'is_active' => true,
        ]);
    }

    private static int $hostSeq = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeWebsite(array $overrides = []): Website
    {
        self::$hostSeq++;
        $host = 'site-'.self::$hostSeq.'.test';

        return Website::create(array_merge([
            'name' => 'Example',
            'url' => "https://{$host}/",
            'scheme' => 'https',
            'host' => $host,
            'is_active' => true,
            'is_visible_on_status' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
            'status_availability' => 'UP',
            'status_security' => 'OK',
            'last_checked_at' => now('UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeIncident(Website $website, array $overrides = []): Incident
    {
        return Incident::create(array_merge([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now('UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function setMode(string $mode, array $attributes = []): StatusPageSetting
    {
        $settings = StatusPageSetting::singleton();
        $settings->visibility_mode = $mode;

        foreach ($attributes as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();

        return $settings->refresh();
    }

    /**
     * The Laravel test client keeps no cookie jar between calls; forward the
     * session cookie explicitly (as a browser would) so an unlock persists.
     */
    protected function forwardSessionCookie(TestResponse $response): void
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c): bool => str_contains($c->getName(), 'session'));

        if ($cookie !== null) {
            $this->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
        }
    }

    /**
     * Perform the real unlock POST and carry the resulting session cookie so
     * subsequent requests share the unlocked session.
     */
    protected function unlockWith(string $password): TestResponse
    {
        $response = $this->post(route('status.unlock'), ['password' => $password]);
        $this->forwardSessionCookie($response);

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function servicesJson(): array
    {
        $response = $this->getJson(route('status.json'));
        $response->assertOk();

        /** @var array<int, array<string, mixed>> $services */
        $services = $response->json('services');

        return $services;
    }

    protected function labelFor(string $alias): ?string
    {
        foreach ($this->servicesJson() as $service) {
            if (($service['displayName'] ?? null) === $alias) {
                return $service['publicLabel'] ?? null;
            }
        }

        return null;
    }
}
