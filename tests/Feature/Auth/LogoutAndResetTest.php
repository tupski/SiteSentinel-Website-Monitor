<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AC-2-04 logout invalidates the session; AC-2-05 password reset flow
 * (SECURITY.md §2.5, §2.9).
 */
final class LogoutAndResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_invalidates_the_session_and_regenerates_csrf(): void
    {
        // Suite-wide driver is `array` (phpunit.xml); this contract is about
        // the SERVER-SIDE session row (FR-07 / SECURITY.md §2.5), so switch
        // to the real database driver before any request.
        config(['session.driver' => 'database']);

        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        // Real login flow so a genuine session row exists in the DB
        $loginResponse = $this->post('/', [
            'email' => 'admin@example.test',
            'password' => 'super-secret-passphrase',
        ]);
        $this->assertAuthenticatedAs($user);

        // The Laravel test client does NOT keep a cookie jar between calls,
        // so forward the session cookie explicitly (as a browser would).
        $sessionCookie = collect($loginResponse->headers->getCookies())
            ->first(fn ($c) => str_contains($c->getName(), 'session'));
        $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue());

        $sessionId = (string) DB::table('sessions')->where('user_id', $user->getKey())->value('id');
        $this->assertNotSame('', $sessionId, 'login must persist a session row');
        $this->assertDatabaseHas('sessions', ['id' => $sessionId]);

        $response = $this->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();

        // Session row deleted (FR-07: deletes the session row)
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
    }

    public function test_reset_request_response_is_identical_for_known_and_unknown_emails(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'admin@example.test']);

        $known = $this->post('/password-reset', ['email' => 'admin@example.test']);
        $unknown = $this->post('/password-reset', ['email' => 'nobody@example.test']);

        $known->assertSessionHas('status');
        $unknown->assertSessionHas('status');
        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
            'reset response must not reveal whether the email exists'
        );

        Mail::assertSentCount(1); // only the known address got a mail
    }

    public function test_full_password_reset_flow_works_for_an_existing_admin(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('old-password-123456'),
        ]);

        // 1. Request a reset link
        $this->post('/password-reset', ['email' => 'admin@example.test']);
        Mail::assertSentCount(1);

        // 2. The stored token is hashed at rest (§2.9), so the test mints a
        //    known token through the same issuance contract.
        $rawToken = $this->mintTokenFor('admin@example.test');

        // 3. Use the token to reset
        $response = $this->post('/password-reset/update', [
            'token' => $rawToken,
            'email' => 'admin@example.test',
            'password' => 'brand-new-password-999',
            'password_confirmation' => 'brand-new-password-999',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $this->assertTrue(Hash::check('brand-new-password-999', $user->fresh()->password));

        // 4. Token is single-use: replay must fail
        $replay = $this->from('/')->post('/password-reset/update', [
            'token' => $rawToken,
            'email' => 'admin@example.test',
            'password' => 'another-password-0000',
            'password_confirmation' => 'another-password-0000',
        ]);
        $replay->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('another-password-0000', $user->fresh()->password));
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);

        $token = 'expired-token-value';
        DB::table('password_reset_tokens')->insert([
            'email' => 'admin@example.test',
            'token' => Hash::make($token),
            'created_at' => now()->subMinutes(31), // beyond the 30-minute TTL
        ]);

        $response = $this->post('/password-reset/update', [
            'token' => $token,
            'email' => 'admin@example.test',
            'password' => 'brand-new-password-999',
            'password_confirmation' => 'brand-new-password-999',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('brand-new-password-999', $user->fresh()->password));
    }

    public function test_password_reset_invalidates_other_sessions(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);

        $token = $this->mintTokenFor('admin@example.test');

        $this->from('/')->post('/password-reset/update', [
            'token' => $token,
            'email' => 'admin@example.test',
            'password' => 'brand-new-password-999',
            'password_confirmation' => 'brand-new-password-999',
        ])->assertRedirect(route('login'));

        // SECURITY.md §2.5: all other sessions for the user are invalidated.
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    /**
     * Mint a valid raw token for the given email and store its hash.
     * (Test helper: mirrors the controller's token issuance.)
     */
    private function mintTokenFor(string $email): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        return $token;
    }
}
