<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Throttling / abuse regression suite (SECURITY.md §2.4, §3.5, §7).
 */
final class ThrottlingTest extends SecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_login_throttle_key_is_partitioned_by_email_and_ip(): void
    {
        $user = User::factory()->create(['password' => Hash::make('super-secret-passphrase')]);

        // Five failures for this (email, IP) key.
        foreach (range(1, 5) as $i) {
            $this->from('/')->post('/', ['email' => $user->email, 'password' => "wrong-{$i}"]);
        }

        // The 6th must be throttled.
        $this->from('/')->post('/', ['email' => $user->email, 'password' => 'wrong-6'])
            ->assertStatus(429);

        // A different email is NOT throttled by the first key (partitioned).
        $other = User::factory()->create(['password' => Hash::make('super-secret-passphrase')]);
        $this->from('/')->post('/', ['email' => $other->email, 'password' => 'wrong-1'])
            ->assertSessionHasErrors('email');
    }

    private function protectedPage(): StatusPage
    {
        $page = StatusPage::query()->firstOrCreate(
            ['slug' => StatusPage::DEFAULT_SLUG],
            ['name' => 'Default', 'is_default' => true, 'visibility_mode' => StatusPage::MODE_PRIVATE]
        );
        $page->visibility_mode = StatusPage::MODE_PASSWORD_PROTECTED;
        $page->password_hash = Hash::make('correct-horse-battery');
        $page->save();

        return $page;
    }

    public function test_status_unlock_is_throttled_and_returns_retry_after(): void
    {
        $page = $this->protectedPage();

        $locked = $this->get(route('status.show', ['statusPage' => $page->slug]));
        $this->forwardCookie($locked);

        for ($i = 0; $i < 5; $i++) {
            $this->forwardCookie($this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'wrong']));
        }

        $blocked = $this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'wrong']);
        $blocked->assertStatus(429);
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
    }

    public function test_successful_unlock_clears_the_throttle_counter(): void
    {
        $page = $this->protectedPage();

        $locked = $this->get(route('status.show', ['statusPage' => $page->slug]));
        $this->forwardCookie($locked);

        $this->forwardCookie($this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'wrong']));

        // The rate-limit key must be cleared after a correct unlock.
        $key = 'status-unlock:127.0.0.1:'.session()->getId();
        $this->forwardCookie($this->post(route('status.unlock', ['statusPage' => $page->slug]), ['password' => 'correct-horse-battery']));
        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_forwarded_for_is_not_trusted_by_default(): void
    {
        // With no TRUSTED_PROXIES configured, a spoofed X-Forwarded-For must not
        // change the request IP (and therefore cannot evade IP-keyed limits).
        config(['trustedproxy.proxies' => null]);

        $user = User::factory()->create(['password' => Hash::make('super-secret-passphrase')]);

        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->withHeader('X-Forwarded-For', '1.1.1.'.(100 + $i))
                ->post('/', ['email' => $user->email, 'password' => "wrong-{$i}"]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Forwarded-For', '1.1.1.250')
            ->post('/', ['email' => $user->email, 'password' => 'wrong-6'])
            ->assertStatus(429);
    }

    private function forwardCookie($response): void
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c): bool => str_contains($c->getName(), 'session'));

        if ($cookie !== null) {
            $this->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
        }
    }
}
