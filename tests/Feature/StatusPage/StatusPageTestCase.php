<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared helpers for the status-page test suite (STATUS-PAGE.md §12, ADR-031).
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

    private static int $slugSeq = 0;

    /**
     * Create (or fetch) the default status page.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function defaultPage(array $overrides = []): StatusPage
    {
        return StatusPage::query()->firstOrCreate(
            ['slug' => StatusPage::DEFAULT_SLUG],
            array_merge([
                'name' => 'Default page',
                'is_default' => true,
                'visibility_mode' => StatusPage::MODE_PRIVATE,
                'password_hash' => null,
            ], $overrides)
        );
    }

    /**
     * Create a non-default status page.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makePage(array $overrides = []): StatusPage
    {
        self::$slugSeq++;
        $slug = $overrides['slug'] ?? 'page-'.self::$slugSeq;

        return StatusPage::query()->create(array_merge([
            'name' => 'Page '.self::$slugSeq,
            'slug' => $slug,
            'is_default' => false,
            'visibility_mode' => StatusPage::MODE_PRIVATE,
            'password_hash' => null,
        ], $overrides));
    }

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
     * Set the default page's visibility mode.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function setMode(string $mode, array $attributes = []): StatusPage
    {
        $settings = $this->defaultPage();
        $settings->visibility_mode = $mode;

        foreach ($attributes as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();

        return $settings->refresh();
    }

    /**
     * Set a specific page's visibility mode.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function setPageMode(StatusPage $page, string $mode, array $attributes = []): StatusPage
    {
        $page->visibility_mode = $mode;

        foreach ($attributes as $key => $value) {
            $page->{$key} = $value;
        }

        $page->save();

        return $page->refresh();
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
     * Perform the real unlock POST for the default page and carry the session.
     */
    protected function unlockWith(string $password): TestResponse
    {
        $response = $this->post(route('status.unlock', ['statusPage' => $this->defaultPage()->slug]), ['password' => $password]);
        $this->forwardSessionCookie($response);

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function servicesJson(?StatusPage $page = null): array
    {
        $page ??= $this->defaultPage();
        $response = $this->getJson(route('status.json', ['statusPage' => $page->slug]));
        $response->assertOk();

        /** @var array<int, array<string, mixed>> $services */
        $services = $response->json('services');

        return $services;
    }

    protected function labelFor(string $alias, ?StatusPage $page = null): ?string
    {
        foreach ($this->servicesJson($page) as $service) {
            if (($service['displayName'] ?? null) === $alias) {
                return $service['publicLabel'] ?? null;
            }
        }

        return null;
    }
}
