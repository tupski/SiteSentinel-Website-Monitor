<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPageSetting;
use Illuminate\Support\Facades\Hash;

/**
 * HTTP surface contract for /status + admin settings (STATUS-PAGE.md §8, §10.3,
 * §11.3; §12.1).
 *
 * Semantic markup, correct headers (no-store vs public max-age, noindex),
 * safe redirects, no admin controls in public output, and admin validation.
 */
final class StatusPageHttpTest extends StatusPageTestCase
{
    public function test_public_html_has_semantic_markup_and_labels(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha', 'status_availability' => 'UP', 'status_security' => 'OK']);
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $response = $this->get(route('status.show'));
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('<section', $html);
        $this->assertStringContainsString('aria-label="Services"', $html);
        $this->assertStringContainsString('Operational', $html);
        $this->assertStringContainsString('Alpha', $html);
    }

    public function test_public_mode_sets_public_cache_control_and_noindex(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $response = $this->get(route('status.show'));
        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

        $json = $this->getJson(route('status.json'));
        $json->assertOk();
        $jsonCacheControl = (string) $json->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $jsonCacheControl);
        $this->assertStringContainsString('max-age=60', $jsonCacheControl);
        $this->assertSame('noindex, nofollow', $json->headers->get('X-Robots-Tag'));
    }

    public function test_private_admin_view_is_no_store(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PRIVATE);
        $this->actingAs($this->admin());

        $response = $this->get(route('status.show'));
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_locked_password_form_is_no_store_and_noindex(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->get(route('status.show'));
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_robots_txt_disallows_status(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /status', $robots);
    }

    public function test_empty_state_renders_no_services_message(): void
    {
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $response = $this->get(route('status.show'));
        $response->assertOk();
        $response->assertSee('No services published.', false);

        $json = $this->getJson(route('status.json'));
        $json->assertOk();
        $json->assertJsonPath('services', []);
        $json->assertJsonPath('banner', 'Unknown');
    }

    public function test_public_output_has_no_admin_controls_nav_or_ids(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $html = (string) $this->get(route('status.show'))->getContent();

        // No admin nav / controls / logout / csrf meta in the public surface.
        $this->assertStringNotContainsString('/admin', $html);
        $this->assertStringNotContainsString('Log out', $html);
        $this->assertStringNotContainsString('csrf-token', $html);
        $this->assertStringNotContainsString('SiteSentinel', $html);
    }

    public function test_locked_form_has_no_admin_controls(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $html = (string) $this->get(route('status.show'))->getContent();
        $this->assertStringNotContainsString('/admin', $html);
        $this->assertStringNotContainsString('csrf-token', $html);
    }

    public function test_logout_redirects_safely_to_status_page(): void
    {
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $this->post(route('status.logout'))->assertRedirect(route('status.show'));
    }

    public function test_admin_settings_require_admin(): void
    {
        $this->get(route('admin.status-settings.edit'))->assertRedirect(route('login'));

        $this->actingAs($this->viewer());
        $this->get(route('admin.status-settings.edit'))->assertForbidden();
    }

    public function test_admin_settings_form_renders(): void
    {
        $this->makeWebsite(['name' => 'Alpha']);
        $this->actingAs($this->admin());

        $response = $this->get(route('admin.status-settings.edit'));
        $response->assertOk();
        $response->assertSee('Status page settings', false);
    }

    public function test_admin_update_validates_visibility_mode(): void
    {
        $this->actingAs($this->admin());

        $response = $this->put(route('admin.status-settings.update'), [
            'visibility_mode' => 'Nonsense',
        ]);

        $response->assertSessionHasErrors('visibility_mode');
    }

    public function test_going_public_requires_confirmation(): void
    {
        $this->actingAs($this->admin());

        $response = $this->put(route('admin.status-settings.update'), [
            'visibility_mode' => StatusPageSetting::MODE_PUBLIC,
        ]);

        $response->assertSessionHasErrors('confirm_public');
    }

    public function test_password_mode_requires_a_password(): void
    {
        $this->actingAs($this->admin());

        $response = $this->put(route('admin.status-settings.update'), [
            'visibility_mode' => StatusPageSetting::MODE_PASSWORD_PROTECTED,
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_password_mode_requires_matching_confirmation(): void
    {
        $this->actingAs($this->admin());

        $response = $this->put(route('admin.status-settings.update'), [
            'visibility_mode' => StatusPageSetting::MODE_PASSWORD_PROTECTED,
            'password' => 'a-long-enough-passphrase',
            'password_confirmation' => 'a-different-passphrase',
        ]);

        $response->assertSessionHasErrors('password_confirmation');
    }

    public function test_admin_can_go_public_with_confirmation(): void
    {
        $website = $this->makeWebsite(['name' => 'Alpha']);
        $this->actingAs($this->admin());

        $response = $this->put(route('admin.status-settings.update'), [
            'visibility_mode' => StatusPageSetting::MODE_PUBLIC,
            'confirm_public' => 1,
            'published' => [$website->id],
            'aliases' => [$website->id => 'Alpha'],
        ]);

        $response->assertRedirect(route('admin.status-settings.edit'));
        $this->assertTrue(StatusPageSetting::singleton()->refresh()->isPublic());
    }
}
