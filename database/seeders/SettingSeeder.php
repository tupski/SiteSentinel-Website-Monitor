<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Seeder;

/**
 * Seeds the default system settings (ADR-035).
 *
 * Idempotent by key: `firstOrCreate` only inserts a row that does not exist, so
 * a re-run never overwrites an operator's customised value. Settings themselves
 * are optional — a missing row resolves to its config-derived default in
 * {@see SettingsRepository} — the seeder just makes the defaults explicit and
 * visible in the table.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            SettingsRepository::SITE_NAME => (string) config('app.name', 'SiteSentinel'),
            SettingsRepository::TIMEZONE => (string) config('app.timezone', 'UTC'),
        ];

        foreach ($defaults as $key => $value) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'is_encrypted' => false],
            );
        }
    }
}
