<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Audit\AuditEvent;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Audit event-name catalogue drift guard (SECURITY.md §9.1, PLAN.md Phase 10).
 *
 * The canonical audit event strings are frozen in SECURITY.md §9.1. This test
 * pins the code to those exact dotted names so a silent rename (the historic
 * `auth.login` vs `auth.login.success` drift) cannot recur. It also asserts the
 * documented catalogue is the source of truth by reading SECURITY.md §9.1 and
 * requiring every canonical constant to appear verbatim in it.
 */
final class AuditEventCatalogueTest extends SecurityTestCase
{
    /**
     * Every constant in `AuditEvent` must be documented verbatim in SECURITY.md
     * §9.1 (docs are the source of truth for names — AGENTS.md §1).
     */
    public function test_every_canonical_event_is_documented_in_security_md(): void
    {
        $doc = (string) file_get_contents(base_path('SECURITY.md'));

        // Bound the search to §9.1 to avoid matching unrelated prose.
        $start = mb_strpos($doc, '### 9.1 What is logged');
        $end = mb_strpos($doc, '### 9.2');

        $this->assertNotFalse($start, 'SECURITY.md §9.1 heading must exist.');
        $this->assertNotFalse($end, 'SECURITY.md §9.2 heading must exist.');

        $section = mb_substr($doc, (int) $start, (int) $end - (int) $start);

        foreach (AuditEvent::all() as $event) {
            $this->assertStringContainsString(
                '`'.$event.'`',
                $section,
                "Audit event `{$event}` must be documented in SECURITY.md §9.1 (docs are the source of truth).",
            );
        }
    }

    public function test_successful_login_writes_canonical_dotted_event(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);

        $this->post('/', [
            'email' => 'admin@example.test',
            'password' => 'super-secret-passphrase',
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => AuditEvent::AUTH_LOGIN_SUCCESS]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'auth.login']);
        $this->assertNotNull($user->getKey());
    }

    public function test_failed_login_writes_canonical_dotted_event(): void
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
        $this->assertDatabaseMissing('audit_logs', ['event' => 'auth.login_failed']);
    }

    public function test_logout_writes_canonical_dotted_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertDatabaseHas('audit_logs', ['event' => AuditEvent::AUTH_LOGOUT]);
    }

    public function test_password_reset_events_use_canonical_dotted_names(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'admin@example.test']);

        $this->post('/password-reset', ['email' => 'admin@example.test']);

        $this->assertDatabaseHas('audit_logs', ['event' => AuditEvent::AUTH_PASSWORD_RESET_REQUESTED]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'auth.password_reset_requested']);
    }

    public function test_channel_test_send_writes_canonical_channel_tested_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $channel = NotificationChannel::create([
            'type' => 'email',
            'name' => 'Ops mail',
            'enabled' => true,
            'config' => [
                'recipients' => ['ops@example.test'],
                'host' => 'smtp.example.test',
                'port' => 587,
                'encryption' => 'tls',
                'from_address' => 'alerts@example.test',
                'from_name' => 'SiteSentinel',
                'min_severity' => 'WARNING',
            ],
            'secret_ref' => 'smtp-secret-value',
        ]);

        // The provider validation runs against the channel config; the test-send
        // path may fail at delivery, but the audit event must still be emitted
        // with the canonical name.
        Mail::fake();

        $this->actingAs($admin)
            ->post(route('admin.notifications.test-send', $channel))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['event' => AuditEvent::CHANNEL_TESTED]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'notification.channel_tested']);
    }

    public function test_channel_secret_update_writes_canonical_secret_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $channel = NotificationChannel::create([
            'type' => 'telegram',
            'name' => 'Ops tg',
            'enabled' => true,
            'config' => ['chat_id' => '123456'],
            'secret_ref' => 'old-bot-token',
        ]);

        $this->actingAs($admin)->put(route('admin.notifications.update', $channel), [
            'type' => 'telegram',
            'name' => 'Ops tg',
            'enabled' => '1',
            'telegram_chat_id' => '123456',
            'min_severity' => 'WARNING',
            'secret_ref' => 'brand-new-bot-token',
        ])->assertRedirect(route('admin.notifications.index'));

        $this->assertDatabaseHas('audit_logs', ['event' => AuditEvent::CHANNEL_SECRET_UPDATED]);
    }
}
