<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Browser Push subscription lifecycle endpoints (NOTIFICATIONS.md §7.3,
 * ADR-032, SECURITY.md §3.2/§4).
 *
 * Auth + admin guard on every method, CSRF enforcement, validation, and the
 * guarantee that subscription secrets are never echoed back.
 */
final class PushSubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    private function subscriptionData(string $endpoint = 'https://push.example.test/abc'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'p256dh-client-key-material',
                'auth' => 'auth-client-secret-material',
            ],
        ];
    }

    public function test_subscribe_requires_authentication(): void
    {
        $this->postJson(route('admin.push.subscribe'), $this->subscriptionData())
            ->assertUnauthorized();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_subscribe_upserts_and_stores_secrets_encrypted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('admin.push.subscribe'), $this->subscriptionData())
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseCount('push_subscriptions', 1);

        $subscription = PushSubscription::query()->firstOrFail();
        $this->assertSame($admin->id, $subscription->user_id);
        $this->assertTrue($subscription->enabled);

        // Raw endpoint column must be encrypted, not plaintext.
        $raw = (string) DB::table('push_subscriptions')->where('id', $subscription->id)->value('endpoint');
        $this->assertStringNotContainsString('push.example.test/abc', $raw);

        // Re-subscribing the same endpoint upserts (no duplicate row).
        $this->actingAs($admin)
            ->postJson(route('admin.push.subscribe'), $this->subscriptionData())
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_subscribe_response_never_echoes_subscription_secrets(): void
    {
        config()->set('sentinel.push.vapid_private_key', 'PRIVATE-KEY-MUST-NEVER-LEAK');

        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.push.subscribe'), $this->subscriptionData());

        $response->assertOk();
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('p256dh-client-key-material', $body);
        $this->assertStringNotContainsString('auth-client-secret-material', $body);
        $this->assertStringNotContainsString('PRIVATE-KEY-MUST-NEVER-LEAK', $body);
    }

    public function test_push_routes_are_state_changing_and_in_the_web_group(): void
    {
        // Testing skips token validation, so assert the *configuration*: each
        // push route carries the web-group middleware (CSRF-applicable) and is
        // reachable only via a state-changing verb.
        foreach (['admin.push.subscribe' => 'POST', 'admin.push.unsubscribe' => 'DELETE', 'admin.push.test' => 'POST'] as $name => $verb) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} must exist.");
            $this->assertContains($verb, $route->methods());
            $this->assertContains('web', $route->gatherMiddleware(), "Route {$name} must be in the web (CSRF) group.");
        }
    }

    public function test_subscribe_validates_required_keys(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('admin.push.subscribe'), ['endpoint' => 'https://push.example.test/x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keys']);
    }

    public function test_unsubscribe_removes_subscription(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('admin.push.subscribe'), $this->subscriptionData())
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);

        $this->actingAs($admin)
            ->deleteJson(route('admin.push.unsubscribe'), ['endpoint' => 'https://push.example.test/abc'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_unsubscribe_requires_authentication(): void
    {
        $this->deleteJson(route('admin.push.unsubscribe'), ['endpoint' => 'https://push.example.test/abc'])
            ->assertUnauthorized();
    }

    public function test_test_push_requires_authentication(): void
    {
        $this->post(route('admin.push.test'))->assertRedirect(route('login'));
    }
}
