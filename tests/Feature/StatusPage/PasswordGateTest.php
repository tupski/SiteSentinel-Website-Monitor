<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPageSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Password Protected unlock flow (STATUS-PAGE.md §3, §12.2).
 *
 * No data is rendered before a correct password; failures are uniform and
 * throttled; a correct password persists in-session; rotation revokes it.
 */
final class PasswordGateTest extends StatusPageTestCase
{
    private function protectWith(string $password): StatusPageSetting
    {
        return $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make($password),
        ]);
    }

    public function test_correct_password_unlocks_and_session_persists(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->protectWith('correct-horse-battery');

        $locked = $this->get(route('status.show'));
        $locked->assertDontSee('Alpha', false);
        $this->forwardSessionCookie($locked);

        $unlock = $this->post(route('status.unlock'), ['password' => 'correct-horse-battery']);
        $unlock->assertRedirect(route('status.show'));
        $this->forwardSessionCookie($unlock);

        // Subsequent request in the same session skips the form.
        $this->get(route('status.show'))->assertOk()->assertSee('Alpha', false);
    }

    public function test_wrong_password_returns_422_with_zero_data(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->protectWith('correct-horse-battery');

        $response = $this->postJson(route('status.unlock'), ['password' => 'wrong-password-here']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
        $response->assertDontSee('Alpha', false);
    }

    public function test_missing_password_is_rejected(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->protectWith('correct-horse-battery');

        $this->postJson(route('status.unlock'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_empty_password_is_rejected(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->protectWith('correct-horse-battery');

        $this->postJson(route('status.unlock'), ['password' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_throttle_blocks_after_five_attempts_with_retry_after(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->protectWith('correct-horse-battery');

        // A real browser session: the throttle key is ip + session id, so the
        // same session cookie must be carried across attempts.
        $locked = $this->get(route('status.show'));
        $this->forwardSessionCookie($locked);

        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $r = $this->post(route('status.unlock'), ['password' => 'wrong-password-x']);
            $statuses[] = $r->getStatusCode();
            $this->forwardSessionCookie($r);
        }

        // Five failures are permitted (form errors redirect back), none throttled.
        $this->assertNotContains(429, $statuses);

        $blocked = $this->post(route('status.unlock'), ['password' => 'wrong-password-x']);
        $blocked->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        $this->assertStringNotContainsString('Alpha', (string) $blocked->getContent());
    }

    public function test_throttle_uses_ten_minute_window(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->protectWith('correct-horse-battery');

        $locked = $this->get(route('status.show'));
        $this->forwardSessionCookie($locked);

        for ($i = 0; $i < 5; $i++) {
            $this->forwardSessionCookie($this->post(route('status.unlock'), ['password' => 'wrong-password-x']));
        }

        $blocked = $this->post(route('status.unlock'), ['password' => 'wrong-password-x']);
        $blocked->assertStatus(429);

        // Retry-After should be on the order of the 10-minute window.
        $this->assertLessThanOrEqual(600, (int) $blocked->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
    }

    public function test_logout_clears_the_unlock(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->protectWith('correct-horse-battery');

        $locked = $this->get(route('status.show'));
        $this->forwardSessionCookie($locked);
        $unlock = $this->post(route('status.unlock'), ['password' => 'correct-horse-battery']);
        $this->forwardSessionCookie($unlock);

        $this->get(route('status.show'))->assertSee('Alpha', false);

        $this->post(route('status.logout'))->assertRedirect(route('status.show'));

        // After logout the page is locked again: no rows.
        $this->get(route('status.show'))->assertOk()->assertDontSee('Alpha', false);
    }

    public function test_rotation_via_updated_at_revokes_old_session(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->protectWith('correct-horse-battery');

        $locked = $this->get(route('status.show'));
        $this->forwardSessionCookie($locked);
        $unlock = $this->post(route('status.unlock'), ['password' => 'correct-horse-battery']);
        $this->forwardSessionCookie($unlock);

        $this->get(route('status.show'))->assertSee('Alpha', false);

        Carbon::setTestNow(now('UTC')->addMinute());
        $settings = StatusPageSetting::singleton();
        $settings->password_hash = Hash::make('rotated-secret-passphrase');
        $settings->save();
        Carbon::setTestNow();

        $this->get(route('status.show'))->assertOk()->assertDontSee('Alpha', false);
    }

    public function test_password_hash_never_appears_in_body_or_cache(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $settings = $this->protectWith('correct-horse-battery');
        $hash = (string) $settings->password_hash;

        $this->assertNotSame('', $hash);
        $this->assertNotSame('correct-horse-battery', $hash, 'Password must be stored hashed.');

        $this->setMode(StatusPageSetting::MODE_PUBLIC);
        $body = (string) $this->get(route('status.show'))->getContent();
        $this->assertStringNotContainsString($hash, $body);
        $this->assertStringNotContainsString('correct-horse-battery', $body);

        // The cached projection artefact must not carry the hash either:
        // scan every cache key and value for the raw hash.
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

    public function test_fail_closed_empty_hash_blocks_render(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, ['password_hash' => null]);

        // Locked page must not render data; the controller aborts 404 (fail closed).
        $this->get(route('status.show'))->assertNotFound();

        // Even a well-formed POST cannot unlock without a configured hash: the
        // form redirects back with an error and never renders service data.
        $attempt = $this->post(route('status.unlock'), ['password' => 'anything-at-all']);
        $attempt->assertStatus(302);
        $attempt->assertSessionHasErrors('password');
        $this->assertStringNotContainsString('Alpha', (string) $attempt->getContent());
    }

    public function test_unlock_route_is_404_when_not_password_mode(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $this->post(route('status.unlock'), ['password' => 'whatever'])->assertNotFound();
    }
}
