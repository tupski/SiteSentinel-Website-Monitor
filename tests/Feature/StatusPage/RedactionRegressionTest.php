<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Snapshot;
use App\Models\StatusPageSetting;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Release blocker: the §14.4 redaction boundary (STATUS-PAGE.md §12.3).
 *
 * A realistic fixture populates EVERY sensitive field with a unique canary and
 * asserts the raw public HTTP body (HTML source) and the JSON body never
 * contain any of them, across every visibility mode and unlock state. A
 * failure here means a leak path exists.
 */
final class RedactionRegressionTest extends StatusPageTestCase
{
    /**
     * @return array<string, string>
     */
    private function canaries(): array
    {
        return [
            'keyword_casino' => 'casino',
            'keyword_maxwin' => 'maxwin',
            'evil_domain' => 'evil-top-xyz.example',
            'redirect_target' => '/redirect-target-abc',
            'rule_id' => 'RULE-XYZ-999',
            'weight' => 'weight-99',
            'score' => 'score-999',
            'content_hash' => 'content-hash-canary',
            'snapshot_path' => '/snap/canary.html',
            'header' => 'X-Canary-Token',
            'cookie' => 'canary-cookie',
            'resolved_ip' => '10.99.99.99',
            'hostname' => 'internal-canary.local',
            'stack' => 'canary-stack',
            'resolution_notes' => 'canary-notes',
            'notification_error' => 'canary-notify',
        ];
    }

    private function seedLeakyFixture(): Website
    {
        $website = $this->makeWebsite([
            'name' => 'Public Alias Name',
            'status_alias' => 'Published Service',
            'status_availability' => 'DOWN',
            'status_security' => 'INCIDENT',
            'last_checked_at' => now('UTC'),
        ]);

        $check = Check::create([
            'website_id' => $website->id,
            'check_key' => 'canary-check-key',
            'started_at' => now('UTC'),
            'finished_at' => now('UTC'),
            'duration_ms' => 4321,
            'http_status' => 200,
            'final_url' => 'https://evil-top-xyz.example/redirect-target-abc',
            'resolved_ip' => '10.99.99.99',
            'redirect_chain' => [
                'https://internal-canary.local/',
                'https://evil-top-xyz.example/redirect-target-abc',
            ],
            'content_hash' => 'content-hash-canary',
            'availability_state' => 'DOWN',
            'security_state' => 'INCIDENT',
            'score' => 999,
            'triggered_rules' => [
                'RULE-XYZ-999' => ['category' => 'keyword', 'weight' => 99, 'confidence' => 'high', 'reason' => 'canary-stack'],
            ],
        ]);

        CheckExtraction::create([
            'check_id' => $check->id,
            'website_id' => $website->id,
            'keywords' => ['casino', 'maxwin'],
            'external_domains' => ['evil-top-xyz.example'],
            'suspicious_patterns' => ['canary-stack'],
        ]);

        Snapshot::create([
            'website_id' => $website->id,
            'check_id' => $check->id,
            'html_path' => '/snap/canary.html',
            'headers' => ['X-Canary-Token' => 'canary-cookie'],
            'final_url' => 'https://evil-top-xyz.example/redirect-target-abc',
            'title' => 'canary title',
            'keywords' => ['casino', 'maxwin'],
            'external_links' => ['evil-top-xyz.example'],
            'redirect_chain' => ['https://evil-top-xyz.example/redirect-target-abc'],
            'size_bytes' => 12345,
            'captured_at' => now('UTC'),
        ]);

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'security',
            'category' => 'security',
            'severity' => 'CRITICAL',
            'status' => 'DETECTED',
            'score' => 999,
            'triggered_rules' => [
                'RULE-XYZ-999' => ['weight' => 99, 'reason' => 'canary-stack'],
            ],
            'message' => 'canary-stack message with casino and maxwin',
            'technical_metadata' => [
                'resolved_ip' => '10.99.99.99',
                'hostname' => 'internal-canary.local',
                'content_hash' => 'content-hash-canary',
            ],
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now('UTC'),
            'resolution_notes' => 'canary-notes',
        ]);

        $channel = NotificationChannel::create([
            'type' => 'email',
            'name' => 'Ops',
            'enabled' => true,
            'config' => ['recipients' => ['ops@example.test']],
            'secret_ref' => 'canary-secret',
        ]);

        NotificationLog::create([
            'incident_id' => $incident->id,
            'channel_id' => $channel->id,
            'status' => 'failed',
            'attempt' => 1,
            'error' => 'canary-notify delivery failed',
        ]);

        return $website;
    }

    private function assertNoCanaries(string $body, string $context): void
    {
        foreach ($this->canaries() as $name => $canary) {
            $this->assertStringNotContainsString(
                $canary,
                $body,
                "Redaction leak ({$name}) in {$context}",
            );
        }
    }

    public function test_private_mode_admin_projection_never_leaks_canaries(): void
    {
        $this->seedLeakyFixture();
        $this->setMode(StatusPageSetting::MODE_PRIVATE);
        $this->actingAs($this->admin());

        $html = $this->get(route('status.show'));
        $html->assertOk();
        $this->assertNoCanaries((string) $html->getContent(), 'Private HTML');

        $json = $this->getJson(route('status.json'));
        $json->assertOk();
        $this->assertNoCanaries((string) $json->getContent(), 'Private JSON');
    }

    public function test_public_mode_anon_projection_never_leaks_canaries(): void
    {
        $this->seedLeakyFixture();
        $this->setMode(StatusPageSetting::MODE_PUBLIC);

        $html = $this->get(route('status.show'));
        $html->assertOk();
        $this->assertNoCanaries((string) $html->getContent(), 'Public HTML');

        $json = $this->getJson(route('status.json'));
        $json->assertOk();
        $this->assertNoCanaries((string) $json->getContent(), 'Public JSON');
    }

    public function test_password_locked_projection_never_leaks_canaries(): void
    {
        $this->seedLeakyFixture();
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $html = $this->get(route('status.show'));
        $html->assertOk();
        $this->assertNoCanaries((string) $html->getContent(), 'Password locked HTML');

        $json = $this->getJson(route('status.json'));
        $this->assertNoCanaries((string) $json->getContent(), 'Password locked JSON');
    }

    public function test_password_unlocked_projection_never_leaks_canaries(): void
    {
        $this->seedLeakyFixture();
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $locked = $this->get(route('status.show'));
        $this->forwardSessionCookie($locked);

        $unlock = $this->post(route('status.unlock'), ['password' => 'correct-horse-battery']);
        $this->forwardSessionCookie($unlock);

        $html = $this->get(route('status.show'));
        $html->assertOk();
        $this->assertNoCanaries((string) $html->getContent(), 'Password unlocked HTML');

        $json = $this->getJson(route('status.json'));
        $json->assertOk();
        $this->assertNoCanaries((string) $json->getContent(), 'Password unlocked JSON');
    }

    public function test_password_invalid_attempt_never_leaks_canaries(): void
    {
        $this->seedLeakyFixture();
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->postJson(route('status.unlock'), ['password' => 'wrong-password-here']);
        $this->assertNoCanaries((string) $response->getContent(), 'Password invalid JSON');

        $html = $this->get(route('status.show'));
        $this->assertNoCanaries((string) $html->getContent(), 'Password invalid HTML');
    }

    public function test_password_expired_session_never_leaks_canaries(): void
    {
        $this->seedLeakyFixture();
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $locked = $this->get(route('status.show'));
        $this->forwardSessionCookie($locked);
        $unlock = $this->post(route('status.unlock'), ['password' => 'correct-horse-battery']);
        $this->forwardSessionCookie($unlock);

        // Bump the settings version (e.g. branding change) => session expires.
        Carbon::setTestNow(now()->addMinute());
        $settings = StatusPageSetting::singleton();
        $settings->branding = ['title' => 'Rotated'];
        $settings->save();
        Carbon::setTestNow();

        $html = $this->get(route('status.show'));
        $this->assertNoCanaries((string) $html->getContent(), 'Password expired HTML');
        $html->assertDontSee('Published Service', false);

        $json = $this->getJson(route('status.json'));
        $this->assertNoCanaries((string) $json->getContent(), 'Password expired JSON');
    }

    public function test_password_rotated_session_never_leaks_canaries(): void
    {
        $this->seedLeakyFixture();
        $this->setMode(StatusPageSetting::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $locked = $this->get(route('status.show'));
        $this->forwardSessionCookie($locked);
        $unlock = $this->post(route('status.unlock'), ['password' => 'correct-horse-battery']);
        $this->forwardSessionCookie($unlock);

        // Rotate the password: updated_at advances, old unlock is revoked.
        Carbon::setTestNow(now()->addMinute());
        $settings = StatusPageSetting::singleton();
        $settings->password_hash = Hash::make('rotated-secret-passphrase');
        $settings->save();
        Carbon::setTestNow();

        $html = $this->get(route('status.show'));
        $this->assertNoCanaries((string) $html->getContent(), 'Password rotated HTML');
        $html->assertDontSee('Published Service', false);

        $json = $this->getJson(route('status.json'));
        $this->assertNoCanaries((string) $json->getContent(), 'Password rotated JSON');
    }
}
