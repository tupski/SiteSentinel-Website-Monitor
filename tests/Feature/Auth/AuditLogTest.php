<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AC-2-06: auth events land in audit_logs with actor + timestamp
 * (PLAN.md Phase 2, DATABASE.md §3.19).
 */
final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_audited_with_actor_and_timestamp(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        $this->post('/', [
            'email' => 'admin@example.test',
            'password' => 'super-secret-passphrase',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->getKey(),
            'event' => AuditEvent::AUTH_LOGIN_SUCCESS,
        ]);

        $entry = AuditLog::query()->where('event', AuditEvent::AUTH_LOGIN_SUCCESS)->first();
        $this->assertNotNull($entry->created_at, 'audit entry must carry a timestamp');
        $this->assertSame($user::class, $entry->subject_type);
    }

    public function test_failed_login_is_audited(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        $this->post('/', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => AuditEvent::AUTH_LOGIN_FAILURE]);
    }

    public function test_logout_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->getKey(),
            'event' => AuditEvent::AUTH_LOGOUT,
        ]);
    }

    public function test_password_reset_request_and_completion_are_audited(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'admin@example.test']);

        $this->post('/password-reset', ['email' => 'admin@example.test']);
        $this->assertDatabaseHas('audit_logs', ['event' => AuditEvent::AUTH_PASSWORD_RESET_REQUESTED]);

        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => 'admin@example.test'],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $this->post('/password-reset/update', [
            'token' => $token,
            'email' => 'admin@example.test',
            'password' => 'brand-new-password-999',
            'password_confirmation' => 'brand-new-password-999',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->getKey(),
            'event' => AuditEvent::AUTH_PASSWORD_RESET_COMPLETED,
        ]);
    }

    public function test_audit_failure_never_breaks_the_action(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        // Break the audit table to simulate an audit-write failure
        Schema::drop('audit_logs');

        $response = $this->post('/', [
            'email' => 'admin@example.test',
            'password' => 'super-secret-passphrase',
        ]);

        // Login still succeeds (best-effort auditing principle)
        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
