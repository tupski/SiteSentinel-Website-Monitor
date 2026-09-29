<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase 1 base layout contract (AC-1-04).
 *
 * Verifies the welcome view extends the base layout and the Vite build
 * manifest registers the Turbo/Tailwind entrypoints. The production asset
 * build itself is exercised by `npm run build` in CI (Build-type test).
 */
final class BaseLayoutTest extends TestCase
{
    public function test_base_layout_renders_with_vite_wiring_and_csrf_meta(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('name="csrf-token"', $html, 'base layout must carry the CSRF meta tag for Turbo');
        $this->assertStringContainsString('SiteSentinel', $html);
    }

    public function test_vite_build_manifest_registers_the_turbo_and_tailwind_entrypoints(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! file_exists($manifest)) {
            $this->markTestSkipped('Vite build not present — run `npm run build` (executed in CI).');
        }

        $entries = json_decode((string) file_get_contents($manifest), true);

        $this->assertIsArray($entries);
        $this->assertArrayHasKey('resources/css/app.css', $entries, 'Tailwind entrypoint must be built');
        $this->assertArrayHasKey('resources/js/app.js', $entries, 'Turbo/Alpine entrypoint must be built');
    }
}
