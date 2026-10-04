<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\StatusPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3c — shared-shell dark-mode contract.
 *
 * The auth, status and error pages are the first surfaces a user sees, so they
 * must render theme-aware markup (semantic token utilities) rather than
 * light-only surfaces. These assertions are deliberately non-brittle: they
 * match semantic token class names, never a full class string, and the
 * absence checks target bare light-only surface wrappers (`bg-white` as a
 * whole class token) which no migrated view may carry.
 */
final class SharedShellDarkModeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Assert that a page's markup carries token utilities and none of the old
     * light-only *combinations* that the migrated views used.
     *
     * The shared theme switcher (out of this phase's scope) legitimately ships
     * `bg-white` paired with `dark:bg-slate-800`, so a page-wide `bg-white` ban
     * would be wrong; the absence checks therefore target the migrated views'
     * old card/section/button combinations instead.
     */
    private function assertThemeAware(string $html, string $label): void
    {
        $this->assertStringContainsString('text-text', $html, "{$label}: expected tokenised text colours");
        $this->assertStringContainsString('bg-surface', $html, "{$label}: expected tokenised surfaces");

        foreach ([
            'bg-white p-6' => 'light-only auth card surface',
            'bg-white p-4' => 'light-only status section surface',
            'bg-white shadow-sm' => 'light-only card surface',
            'bg-slate-50' => 'light-only canvas',
            'rounded bg-slate-900' => 'light-only primary button',
            'divide-slate-200' => 'light-only list divider',
        ] as $needle => $why) {
            $this->assertStringNotContainsString($needle, $html, "{$label}: found {$why} ({$needle})");
        }
    }

    public function test_login_page_is_theme_aware(): void
    {
        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertThemeAware($html, 'login');
        $this->assertStringContainsString('bg-primary', $html, 'login submit button must use the primary token');
        $this->assertStringContainsString('border-border-muted', $html, 'login inputs must be tokenised');

        // Theme plumbing + switcher inclusion are preserved.
        $this->assertStringContainsString("localStorage.getItem('theme')", $html);
        $this->assertStringContainsString('aria-label="Change theme"', $html);
        $this->assertStringContainsString('data-theme', $html);
    }

    public function test_password_reset_request_page_is_theme_aware(): void
    {
        $html = (string) $this->get(route('password.request'))->assertOk()->getContent();

        $this->assertThemeAware($html, 'password.request');
        $this->assertStringContainsString('border-border-muted', $html);
    }

    public function test_password_reset_form_page_is_theme_aware(): void
    {
        $html = (string) $this->get(route('password.reset', ['token' => 'test-token', 'email' => 'admin@example.com']))
            ->assertOk()
            ->getContent();

        $this->assertThemeAware($html, 'password.reset');
        $this->assertStringContainsString('name="token"', $html, 'hidden CSRF/token contract preserved');
    }

    public function test_status_show_page_is_theme_aware_without_theme_switcher(): void
    {
        $page = $this->publicStatusPage();

        $html = (string) $this->get(route('status.show', ['statusPage' => $page->slug]))
            ->assertOk()
            ->getContent();

        $this->assertThemeAware($html, 'status.show');

        // Public page: no switcher, but the no-FOUC bootstrap honours OS dark.
        $this->assertStringNotContainsString('aria-label="Change theme"', $html);
        $this->assertStringContainsString("localStorage.getItem('theme')", $html);
        $this->assertStringContainsString('data-theme', $html);

        // Structure/accessibility preserved.
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('aria-label="Services"', $html);
    }

    public function test_status_unlock_page_is_theme_aware(): void
    {
        $page = $this->lockedStatusPage();

        $html = (string) $this->get(route('status.show', ['statusPage' => $page->slug]))
            ->assertOk()
            ->getContent();

        $this->assertThemeAware($html, 'status.unlock');
        $this->assertStringContainsString('Status page locked', $html);
        $this->assertStringContainsString('border-border-muted', $html, 'unlock password input must be tokenised');
    }

    public function test_error_pages_are_theme_aware(): void
    {
        $notFound = (string) $this->get('/definitely-not-a-route-'.bin2hex(random_bytes(4)))->getContent();

        foreach ([404 => $notFound] as $code => $html) {
            // Error views ship their own minimal stylesheet but must still honour
            // the `.dark` class via an explicit dark rule.
            $this->assertStringContainsString('html.dark', $html, "{$code}: expected a dark-aware style rule");
            $this->assertStringContainsString('404', $html);
        }

        foreach (['404', '429', '500'] as $code) {
            $view = (string) file_get_contents(resource_path("views/errors/{$code}.blade.php"));

            $this->assertStringContainsString('html.dark', $view, "errors/{$code}: expected dark-mode rule");
            $this->assertStringContainsString('partials.theme-bootstrap', $view, "errors/{$code}: expected theme bootstrap");
            $this->assertStringNotContainsString('background: #fff', $view, "errors/{$code}: found a light-only surface");
        }
    }

    private function publicStatusPage(): StatusPage
    {
        return StatusPage::create([
            'name' => 'Public Status',
            'slug' => 'public-status-'.bin2hex(random_bytes(3)),
            'is_default' => false,
            'visibility_mode' => StatusPage::MODE_PUBLIC,
        ]);
    }

    private function lockedStatusPage(): StatusPage
    {
        return StatusPage::create([
            'name' => 'Locked Status',
            'slug' => 'locked-status-'.bin2hex(random_bytes(3)),
            'is_default' => false,
            'visibility_mode' => StatusPage::MODE_PASSWORD_PROTECTED,
            'password_hash' => bcrypt('secret'),
        ]);
    }
}
