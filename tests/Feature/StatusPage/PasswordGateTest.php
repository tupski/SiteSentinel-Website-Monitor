<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Password Protected unlock flow (STATUS-PAGE.md §3, §12.2), per page.
 *
 * No data is rendered before a correct password; failures are uniform and
 * throttled; a correct password persists in-session; rotation revokes it.
 * Entering a page's password never unlocks another page.
 */
final class PasswordGateTest extends StatusPageTestCase
{
    private function protectWith(string $password): StatusPage
    {
        return $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make($password),
        ]);
    }

    private function showUrl(StatusPage $page): string
    {
        return route('status.show', ['statusPage' => $page->slug]);
    }

    public function test_correct_password_unlocks_and_session_persists(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->protectWith('correct-horse-battery');

        $locked = $this->get($this->showUrl($page));
        $locked->assertDontSee('Alpha', false);
        $this->forwardSessionCookie($locked);

        $unlock = $this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'correct-horse-battery']);
        $unlock->assertRedirect($this->showUrl($page));
        $this->forwardSessionCookie($unlock);

        // Subsequent request in the same session skips the form.
        $this->get($this->showUrl($page))->assertOk()->assertSee('Alpha', false);
    }

    public function test_wrong_password_returns_422_with_zero_data(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->protectWith('correct-horse-battery');

        $response = $this->postJson(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'wrong-password-here']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
        $response->assertDontSee('Alpha', false);
    }

    public function test_missing_password_is_rejected(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->protectWith('correct-horse-battery');

        $this->postJson(route('status.unlock', ['statusPage' => $page->slug]), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_empty_password_is_rejected(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->protectWith('correct-horse-battery');

        $this->postJson(route('status.unlock', ['statusPage' => $page->slug]), ['password' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_throttle_blocks_after_five_attempts_with_retry_after(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->protectWith('correct-horse-battery');

        // A real browser session: the throttle key is ip + session id, so the
        // same session cookie must be carried across attempts.
        $locked = $this->get($this->showUrl($page));
        $this->forwardSessionCookie($locked);

        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $r = $this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'wrong-password-x']);
            $statuses[] = $r->getStatusCode();
            $this->forwardSessionCookie($r);
        }

        // Five failures are permitted (form errors redirect back), none throttled.
        $this->assertNotContains(429, $statuses);

        $blocked = $this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'wrong-password-x']);
        $blocked->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        $this->assertStringNotContainsString('Alpha', (string) $blocked->getContent());
    }

    public function test_throttle_uses_ten_minute_window(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->protectWith('correct-horse-battery');

        $locked = $this->get($this->showUrl($page));
        $this->forwardSessionCookie($locked);

        for ($i = 0; $i < 5; $i++) {
            $this->forwardSessionCookie($this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'wrong-password-x']));
        }

        $blocked = $this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'wrong-password-x']);
        $blocked->assertStatus(429);

        // Retry-After should be on the order of the 10-minute window.
        $this->assertLessThanOrEqual(600, (int) $blocked->headers->get('Retry-After'));
        $this->assertGreaterThan(540, (int) $blocked->headers->get('Retry-After'));
    }

    public function test_password_change_revokes_existing_unlock(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->protectWith('correct-horse-battery');

        $locked = $this->get($this->showUrl($page));
        $this->forwardSessionCookie($locked);
        $this->forwardSessionCookie($this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'correct-horse-battery']));

        $this->get($this->showUrl($page))->assertOk()->assertSee('Alpha', false);

        // Rotate the password: updated_at advances, the old unlock is revoked.
        Carbon::setTestNow(now()->addMinute());
        $page->password_hash = Hash::make('rotated-secret-passphrase');
        $page->save();
        Carbon::setTestNow();

        $this->get($this->showUrl($page))->assertOk()->assertDontSee('Alpha', false);
    }

    public function test_password_hash_never_leaks_into_body_or_cache(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $hash = Hash::make('correct-horse-battery');
        $page = $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, ['password_hash' => $hash]);

        $locked = $this->get($this->showUrl($page));
        $this->assertStringNotContainsString($hash, (string) $locked->getContent());
        $this->forwardSessionCookie($locked);

        $unlock = $this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'correct-horse-battery']);
        $this->forwardSessionCookie($unlock);

        $body = (string) $this->get($this->showUrl($page))->getContent();
        $this->assertStringNotContainsString($hash, $body);
        $this->assertStringNotContainsString('correct-horse-battery', $body);

        // The cached projection artefact must not carry the hash either.
        $store = Cache::getStore();
        $this->assertTrue(method_exists($store, 'all'), 'Expected an introspectable array cache store.');

        /** @var array<string, mixed> $entries */
        $entries = $store->all();
        $this->assertNotEmpty($entries, 'The projection cache should hold at least one entry.');

        foreach ($entries as $key => $value) {
            $this->assertStringNotContainsString($hash, (string) $key);
            $this->assertStringNotContainsString($hash, (string) json_encode($value));
        }
    }

    public function test_unlocking_one_page_does_not_unlock_another(): void
    {
        $pageA = $this->makePage(['slug' => 'alpha-page']);
        $pageB = $this->makePage(['slug' => 'beta-page']);
        $this->setPageMode($pageA, StatusPage::MODE_PASSWORD_PROTECTED, ['password_hash' => Hash::make('alpha-secret-passphrase')]);
        $this->setPageMode($pageB, StatusPage::MODE_PASSWORD_PROTECTED, ['password_hash' => Hash::make('beta-secret-passphrase')]);

        $this->makeWebsite(['status_alias' => 'Service A', 'status_page_id' => $pageA->id]);
        $this->makeWebsite(['status_alias' => 'Service B', 'status_page_id' => $pageB->id]);

        // Unlock page B only.
        $this->forwardSessionCookie($this->get($this->showUrl($pageB)));
        $this->forwardSessionCookie($this->post(route('status.unlock', ['statusPage' => $pageB->slug]), ['password' => 'beta-secret-passphrase']));

        // Page B is unlocked...
        $this->get($this->showUrl($pageB))->assertOk()->assertSee('Service B', false);

        // ...but page A remains locked and never shows page B's data.
        $lockedHtml = (string) $this->get($this->showUrl($pageA))->getContent();
        $this->assertStringNotContainsString('Service A', $lockedHtml);
        $this->assertStringNotContainsString('Service B', $lockedHtml);
    }

    public function test_page_a_password_does_not_unlock_page_b(): void
    {
        $pageA = $this->makePage(['slug' => 'alpha-page']);
        $pageB = $this->makePage(['slug' => 'beta-page']);
        $this->setPageMode($pageA, StatusPage::MODE_PASSWORD_PROTECTED, ['password_hash' => Hash::make('shared-known-passphrase')]);
        $this->setPageMode($pageB, StatusPage::MODE_PASSWORD_PROTECTED, ['password_hash' => Hash::make('different-page-passphrase')]);
        $this->makeWebsite(['status_alias' => 'Service B', 'status_page_id' => $pageB->id]);

        $this->forwardSessionCookie($this->get($this->showUrl($pageA)));
        $this->forwardSessionCookie($this->post(route('status.unlock', ['statusPage' => $pageA->slug]), ['password' => 'shared-known-passphrase']));

        // Page B must still be locked despite page A being unlocked.
        $this->get($this->showUrl($pageB))->assertDontSee('Service B', false);
    }

    public function test_fail_closed_empty_hash_blocks_render(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, ['password_hash' => null]);

        // Locked page must not render data; the controller aborts 404 (fail closed).
        $this->get($this->showUrl($page))->assertNotFound();

        // Even a well-formed POST cannot unlock without a configured hash: the
        // form redirects back with an error and never renders service data.
        $attempt = $this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'anything-at-all']);
        $attempt->assertStatus(302);
        $attempt->assertSessionHasErrors('password');
        $this->assertStringNotContainsString('Alpha', (string) $attempt->getContent());
    }

    public function test_unlock_route_is_404_when_not_password_mode(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $page = $this->setMode(StatusPage::MODE_PUBLIC);

        $this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'whatever'])->assertNotFound();
    }
}
