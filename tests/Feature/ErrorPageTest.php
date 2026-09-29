<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase 1 error-page contract (AC-1-06).
 *
 * In production mode, unhandled exceptions must render a safe error page
 * with no stack trace, no framework internals, and no environment leakage.
 */
final class ErrorPageTest extends TestCase
{
    public function test_404_renders_a_safe_error_page(): void
    {
        $response = $this->get('/definitely-not-a-route-'.bin2hex(random_bytes(4)));

        $response->assertNotFound();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('404', $html);
        $this->assertStringNotContainsString('stack trace', $html);
        $this->assertStringNotContainsString('vendor/', $html, 'error pages must not reveal framework paths');
        $this->assertStringNotContainsString('.php', $html, 'error pages must not reveal script paths');
    }

    public function test_production_mode_hides_exception_details(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('/definitely-not-a-route-'.bin2hex(random_bytes(4)));

        $response->assertNotFound();

        $html = (string) $response->getContent();

        // APP_DEBUG=false must not surface framework internals
        $this->assertStringNotContainsString((string) config('app.key'), $html);
        $this->assertStringNotContainsString('PDOException', $html);
        $this->assertStringNotContainsString('laravel/framework', $html);
    }
}
