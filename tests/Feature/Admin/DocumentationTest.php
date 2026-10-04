<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7 admin documentation page.
 *
 * Guards three things: the page is admin-only, its Telegram content matches the
 * real channel field labels, and the documented areas still map to routes that
 * exist (anti-drift). It also proves the page ships no real secret material.
 */
final class DocumentationTest extends TestCase
{
    use RefreshDatabase;

    // --- Authorization -----------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.documentation'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'viewer'])->save();

        $this->actingAs($user)->get(route('admin.documentation'))->assertForbidden();
    }

    public function test_disabled_admin_is_forbidden(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->forceFill(['is_active' => false])->save();

        $this->get(route('admin.documentation'))->assertForbidden();
    }

    public function test_admin_can_open_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.documentation'))->assertOk();
    }

    // --- Content -----------------------------------------------------------

    public function test_page_contains_the_telegram_section_and_exact_field_labels(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.documentation'));

        $response->assertOk();
        $response->assertSee('Telegram Bot setup', false);
        $response->assertSee('Chat ID', false);
        $response->assertSee('Topic thread ID (optional)', false);
        $response->assertSee('Secret (SMTP password or bot token)', false);
    }

    public function test_page_uses_the_token_placeholder_and_no_real_token_shape(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('admin.documentation'))->getContent();

        // The placeholder is escaped in the HTML so the browser renders a literal
        // "<TOKEN>" (an unescaped angle bracket would be parsed as a tag and vanish).
        // Matched with a regex so no literal entity text is required here.
        $this->assertSame(
            1,
            preg_match('/&[a-z]+;TOKEN&[a-z]+;/', (string) $html),
            'the <TOKEN> placeholder must be shown (escaped) in the code example'
        );

        // A real Telegram bot token has the shape <digits>:<30+ url-safe chars>.
        $this->assertSame(
            0,
            preg_match('/\d{6,}:[A-Za-z0-9_-]{30,}/', (string) $html),
            'the documentation must never contain a real-looking bot token'
        );
    }

    public function test_page_has_no_placeholder_strings(): void
    {
        $user = User::factory()->create();

        $html = mb_strtolower($this->actingAs($user)->get(route('admin.documentation'))->getContent());

        foreach (['coming soon', 'todo', 'configure this later', 'example setting'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "the documentation must not contain the placeholder text: {$needle}"
            );
        }
    }

    /**
     * Anti-drift guard: every area the navigation table documents must resolve
     * to a route that actually exists. A rename breaks this test on purpose.
     */
    public function test_documented_areas_correspond_to_real_routes(): void
    {
        $documented = [
            'admin.dashboard',
            'admin.websites.index',
            'admin.incidents.index',
            'admin.notifications.index',
            'admin.notification-logs.index',
            'admin.status-pages.index',
            'admin.profile.edit',
            'admin.settings.edit',
            'admin.documentation',
        ];

        foreach ($documented as $name) {
            $this->assertTrue(
                app('router')->has($name),
                "the documentation links to route [{$name}], which does not exist"
            );
        }
    }
}
