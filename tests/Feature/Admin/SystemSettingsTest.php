<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Middleware\ApplySystemSettings;
use App\Models\Check;
use App\Models\Setting;
use App\Models\SettingVersion;
use App\Models\User;
use App\Models\Website;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 6 system settings tests (ADR-035).
 *
 * Covers access control (mirrors AdminAccessControlTest), persistence through
 * the settings accessor, validation, config-derived defaults when no rows
 * exist, seeder idempotency, upload store/replace/remove, and the secret
 * boundary (arbitrary / infrastructure keys are never writable).
 */
final class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('super-secret-passphrase'),
        ]);
    }

    // --- Authorization -----------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.settings.edit'))->assertRedirect(route('login'));

        $this->put(route('admin.settings.update'), ['site_name' => 'X', 'timezone' => 'UTC'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'viewer'])->save();

        $this->actingAs($user)->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($user)
            ->put(route('admin.settings.update'), ['site_name' => 'Nope', 'timezone' => 'UTC'])
            ->assertForbidden();
    }

    public function test_page_loads(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('System settings')
            ->assertSee('General')
            ->assertSee('Branding')
            ->assertSee('Timezone');
    }

    public function test_page_renders_every_logical_category(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admin.settings.edit'))
            ->assertOk();

        foreach (['General', 'Branding', 'System', 'Monitoring & checks', 'Detection & scoring', 'Notifications', 'Retention & data', 'Security'] as $heading) {
            $response->assertSee($heading);
        }

        // Version control surface is present.
        $response->assertSee('Version control');
        $response->assertSee('Pull update');
        $response->assertSee('History');
    }

    public function test_page_is_responsive_and_theme_aware(): void
    {
        $html = (string) $this->actingAs($this->admin())
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->getContent();

        // Token surfaces (dark-mode safe) and a responsive grid.
        $this->assertStringContainsString('bg-surface-elevated', $html);
        $this->assertStringContainsString('border-border', $html);
        $this->assertStringContainsString('lg:grid-cols-[1fr_20rem]', $html);
        $this->assertStringContainsString('sm:grid-cols-2', $html);

        // No legacy light-only surfaces.
        $this->assertStringNotContainsString('bg-white shadow-sm', $html);
    }

    // --- Persistence -------------------------------------------------------

    public function test_saving_valid_settings_persists_and_reads_back(): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'site_description' => 'Always watching.',
            'timezone' => 'Asia/Jakarta',
        ])->assertRedirect(route('admin.settings.edit'));

        $this->assertDatabaseHas('settings', ['key' => 'site_name', 'value' => 'Judol Monitor']);
        $this->assertDatabaseHas('settings', ['key' => 'site_description', 'value' => 'Always watching.']);
        $this->assertDatabaseHas('settings', ['key' => 'timezone', 'value' => 'Asia/Jakarta']);

        $repository = app(SettingsRepository::class);
        $repository->flush();

        $this->assertSame('Judol Monitor', $repository->string(SettingsRepository::SITE_NAME));
        $this->assertSame('Asia/Jakarta', settings(SettingsRepository::TIMEZONE));
    }

    // --- Validation --------------------------------------------------------

    public function test_invalid_timezone_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), [
                'site_name' => 'Judol Monitor',
                'timezone' => 'Mars/Olympus_Mons',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertDatabaseMissing('settings', ['key' => 'timezone']);
    }

    public function test_too_long_site_name_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), [
                'site_name' => str_repeat('a', 256),
                'timezone' => 'UTC',
            ])
            ->assertSessionHasErrors('site_name');
    }

    public function test_invalid_image_mime_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), [
                'site_name' => 'Judol Monitor',
                'timezone' => 'UTC',
                'site_logo' => UploadedFile::fake()->create('malicious.exe', 10, 'application/octet-stream'),
            ])
            ->assertSessionHasErrors('site_logo');

        $this->assertDatabaseMissing('settings', ['key' => 'site_logo']);
    }

    public function test_oversized_logo_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), [
                'site_name' => 'Judol Monitor',
                'timezone' => 'UTC',
                // 3 MB > the 2 MB (2048 KB) rule.
                'site_logo' => UploadedFile::fake()->image('big.png')->size(3072),
            ])
            ->assertSessionHasErrors('site_logo');
    }

    // --- Defaults ----------------------------------------------------------

    public function test_defaults_apply_when_no_settings_rows_exist(): void
    {
        $this->assertSame(0, Setting::query()->count());

        $repository = app(SettingsRepository::class);
        $repository->flush();

        $this->assertSame((string) config('app.name'), $repository->string(SettingsRepository::SITE_NAME));
        $this->assertSame((string) config('app.timezone'), $repository->string(SettingsRepository::TIMEZONE));
        $this->assertNull($repository->get(SettingsRepository::SITE_LOGO));

        // The page still renders with no rows.
        $this->actingAs($this->admin())
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee((string) config('app.timezone'));
    }

    // --- Seeder idempotency ------------------------------------------------

    public function test_seeder_is_idempotent_and_never_clobbers_custom_values(): void
    {
        $this->seed(SettingSeeder::class);

        $repository = app(SettingsRepository::class);
        $repository->set(SettingsRepository::SITE_NAME, 'Custom Name');
        $repository->flush();

        // Re-run the seeder: the customised value must survive.
        $this->seed(SettingSeeder::class);

        $this->assertSame('Custom Name', Setting::query()->where('key', 'site_name')->value('value'));
        $this->assertSame(1, Setting::query()->where('key', 'site_name')->count());
    }

    // --- Uploads -----------------------------------------------------------

    public function test_logo_upload_stores_file_and_persists_path(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            'site_logo' => UploadedFile::fake()->image('logo.png', 100, 40),
        ])->assertRedirect(route('admin.settings.edit'));

        $path = Setting::query()->where('key', 'site_logo')->value('value');

        $this->assertIsString($path);
        $this->assertStringStartsWith('branding/', $path);
        Storage::disk('public')->assertExists($path);
        // The original filename is never trusted.
        $this->assertStringNotContainsString('logo.png', (string) $path);
    }

    public function test_replacing_logo_removes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            'site_logo' => UploadedFile::fake()->image('first.png', 100, 40),
        ]);

        $first = (string) Setting::query()->where('key', 'site_logo')->value('value');
        Storage::disk('public')->assertExists($first);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            'site_logo' => UploadedFile::fake()->image('second.png', 100, 40),
        ]);

        $second = (string) Setting::query()->where('key', 'site_logo')->value('value');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_favicon_can_be_removed(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            'favicon' => UploadedFile::fake()->image('icon.png', 16, 16),
        ]);

        $path = (string) Setting::query()->where('key', 'favicon')->value('value');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            'remove_favicon' => '1',
        ]);

        $this->assertDatabaseMissing('settings', ['key' => 'favicon']);
        Storage::disk('public')->assertMissing($path);
    }

    // --- Audit -------------------------------------------------------------

    public function test_update_emits_the_canonical_settings_changed_audit_event(): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.changed']);
    }

    // --- Secret boundary ---------------------------------------------------

    public function test_arbitrary_and_secret_looking_keys_are_never_written(): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            // Smuggled keys that must never land in the settings table.
            'app_key' => 'base64:attacker-controlled-key',
            'db_password' => 'hunter2',
            'mail_password' => 'smtp-secret',
            'telegram_bot_token' => '123456:ABC',
            'vapid_private_key' => 'private-key-material',
            'some_unknown_setting' => 'nope',
        ])->assertRedirect(route('admin.settings.edit'));

        foreach (['app_key', 'db_password', 'mail_password', 'telegram_bot_token', 'vapid_private_key', 'some_unknown_setting'] as $key) {
            $this->assertDatabaseMissing('settings', ['key' => $key]);
        }

        // Only whitelisted keys were written.
        $this->assertSame(
            ['site_name', 'timezone'],
            Setting::query()->orderBy('key')->pluck('key')->all(),
        );
    }

    public function test_repository_rejects_unregistered_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SettingsRepository::class)->set('app_key', 'sneaky');
    }

    // --- Applied-at-runtime (regression for "settings saved but not applied") ---

    public function test_saved_timezone_is_applied_to_the_running_request(): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'Asia/Jakarta',
        ])->assertRedirect(route('admin.settings.edit'));

        app(SettingsRepository::class)->flush();

        // Drive the middleware directly and capture the timezone observed by
        // the downstream handler (terminate() restores it afterwards).
        $middleware = app(ApplySystemSettings::class);
        $request = Request::create('/admin/settings', 'GET');

        $observed = null;
        $middleware->handle($request, function () use (&$observed) {
            $observed = date_default_timezone_get();

            return response('ok');
        });

        $this->assertSame('Asia/Jakarta', $observed);
        $this->assertSame((string) config('app.timezone'), 'Asia/Jakarta');

        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
    }

    public function test_saved_site_name_is_shared_with_views(): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
        ]);

        $response = $this->get(route('admin.settings.edit'))->assertOk();

        $response->assertSee('Judol Monitor', false);
    }

    public function test_saved_retention_window_is_consumed_by_pruning(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            'retention_checks_days' => 60,
        ])->assertRedirect(route('admin.settings.edit'));

        app(SettingsRepository::class)->flush();
        $this->assertSame(60, app(SettingsRepository::class)->int(SettingsRepository::RETENTION_CHECKS_DAYS));

        // A 45-day-old check survives the 60-day window, an 61-day-old is pruned.
        $website = Website::factory()->create();
        $kept = $this->agedCheck($website, 'kept', 45);
        $pruned = $this->agedCheck($website, 'pruned', 61);

        $this->artisan('model:prune', ['--model' => [Check::class]])->assertExitCode(0);

        $this->assertDatabaseHas('checks', ['id' => $kept->id]);
        $this->assertDatabaseMissing('checks', ['id' => $pruned->id]);
    }

    public function test_saved_scoring_threshold_is_consumed_by_the_rule_engine(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            'scoring_threshold_info' => 5,
        ])->assertRedirect(route('admin.settings.edit'));

        app(SettingsRepository::class)->flush();

        $this->assertSame(5, app(SettingsRepository::class)->int(SettingsRepository::SCORING_THRESHOLD_INFO));
    }

    public function test_middleware_restores_the_previous_timezone_on_terminate(): void
    {
        $original = date_default_timezone_get();

        app(SettingsRepository::class)->set(SettingsRepository::TIMEZONE, 'Asia/Jakarta');

        $middleware = app(ApplySystemSettings::class);
        $request = Request::create('/admin/settings', 'GET');

        $middleware->handle($request, fn () => response('ok'));

        $this->assertSame('Asia/Jakarta', date_default_timezone_get());

        $middleware->terminate($request, response('ok'));

        $this->assertSame($original, date_default_timezone_get());
    }

    private function agedCheck(Website $website, string $key, int $days): Check
    {
        $check = new Check([
            'website_id' => $website->id,
            'check_key' => $key,
            'started_at' => now()->subDays($days),
        ]);
        $check->created_at = now()->subDays($days);
        $check->save();

        return $check;
    }

    // --- Version control (ADR-043) -----------------------------------------

    public function test_saving_creates_a_version_snapshot(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
        ])->assertRedirect(route('admin.settings.edit'));

        $this->assertDatabaseHas('setting_versions', [
            'source' => 'save',
            'author_id' => $admin->id,
        ]);

        $version = SettingVersion::query()->orderByDesc('version')->first();
        $this->assertNotNull($version);
        $this->assertSame('Judol Monitor', $version->snapshot['site_name']);
    }

    public function test_identical_consecutive_saves_do_not_duplicate_versions(): void
    {
        $admin = $this->admin();
        $payload = ['site_name' => 'Judol Monitor', 'timezone' => 'UTC'];

        $this->actingAs($admin)->put(route('admin.settings.update'), $payload);
        $this->actingAs($admin)->put(route('admin.settings.update'), $payload);

        $this->assertSame(1, SettingVersion::query()->count());
    }

    public function test_rollback_restores_a_previous_version(): void
    {
        $admin = $this->admin();

        // v1: the initial state.
        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'First Name',
            'timezone' => 'UTC',
        ]);
        $v1 = SettingVersion::query()->orderBy('version')->first();

        // v2: change the name.
        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Second Name',
            'timezone' => 'UTC',
        ]);

        $this->assertSame('Second Name', Setting::query()->where('key', 'site_name')->value('value'));

        // Roll back to v1.
        $this->actingAs($admin)
            ->post(route('admin.settings.rollback', $v1))
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('First Name', Setting::query()->where('key', 'site_name')->value('value'));
        $this->assertDatabaseHas('setting_versions', ['source' => 'rollback']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.rolled_back']);
    }

    public function test_pull_update_refreshes_to_the_latest_known_state(): void
    {
        $admin = $this->admin();

        // Create a version, then diverge the live settings directly.
        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Known Good',
            'timezone' => 'UTC',
        ]);

        app(SettingsRepository::class)->set(SettingsRepository::SITE_NAME, 'Drifted');
        app(SettingsRepository::class)->flush();

        // Pull reloads the most recent stored snapshot.
        $this->actingAs($admin)
            ->post(route('admin.settings.pull'))
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertDatabaseHas('settings', ['key' => 'site_name', 'value' => 'Known Good']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.pulled']);
    }

    public function test_pull_update_applies_a_configured_upstream_file(): void
    {
        $admin = $this->admin();
        $path = tempnam(sys_get_temp_dir(), 'sentinel-settings-');
        file_put_contents($path, json_encode([
            'site_name' => 'From Upstream',
            'retention.checks_days' => 90,
            // An unknown key must never be applied.
            'app_key' => 'attacker',
        ]));

        config(['sentinel.settings.upstream_path' => $path]);

        try {
            $this->actingAs($admin)
                ->post(route('admin.settings.pull'))
                ->assertRedirect(route('admin.settings.edit'));

            $this->assertDatabaseHas('settings', ['key' => 'site_name', 'value' => 'From Upstream']);
            $this->assertDatabaseHas('settings', ['key' => 'retention.checks_days', 'value' => '90']);
            $this->assertDatabaseMissing('settings', ['key' => 'app_key']);
        } finally {
            @unlink($path);
        }
    }

    public function test_pull_and_rollback_are_admin_only(): void
    {
        $viewer = User::factory()->create();
        $viewer->forceFill(['role' => 'viewer'])->save();

        $version = SettingVersion::query()->create([
            'version' => 1,
            'snapshot' => ['site_name' => 'X'],
            'checksum' => 'abc',
            'source' => 'save',
        ]);

        $this->actingAs($viewer)->post(route('admin.settings.pull'))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.settings.rollback', $version))->assertForbidden();
    }

    public function test_rollback_numeric_settings_restore_the_snapshot_value(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            'notifications_default_cooldown_minutes' => 30,
        ]);
        $v1 = SettingVersion::query()->orderBy('version')->first();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name' => 'Judol Monitor',
            'timezone' => 'UTC',
            'notifications_default_cooldown_minutes' => 5,
        ]);

        $this->actingAs($admin)->post(route('admin.settings.rollback', $v1));

        $this->assertDatabaseHas('settings', [
            'key' => 'notifications.default_cooldown_minutes',
            'value' => '30',
        ]);
    }
}
