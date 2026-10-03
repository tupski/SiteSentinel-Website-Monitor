<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Session idle/absolute timeout regression suite (SECURITY.md §2.5).
 *
 * Uses a monotonic test clock anchored at a fixed instant so activity
 * refreshes and absolute expiry can be asserted deterministically.
 */
final class SessionTimeoutTest extends SecurityTestCase
{
    private Carbon $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = Carbon::parse('2026-10-03 12:00:00', 'UTC');
        Carbon::setTestNow($this->base);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(int $minutes): void
    {
        Carbon::setTestNow($this->base->copy()->addMinutes($minutes));
    }

    private function login(): User
    {
        config([
            'sentinel.auth.idle_timeout_minutes' => 30,
            'sentinel.auth.absolute_timeout_minutes' => 480,
        ]);

        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        $this->post('/', [
            'email' => 'admin@example.test',
            'password' => 'super-secret-passphrase',
        ]);

        // A request into /admin seeds the timeout anchors.
        $this->get(route('admin.dashboard'))->assertOk();

        return $user;
    }

    public function test_idle_timeout_expires_the_session(): void
    {
        $this->login();

        // Activity at +29 refreshes the idle anchor.
        $this->at(29);
        $this->get(route('admin.dashboard'))->assertOk();

        // +31 idle minutes since the last activity => expired.
        $this->at(60);
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_absolute_timeout_expires_even_with_activity(): void
    {
        $this->login();

        // Stay active with a request every 20 minutes (under the 30-min idle
        // window) until just before the 8-hour absolute limit.
        foreach (range(20, 460, 20) as $minutes) {
            $this->at($minutes);
            $this->get(route('admin.dashboard'))->assertOk();
        }

        // Past 480 minutes from login, activity cannot save the session.
        $this->at(490);
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_activity_refreshes_the_idle_window(): void
    {
        $this->login();

        foreach ([20, 40, 60] as $minutes) {
            $this->at($minutes);
            $this->get(route('admin.dashboard'))->assertOk();
        }

        $this->assertAuthenticated();
    }
}
