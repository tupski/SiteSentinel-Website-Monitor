<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPageSetting;
use Illuminate\Support\Facades\Hash;

/**
 * Visibility gate across the three modes (STATUS-PAGE.md §2, §12.1, §12.2).
 *
 * Private hides existence behind a 404 (never a login redirect); Public serves
 * anonymously; Password Protected shows a form with zero rows until unlocked.
 */
final class VisibilityGateTest extends StatusPageTestCase
{
    public function test_private_mode_returns_404_to_anonymous_never_a_login_redirect(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PRIVATE);

        $response = $this->get(route('status.show'));

        $response->assertNotFound();
        $response->assertStatus(404);
        $this->assertNotSame(302, $response->getStatusCode());
        $response->assertDontSee('Alpha', false);

        $json = $this->getJson(route('status.json'));
        $json->assertNotFound();
    }

    public function test_private_mode_non_admin_is_not_advertised_and_forbidden_from_data(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PRIVATE);
        $this->actingAs($this->viewer());

        $this->get(route('status.show'))->assertNotFound();
    }

    public function test_private_mode_admin_sees_projection(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PRIVATE);
        $this->actingAs($this->admin());

        $response = $this->get(route('status.show'));

        $response->assertOk();
        $response->assertSee('Alpha', false);
    }

    public function test_public_mode_anonymous_sees_projection(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $response = $this->get(route('status.show'));

        $response->assertOk();
        $response->assertSee('Alpha', false);

        $json = $this->getJson(route('status.json'));
        $json->assertOk();
        $json->assertJsonPath('services.0.displayName', 'Alpha');
    }

    public function test_password_locked_shows_form_with_zero_rows(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->get(route('status.show'));

        $response->assertOk();
        $response->assertSee('Status page locked', false);
        $response->assertDontSee('Alpha', false);
        $this->assertStringNotContainsString('Alpha', (string) $response->getContent());
    }

    public function test_password_unlocked_shows_projection(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $locked = $this->get(route('status.show'));
        $this->forwardSessionCookie($locked);

        $unlock = $this->post(route('status.unlock'), ['password' => 'correct-horse-battery']);
        $unlock->assertRedirect(route('status.show'));
        $this->forwardSessionCookie($unlock);

        $response = $this->get(route('status.show'));
        $response->assertOk();
        $response->assertSee('Alpha', false);
    }

    public function test_mode_change_takes_effect_immediately(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);

        $this->setMode(StatusPageSetting::MODE_PUBLIC);
        $this->get(route('status.show'))->assertOk();

        $this->setMode(StatusPageSetting::MODE_PRIVATE);
        $this->get(route('status.show'))->assertNotFound();

        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);
        $this->get(route('status.show'))->assertOk()->assertDontSee('Alpha', false);
    }

    public function test_disabled_website_is_hidden(): void
    {
        $this->makeWebsite(['status_alias' => 'Hidden', 'is_active' => false]);
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $response = $this->get(route('status.show'));
        $response->assertOk();
        $response->assertDontSee('Hidden', false);
    }

    public function test_unpublished_website_is_hidden(): void
    {
        $this->makeWebsite(['status_alias' => 'Unpublished', 'is_visible_on_status' => false]);
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $response = $this->get(route('status.show'));
        $response->assertOk();
        $response->assertDontSee('Unpublished', false);
    }

    public function test_fail_closed_password_mode_without_hash_returns_404(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, ['password_hash' => null]);

        $this->get(route('status.show'))->assertNotFound();
    }
}
