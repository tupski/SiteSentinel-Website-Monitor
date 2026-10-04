<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\NotificationChannel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\MessageRedactor;
use Illuminate\Support\Facades\DB;

/**
 * Encryption + secret-redaction regression suite (SECURITY.md §4).
 *
 * Proves channel secrets are encrypted at rest, never serialized to the
 * browser, and that provider error strings are scrubbed of credential shapes.
 */
final class SecretsRedactionTest extends SecurityTestCase
{
    public function test_channel_secret_is_encrypted_at_rest(): void
    {
        $channel = NotificationChannel::create([
            'type' => 'telegram',
            'name' => 'Ops',
            'enabled' => true,
            'config' => ['chat_id' => '12345'],
            'secret_ref' => '123456:AAHsuper-secret-bot-token-value-xyz',
        ]);

        // Raw column value must not equal the plaintext.
        $raw = (string) DB::table('notification_channels')->where('id', $channel->id)->value('secret_ref');

        $this->assertNotSame('123456:AAHsuper-secret-bot-token-value-xyz', $raw);
        $this->assertStringNotContainsString('super-secret', $raw);

        // The model still decrypts it for use.
        $this->assertSame(
            '123456:AAHsuper-secret-bot-token-value-xyz',
            (string) $channel->fresh()->secret_ref,
        );
    }

    public function test_channel_secret_is_never_serialized_to_array_or_json(): void
    {
        $channel = NotificationChannel::create([
            'type' => 'email',
            'name' => 'SMTP',
            'enabled' => true,
            'config' => ['host' => 'smtp.example.test', 'recipients' => ['a@example.test']],
            'secret_ref' => 'smtp-super-secret-password',
        ]);

        // A naive json_encode($channel) in a controller would leak it; the
        // edit view renders a masked field only. Assert the attribute is not
        // present in a default toArray()/toJson() projection.
        $array = $channel->toArray();
        $this->assertArrayNotHasKey('secret_ref', $array, 'secret_ref must not serialize by default');
        $this->assertStringNotContainsString('smtp-super-secret-password', json_encode($array));
    }

    public function test_redactor_scrubs_json_and_authorization_shapes(): void
    {
        $samples = [
            '{"password":"hunter2secret"}' => 'hunter2secret',
            '"token":"abc123secret"' => 'abc123secret',
            'Authorization: Bearer abc123secrettoken' => 'abc123secrettoken',
            'smtp password=supersecret' => 'supersecret',
            'bot_token: 123456:AAHxyzABCdefGHIjklMNOpqrSTUvwxYZA12' => 'AAHxyzABCdefGHIjklMNOpqrSTUvwxYZA12',
        ];

        foreach ($samples as $input => $secret) {
            $redacted = (string) MessageRedactor::redact($input);
            $this->assertStringNotContainsString($secret, $redacted, "Redactor leaked: {$input}");
        }
    }

    public function test_notification_log_never_stores_raw_provider_secret(): void
    {
        // A provider exception embedding a Telegram token must be redacted
        // before it is persisted to notification_logs.error.
        $message = 'Guzzle error: POST https://api.telegram.org/bot123456:AAHxyzABCdefGHIjklMNOpqrSTUvwxYZA12/sendMessage failed';
        $redacted = (string) MessageRedactor::redact($message);

        $this->assertStringNotContainsString('AAHxyzABCdefGHIjklMNOpqrSTUvwxYZA12', $redacted);
    }

    public function test_redactor_scrubs_browser_push_material_and_vapid_private_key(): void
    {
        // VAPID private key and push subscription material must never survive
        // redaction (SECURITY.md §4, ADR-032).
        $samples = [
            'vapid_private_key=SUPER-SECRET-PRIVATE-KEY' => 'SUPER-SECRET-PRIVATE-KEY',
            'p256dh=BCdummyP256dhMaterialValue' => 'BCdummyP256dhMaterialValue',
            '"private_key":"LEAKED-PRIVATE-KEY"' => 'LEAKED-PRIVATE-KEY',
        ];

        foreach ($samples as $input => $secret) {
            $redacted = (string) MessageRedactor::redact($input);
            $this->assertStringNotContainsString($secret, $redacted, "Redactor leaked push material: {$input}");
        }
    }

    public function test_push_subscription_secrets_encrypted_and_hidden(): void
    {
        $user = User::factory()->create();
        $endpoint = 'https://push.example.test/vapid-secret-endpoint';

        $subscription = PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'p256dh' => 'p256dh-client-key-material-secret',
            'auth' => 'auth-client-secret-material-secret',
            'enabled' => true,
        ]);

        foreach (['endpoint', 'p256dh', 'auth'] as $column) {
            $raw = (string) DB::table('push_subscriptions')->where('id', $subscription->id)->value($column);
            $this->assertStringNotContainsString('secret', $raw, "push_subscriptions.{$column} must be encrypted at rest");
        }

        $array = $subscription->toArray();
        $this->assertArrayNotHasKey('endpoint', $array);
        $this->assertArrayNotHasKey('p256dh', $array);
        $this->assertArrayNotHasKey('auth', $array);
        $this->assertStringNotContainsString('secret', json_encode($array));
    }
}
