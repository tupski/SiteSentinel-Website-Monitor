<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC-2-02: unauthenticated access to any /admin route is rejected for EVERY
 * HTTP method (PRD.md AC-02, SECURITY.md §3.2). AC-2-03: no registration route.
 */
final class AdminAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_to_admin_are_rejected_for_every_method(): void
    {
        // Every method an admin route could ever accept. GET /admin exists;
        // other methods get 405 (no route) — which is still a rejection.
        // The EnsureAdmin middleware runs on the group for all methods.
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $response = $this->{$method}('/admin');

            $this->assertContains(
                $response->getStatusCode(),
                [302, 401, 405],
                "{$method} /admin must not be reachable unauthenticated"
            );
            if ($response->getStatusCode() === 302) {
                $this->assertStringContainsString(route('login'), $response->headers->get('Location'));
            }
        }

        $this->assertGuest();
    }

    public function test_authenticated_non_admin_is_forbidden(): void
    {
        // A future non-admin role: craft a user whose role is not 'admin'.
        $user = User::factory()->create();
        $user->forceFill(['role' => 'viewer'])->save();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertForbidden();
    }

    public function test_disabled_admin_session_is_invalidated_and_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $user->forceFill(['is_active' => false])->save(); // disabled after login

        $response = $this->get('/admin');

        $response->assertForbidden();
    }

    public function test_admin_can_access_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_no_public_registration_route_exists(): void
    {
        // Common registration endpoints must not resolve
        foreach (['/register', '/signup', '/admin/register'] as $path) {
            $response = $this->get($path);
            $this->assertNotSame(200, $response->getStatusCode(), "registration route must not exist: {$path}");
        }

        // And a POST attempt must not create a user
        $before = User::query()->count();
        $this->post('/register', [
            'name' => 'Attacker',
            'email' => 'attacker@example.test',
            'password' => 'attacker-password-123',
        ]);
        $this->assertSame($before, User::query()->count(), 'no self-registration may create users');
    }
}
