<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;

/**
 * Production configuration regression suite (SECURITY.md §10, §11).
 *
 * These are config-shape assertions (not environment-dependent) that catch a
 * regression such as a debug/diagnostic route being introduced or the secure
 * cookie defaults drifting.
 */
final class ProductionConfigTest extends SecurityTestCase
{
    public function test_no_debug_or_diagnostic_routes_are_registered(): void
    {
        $forbidden = ['debug', 'telescope', 'horizon', '_ignition', 'phpinfo'];

        foreach (Route::getRoutes() as $route) {
            $uri = ltrim($route->uri(), '/');
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    strtolower($uri),
                    "Debug/diagnostic route '{$uri}' must not be exposed",
                );
            }
        }
    }

    public function test_no_public_registration_route_exists(): void
    {
        $this->assertFalse(Route::has('register'), 'A public registration route must never exist (AC-2-03)');
        $this->assertFalse(Route::has('register.store'));

        $uris = collect(Route::getRoutes())->map(fn ($r) => ltrim($r->uri(), '/'))->all();
        $this->assertNotContains('register', $uris);
    }

    public function test_only_canonical_top_level_routes_are_public(): void
    {
        // PRD §22 fixes the public surface: /, /admin, /status (+health),
        // with the authenticated logout and the guest password-reset flow.
        // Phase 11 (ADR-031) serves each page at /status/{slug}.
        $allowed = [
            '', '/', 'health', 'up',
            'status', 'status.json',
            'status/{statusPage}', 'status/{statusPage}.json',
            'status/{statusPage}/unlock', 'status/{statusPage}/logout',
            'logout', 'storage/{path}',
        ];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'admin') || str_starts_with($uri, 'password-reset')) {
                continue;
            }
            $this->assertContains(
                $uri,
                $allowed,
                "Unexpected public route '{$uri}' — the canonical route map fixes the surface",
            );
        }
    }

    public function test_secure_session_cookie_defaults(): void
    {
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertContains((string) config('session.same_site'), ['lax', 'strict']);
    }

    public function test_health_endpoint_is_public_and_minimal(): void
    {
        $this->assertTrue(Route::has('health'));
        $this->assertContains('GET', Route::getRoutes()->getByName('health')->methods());
    }
}
