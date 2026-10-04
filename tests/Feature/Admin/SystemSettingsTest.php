<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
