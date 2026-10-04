<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StatusPage;
use Illuminate\Database\Seeder;

/**
 * Ensures the default status page exists (Phase 11, ADR-031).
 *
 * Idempotent by natural key (`slug`): a re-run upserts nothing it did not
 * create, and never clobbers an operator's customised default page.
 */
class StatusPageSeeder extends Seeder
{
    public function run(): void
    {
        StatusPage::query()->firstOrCreate(
            ['slug' => StatusPage::DEFAULT_SLUG],
            [
                'name' => 'Status page',
                'is_default' => true,
                'visibility_mode' => StatusPage::MODE_PRIVATE,
                'password_hash' => null,
            ]
        );
    }
}
