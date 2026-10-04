<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 5 admin profile tests.
 *
 * Covers access control (mirrors AdminAccessControlTest), identity editing,
 * the secure password-change flow, and the two hard invariants: no password
 * value ever appears in a response, and no privilege escalation is possible
 * through these endpoints.
 */
final class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $password = 'super-secret-passphrase'): User
    {
        return User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make($password),
        ]);
    }

    // --- Authorization -----------------------------------------------------

    public function test_guest_is_redirected_to_login_for_every_profile_route(): void
    {
        $this->get(route('admin.profile.edit'))->assertRedirect(route('login'));

        $this->put(route('admin.profile.update'), ['name' => 'X', 'email' => 'x@example.test'])
            ->assertRedirect(route('login'));

        $this->put(route('admin.profile.password'), [
            'current_password' => 'a',
            'password' => 'b',
            'password_confirmation' => 'b',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'viewer'])->save();

        $this->actingAs($user)->get(route('admin.profile.edit'))->assertForbidden();

        $this->actingAs($user)
            ->put(route('admin.profile.update'), ['name' => 'Nope', 'email' => 'nope@example.test'])
            ->assertForbidden();
    }

    // --- Edit page ---------------------------------------------------------

    public function test_profile_page_renders_current_identity_for_admin(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.profile.edit'));

        $response->assertOk();
        $response->assertSee($admin->name);
        $response->assertSee($admin->email);
    }

    // --- Profile update ----------------------------------------------------

    public function test_profile_update_saves_a_valid_name(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('admin.profile.update'), [
                'name' => 'Renamed Operator',
                'email' => $admin->email,
            ]);

        $response->assertRedirect(route('admin.profile.edit'));
        $response->assertSessionHas('status');
        $this->assertSame('Renamed Operator', $admin->fresh()->name);
    }

    public function test_profile_update_rejects_invalid_input(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['email' => 'taken@example.test']);

        $response = $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('admin.profile.update'), [
                'name' => '',
                'email' => $other->email,
            ]);

        $response->assertSessionHasErrors(['name', 'email']);
        $this->assertSame($admin->name, $admin->fresh()->name);
    }

    public function test_profile_update_cannot_escalate_role_or_active_state(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('admin.profile.update'), [
                'name' => 'Sneaky',
                'email' => $admin->email,
                'role' => 'superuser',
                'is_admin' => true,
                'is_active' => false,
                'password' => Hash::make('injected-password'),
            ]);

        $response->assertRedirect(route('admin.profile.edit'));

        $fresh = $admin->fresh();
        $this->assertSame('admin', $fresh->role);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertTrue(Hash::check('super-secret-passphrase', (string) $fresh->password));
    }

    // --- Password change ---------------------------------------------------

    public function test_password_change_succeeds_with_correct_current_password(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('admin.profile.password'), [
                'current_password' => 'super-secret-passphrase',
                'password' => 'brand-new-passphrase-123',
                'password_confirmation' => 'brand-new-passphrase-123',
            ]);

        $response->assertRedirect(route('admin.profile.edit'));
        $response->assertSessionHas('status');
        $this->assertTrue(Hash::check('brand-new-passphrase-123', (string) $admin->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected_and_password_unchanged(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('admin.profile.password'), [
                'current_password' => 'not-the-right-one',
                'password' => 'brand-new-passphrase-123',
                'password_confirmation' => 'brand-new-passphrase-123',
            ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('super-secret-passphrase', (string) $admin->fresh()->password));
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('admin.profile.password'), [
                'current_password' => 'super-secret-passphrase',
                'password' => 'brand-new-passphrase-123',
                'password_confirmation' => 'different-confirmation-123',
            ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('super-secret-passphrase', (string) $admin->fresh()->password));
    }

    public function test_short_new_password_is_rejected(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('admin.profile.password'), [
                'current_password' => 'super-secret-passphrase',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('super-secret-passphrase', (string) $admin->fresh()->password));
    }

    public function test_new_password_equal_to_current_is_rejected(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('admin.profile.password'), [
                'current_password' => 'super-secret-passphrase',
                'password' => 'super-secret-passphrase',
                'password_confirmation' => 'super-secret-passphrase',
            ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('super-secret-passphrase', (string) $admin->fresh()->password));
    }

    public function test_password_values_never_appear_in_the_response_body(): void
    {
        $admin = $this->admin('super-secret-passphrase');

        $get = $this->actingAs($admin)->get(route('admin.profile.edit'));
        $get->assertOk();
        $get->assertDontSee('super-secret-passphrase', false);
        $this->assertStringNotContainsString($admin->fresh()->password, $get->getContent());

        $post = $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('admin.profile.password'), [
                'current_password' => 'super-secret-passphrase',
                'password' => 'brand-new-passphrase-123',
                'password_confirmation' => 'brand-new-passphrase-123',
            ]);

        $post->assertSessionHasNoErrors();

        // Follow the redirect and assert the fresh secret is nowhere in the HTML.
        $followed = $this->actingAs($admin)->get(route('admin.profile.edit'));
        $followed->assertDontSee('brand-new-passphrase-123', false);
        $followed->assertDontSee((string) $admin->fresh()->password, false);
    }

    public function test_session_remains_valid_after_password_change(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.profile.password'), [
                'current_password' => 'super-secret-passphrase',
                'password' => 'brand-new-passphrase-123',
                'password_confirmation' => 'brand-new-passphrase-123',
            ]);

        $this->assertAuthenticatedAs($admin->fresh());
    }
}
