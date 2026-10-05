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
        foreach (SettingsRepository::keys() as $key) {
            $value = SettingsRepository::defaultFor($key);

            // Null defaults (e.g. the optional branding assets) are left
            // unseeded so the row is absent and the default resolver applies.
            if ($value === null || $value === '') {
                continue;
            }

            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => is_scalar($value) ? (string) $value : json_encode($value), 'is_encrypted' => false],
            );
        }
    }
}
