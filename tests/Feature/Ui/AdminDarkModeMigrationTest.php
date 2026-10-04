<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3a — the migrated admin views must be theme-aware: they consume the
 * semantic token utilities (`bg-surface-*`, `text-text*`, `border-border*`) and
 * the shared table/button primitives, so light AND dark render correctly
 * without the legacy `html.dark` bridge.
 *
 * Deliberately non-brittle: these assert semantic class tokens, never a full
 * class string (so incidental class-order/style changes do not break them).
 *
 * NOTE: the rendered page includes `x-admin-layout`, which is intentionally
 * out of this phase's scope and still uses its own `dark:` utilities. The
 * absence checks therefore target the OLD view-local card/table/control
 * combinations (`bg-white shadow-sm`, `divide-slate-200`, `bg-slate-900`,
 * `border-slate-300`) that never appear in the layout shell.
 */
final class AdminDarkModeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function html(string $route, array $params = []): string
    {
        return (string) $this->actingAs($this->admin())
            ->get(route($route, $params))
            ->assertOk()
            ->getContent();
    }

    public function test_dashboard_cards_are_theme_aware(): void
    {
        $html = $this->html('admin.dashboard');

        // Elevated card surface + tokenised text/border.
        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('border-border', $html);
        $this->assertStringContainsString('text-text', $html);

        // The old light-only card surface must be gone from the view.
        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
        $this->assertStringNotContainsString('divide-slate-200', $html);
    }

    public function test_websites_index_uses_theme_aware_table_and_buttons(): void
    {
        Website::factory()->create(['name' => 'Theme Aware Site']);

        $html = $this->html('admin.websites.index');

        // Table primitive owns the overflow container (§12) and token surfaces.
        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('bg-surface-muted', $html);

        // Theme-aware buttons/inputs.
        $this->assertStringContainsString('bg-primary', $html);
        $this->assertStringContainsString('text-primary-foreground', $html);

        // Old view-local light-only surfaces gone.
        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
        $this->assertStringNotContainsString('rounded bg-slate-900', $html);
        $this->assertStringNotContainsString('divide-slate-200', $html);
    }

    public function test_incidents_index_uses_theme_aware_table(): void
    {
        $website = Website::factory()->create();

        Incident::create([
            'website_id' => $website->id,
            'type' => 'availability',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 5,
            'dedupe_key' => 'availability:website:'.$website->id,
            'detected_at' => now(),
        ]);

        $html = $this->html('admin.incidents.index');

        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('divide-border', $html);

        $this->assertStringNotContainsString('divide-slate-200', $html);
        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
    }

    public function test_website_form_uses_theme_aware_controls(): void
    {
        $html = $this->html('admin.websites.create');

        // Tokenised surfaces on the form card and controls.
        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('border-border-muted', $html);
        $this->assertStringContainsString('focus:ring-focus', $html);

        $this->assertStringNotContainsString('rounded border border-slate-300', $html);
        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
    }

    public function test_incident_show_surfaces_are_theme_aware(): void
    {
        $website = Website::factory()->create();

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'availability',
            'severity' => 'CRITICAL',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'availability:show:'.$website->id,
            'detected_at' => now(),
        ]);

        $html = $this->html('admin.incidents.show', ['incident' => $incident]);

        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('border-border', $html);

        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
        $this->assertStringNotContainsString('divide-slate-200', $html);
    }

    public function test_flash_message_renders_as_token_alert(): void
    {
        NotificationChannel::create([
            'type' => 'email',
            'name' => 'Alert mail',
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

        $html = (string) $this->actingAs($this->admin())
            ->withSession(['status' => 'Saved successfully.'])
            ->get(route('admin.websites.index'))
            ->assertOk()
            ->getContent();

        // Flash messages adopt the shared alert primitive (semantic variant bg).
        $this->assertStringContainsString('bg-success-muted', $html);
        $this->assertStringContainsString('Saved successfully.', $html);

        // Requirement 22 — success flashes are dismissible on the page.
        $this->assertStringContainsString('x-data="flashMessage"', $html);
        $this->assertStringContainsString('aria-label="Dismiss notification"', $html);
        $this->assertStringContainsString('x-on:click.stop="dismiss()"', $html);
    }

    // -----------------------------------------------------------------------
    // Phase 3b — notifications, status pages and status settings.
    // -----------------------------------------------------------------------

    private function emailChannel(): NotificationChannel
    {
        return NotificationChannel::create([
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
    }

    public function test_notification_channels_index_uses_theme_aware_table(): void
    {
        $this->emailChannel();

        $html = $this->html('admin.notifications.index');

        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('bg-surface-muted', $html);

        // The push opt-in card adopts the shared elevated surface.
        $this->assertStringContainsString('border-border', $html);

        // Old view-local light-only surfaces gone.
        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
        $this->assertStringNotContainsString('divide-slate-200', $html);
        $this->assertStringNotContainsString('rounded bg-slate-900', $html);
    }

    public function test_notification_channel_form_uses_theme_aware_controls(): void
    {
        $html = $this->html('admin.notifications.create');

        // Tokenised form card + controls.
        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('border-border-muted', $html);
        $this->assertStringContainsString('focus:ring-focus', $html);

        // Alpine type-conditional bindings survive the migration.
        $this->assertStringContainsString("x-show=\"type === 'email'\"", $html);
        $this->assertStringContainsString("x-bind:disabled=\"type !== 'email'\"", $html);

        // No secret is echoed back into the form.
        $this->assertStringContainsString('name="secret_ref"', $html);
        $this->assertStringNotContainsString('smtp-secret-value', $html);

        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
        $this->assertStringNotContainsString('rounded border border-slate-300', $html);
        $this->assertStringNotContainsString('divide-slate-200', $html);
    }

    public function test_delivery_log_index_uses_theme_aware_table_and_filters(): void
    {
        $html = $this->html('admin.notification-logs.index');

        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('bg-surface-muted', $html);

        // Filter bar adopts the token surface.
        $this->assertStringContainsString('border-border', $html);

        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
        $this->assertStringNotContainsString('divide-slate-200', $html);
        $this->assertStringNotContainsString('rounded bg-slate-900', $html);
    }

    public function test_status_pages_index_uses_theme_aware_table(): void
    {
        $html = $this->html('admin.status-pages.index');

        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('bg-surface-muted', $html);

        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
        $this->assertStringNotContainsString('divide-slate-200', $html);
        $this->assertStringNotContainsString('rounded bg-slate-900', $html);
    }

    public function test_status_page_form_uses_theme_aware_controls_and_warning_alert(): void
    {
        $html = $this->html('admin.status-pages.create');

        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('bg-warning-muted', $html);

        // Shared password fields (also fixing status-settings) moved onto tokens.
        $this->assertStringContainsString('border-border-muted', $html);

        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
        $this->assertStringNotContainsString('rounded border border-slate-300', $html);
        $this->assertStringNotContainsString('bg-amber-50', $html);
    }

    public function test_status_settings_form_uses_theme_aware_controls_and_warning_alert(): void
    {
        $html = $this->html('admin.status-settings.edit');

        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('bg-warning-muted', $html);
        $this->assertStringContainsString('border-border-muted', $html);

        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
        $this->assertStringNotContainsString('rounded border border-slate-300', $html);
        $this->assertStringNotContainsString('bg-amber-50', $html);
    }
}
