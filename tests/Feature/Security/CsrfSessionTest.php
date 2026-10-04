<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/**
 * CSRF + session regression suite (SECURITY.md §2.5–§2.6, NFR-14).
 *
 * The test suite runs with APP_ENV=testing, where Laravel itself skips token
 * validation; these tests therefore assert the *configuration* invariants that
 * guarantee protection in production, plus the observable session controls.
 */
final class CsrfSessionTest extends SecurityTestCase
{
    public function test_no_blanket_csrf_exemption_is_registered(): void
    {
        // A wildcard exception (e.g. '/*') would disable CSRF in production.
        $middleware = new PreventRequestForgery(app(), app('encrypter'));
        $excluded = $middleware->getExcludedPaths();

        $this->assertIsArray($excluded);

        foreach ($excluded as $path) {
            $this->assertNotSame('*', $path, 'A wildcard CSRF exemption is a production bypass.');
            $this->assertNotSame('/*', $path, 'A wildcard CSRF exemption is a production bypass.');
        }
    }

    public function test_all_state_changing_admin_routes_are_post_or_higher(): void
    {
        $stateChanging = [
            'login.attempt', 'logout', 'password.email', 'password.update',
            'admin.websites.store', 'admin.websites.update', 'admin.websites.destroy',
            'admin.websites.toggle', 'admin.websites.check',
            'admin.websites.bulk.enable', 'admin.websites.bulk.disable', 'admin.websites.bulk.delete',
            'admin.incidents.acknowledge', 'admin.incidents.resolve',
            'admin.notifications.store', 'admin.notifications.update', 'admin.notifications.destroy',
            'admin.notifications.test-send', 'admin.notifications.test', 'admin.status-settings.update',
            'admin.push.subscribe', 'admin.push.unsubscribe', 'admin.push.test',
            'status.unlock', 'status.logout',
        ];

        foreach ($stateChanging as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} must exist");
            $this->assertContains(
                $route->methods()[0],
                ['POST', 'PUT', 'PATCH', 'DELETE'],
                "State-changing route {$name} must not be reachable via GET",
            );
        }
    }

    public function test_no_get_route_mutates_state(): void
    {
        // Every route that performs a write must not be a safe (read) verb.
        $getRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true));

        foreach ($getRoutes as $route) {
            $this->assertNotContains('DELETE', $route->methods());
            $this->assertNotContains('PUT', $route->methods());
        }
    }

    public function test_session_cookie_defaults_are_hardened(): void
    {
        $this->assertTrue((bool) config('session.http_only'), 'session cookie must be HttpOnly');
        $this->assertContains(
            (string) config('session.same_site'),
            ['lax', 'strict'],
            'session cookie SameSite must be Lax or Strict, never None',
        );
    }

    public function test_login_regenerates_session_id(): void
    {
        $user = User::factory()->create(['password' => bcrypt('super-secret-passphrase')]);

        $this->get('/');
        $before = session()->getId();

        $this->post('/', ['email' => $user->email, 'password' => 'super-secret-passphrase']);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId(), 'session id must regenerate on login');
    }
}
