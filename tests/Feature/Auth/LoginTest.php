<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Phase 2 login tests (AC-2-01) + disabled-account rejection (SECURITY §3.1).
 */
final class LoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin@example.test', 'nobody@example.test'] as $email) {
            RateLimiter::clear("login:{$email}|127.0.0.1");
            RateLimiter::clear("login:{$email}|127.0.0.1:soft");
            RateLimiter::clear("login:{$email}|127.0.0.1:lockout");
            RateLimiter::clear("login:{$email}|127.0.0.1:locked");
        }
    }

    use RefreshDatabase;

    public function test_admin_can_log_in_at_root(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        $response = $this->from('/')->post('/', [
            'email' => 'admin@example.test',
            'password' => 'super-secret-passphrase',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);

        // last_login_at recorded (DATABASE.md §3.1 audit column)
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_fails_with_generic_message(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        $response = $this->from('/')->post('/', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password-here',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        // Enumeration resistance: unknown email gets the same error key
        $response2 = $this->from('/')->post('/', [
            'email' => 'nobody@example.test',
            'password' => 'wrong-password-here',
        ]);
        $response2->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_disabled_account_cannot_log_in(): void
    {
        User::factory()->inactive()->create([
            'email' => 'disabled@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        $this->from('/')->post('/', [
            'email' => 'disabled@example.test',
            'password' => 'super-secret-passphrase',
        ]);

        $this->assertGuest('web');
    }

    public function test_repeated_failures_are_throttled_with_429(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        // SECURITY.md §2.4: soft limit = 5 failures / 15 min per (email + IP).
        // The 6th failed attempt within the window must get HTTP 429.
        foreach (range(1, 5) as $i) {
            $this->from('/')->post('/', [
                'email' => 'admin@example.test',
                'password' => 'wrong-password-'.$i,
            ]);
        }
        $this->assertGuest();

        $response = $this->from('/')->post('/', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password-6',
        ]);

        $response->assertStatus(429);
    }

    public function test_successful_login_clears_the_failure_counter(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        // Burn 4 failures (below the soft limit of 5)
        foreach (range(1, 4) as $i) {
            $this->post('/', [
                'email' => 'admin@example.test',
                'password' => 'wrong-password-'.$i,
            ]);
        }

        // Successful login resets counters (SECURITY.md §2.4 "Reset")
        $this->post('/', [
            'email' => 'admin@example.test',
            'password' => 'super-secret-passphrase',
        ]);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->assertGuest();

        // 4 more failures must NOT hit the throttle (counter was cleared):
        foreach (range(1, 4) as $i) {
            $response = $this->post('/', [
                'email' => 'admin@example.test',
                'password' => 'again-wrong-'.$i,
            ]);
            $response->assertSessionHasErrors('email'); // 302 with errors, NOT 429
        }
    }

    public function test_session_id_is_regenerated_on_login(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        $this->get('/'); // establish a pre-login session (fixation defence, §2.5)
        $sessionIdBefore = Session::getId();

        $this->post('/', [
            'email' => 'admin@example.test',
            'password' => 'super-secret-passphrase',
        ]);

        $this->assertNotSame($sessionIdBefore, Session::getId(), 'session ID must be regenerated on login');
    }
}
