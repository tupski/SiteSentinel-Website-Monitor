<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPage;
use Illuminate\Support\Facades\Hash;

/**
 * Visibility gate across the three modes (STATUS-PAGE.md §2, §12.1, §12.2),
 * now per page (ADR-031).
 *
 * Private hides existence behind a 404 (never a login redirect); Public serves
 * anonymously; Password Protected shows a form with zero rows until unlocked.
 */
final class VisibilityGateTest extends StatusPageTestCase
{
    public function test_private_mode_returns_404_to_anonymous_never_a_login_redirect(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PRIVATE);

        $response = $this->get(route('status.show', ['statusPage' => $this->defaultPage()->slug]));

        $response->assertNotFound();
        $response->assertStatus(404);
        $this->assertNotSame(302, $response->getStatusCode());
        $response->assertDontSee('Alpha', false);

        $json = $this->getJson(route('status.json', ['statusPage' => $this->defaultPage()->slug]));
        $json->assertNotFound();
    }

    public function test_private_mode_non_admin_is_not_advertised_and_forbidden_from_data(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PRIVATE);
        $this->actingAs($this->viewer());

        $this->get(route('status.show', ['statusPage' => $this->defaultPage()->slug]))->assertNotFound();
    }

    public function test_private_mode_admin_sees_projection(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PRIVATE);
        $this->actingAs($this->admin());

        $response = $this->get(route('status.show', ['statusPage' => $this->defaultPage()->slug]));

        $response->assertOk();
        $response->assertSee('Alpha', false);
    }

    public function test_public_mode_anonymous_sees_projection(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $response = $this->get(route('status.show', ['statusPage' => $this->defaultPage()->slug]));

        $response->assertOk();
        $response->assertSee('Alpha', false);

        $json = $this->getJson(route('status.json', ['statusPage' => $this->defaultPage()->slug]));
        $json->assertOk();
        $json->assertJsonPath('services.0.displayName', 'Alpha');
    }

    public function test_password_locked_shows_form_with_zero_rows(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->get(route('status.show', ['statusPage' => $this->defaultPage()->slug]));

        $response->assertOk();
        $response->assertSee('Status page locked', false);
        $response->assertDontSee('Alpha', false);
        $this->assertStringNotContainsString('Alpha', (string) $response->getContent());
    }

    public function test_password_unlocked_shows_projection(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $locked = $this->get(route('status.show', ['statusPage' => $page->slug]));
        $this->forwardSessionCookie($locked);

        $unlock = $this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'correct-horse-battery']);
        $unlock->assertRedirect(route('status.show', ['statusPage' => $page->slug]));
        $this->forwardSessionCookie($unlock);

        $response = $this->get(route('status.show', ['statusPage' => $page->slug]));
        $response->assertOk();
        $response->assertSee('Alpha', false);
    }

    public function test_mode_change_takes_effect_immediately(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->defaultPage();

        $this->setMode(StatusPage::MODE_PUBLIC);
        $this->get(route('status.show', ['statusPage' => $page->slug]))->assertOk();

        $this->setMode(StatusPage::MODE_PRIVATE);
        $this->get(route('status.show', ['statusPage' => $page->slug]))->assertNotFound();

        $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);
        $this->get(route('status.show', ['statusPage' => $page->slug]))->assertOk()->assertDontSee('Alpha', false);
    }

    public function test_disabled_website_is_hidden(): void
    {
        $this->makeWebsite(['status_alias' => 'Hidden', 'is_active' => false]);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $response = $this->get(route('status.show', ['statusPage' => $this->defaultPage()->slug]));
        $response->assertOk();
        $response->assertDontSee('Hidden', false);
    }

    public function test_unpublished_website_is_hidden(): void
    {
        $this->makeWebsite(['status_alias' => 'Unpublished', 'is_visible_on_status' => false]);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $response = $this->get(route('status.show', ['statusPage' => $this->defaultPage()->slug]));
        $response->assertOk();
        $response->assertDontSee('Unpublished', false);
    }

    public function test_fail_closed_password_mode_without_hash_returns_404(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, ['password_hash' => null]);

        $this->get(route('status.show', ['statusPage' => $this->defaultPage()->slug]))->assertNotFound();
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $this->get(route('status.show', ['statusPage' => 'does-not-exist']))->assertNotFound();
    }
}
