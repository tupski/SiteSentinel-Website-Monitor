<?php

declare(strict_types=1);

use App\Models\StatusPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 (ADR-031, data-preserving): move the singleton
 * status_page_settings row into one default status_pages row.
 *
 * Additive and never destructive — the legacy status_page_settings table is
 * retained for one release for rollback safety (DATABASE.md §3.18) and no row
 * in it is modified or deleted. If no singleton row exists yet, nothing is
 * created here: a page is provisioned lazily via {@see StatusPage::default()}.
 */
return new class extends Migration
{
    private const DEFAULT_SLUG = 'status';

    public function up(): void
    {
        if (! Schema::hasTable('status_pages') || ! Schema::hasTable('status_page_settings')) {
            return;
        }

        // Already migrated (idempotent): a default page exists.
        if (DB::table('status_pages')->where('is_default', true)->exists()) {
            return;
        }

        $setting = DB::table('status_page_settings')->orderBy('id')->first();
        if ($setting === null) {
            return;
        }

        $slug = $this->uniqueSlug((string) ($setting->slug ?? '') ?: self::DEFAULT_SLUG);

        DB::table('status_pages')->insert([
            'name' => 'Status page',
            'slug' => $slug,
            'is_default' => true,
            'visibility_mode' => (string) ($setting->visibility_mode ?? 'Private'),
            'password_hash' => $setting->password_hash ?? null,
            'created_by' => null,
            'created_at' => $setting->created_at ?? now('UTC'),
            'updated_at' => $setting->updated_at ?? now('UTC'),
        ]);
    }

    public function down(): void
    {
        // Non-destructive: the legacy table still holds the original row, so the
        // migrated default page is simply removed. Websites keep their (now
        // dangling) status_page_id only if the FK were dropped separately; the
        // FK lives on the other migration, so this is a clean no-op for websites.
        if (Schema::hasTable('status_pages')) {
            DB::table('status_pages')->where('is_default', true)->delete();
        }
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 1;
        while (DB::table('status_pages')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
};
