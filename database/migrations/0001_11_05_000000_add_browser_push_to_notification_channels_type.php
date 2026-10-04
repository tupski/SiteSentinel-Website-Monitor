<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the notification channel `type` enum with `browser_push`
 * (DATABASE.md §3.13, NOTIFICATIONS.md §7.3, ADR-032, Phase 11).
 *
 * MySQL uses an explicit ENUM alter. SQLite (local dev + test suite, ADR-022)
 * renders `enum` as a CHECK constraint, so the column must be rebuilt — the
 * Schema builder's `change()` path does this. The canonical production schema
 * is MySQL 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->isMysql()) {
            DB::statement("ALTER TABLE `notification_channels` MODIFY `type` ENUM('email','telegram','browser_push') NOT NULL");

            return;
        }

        Schema::table('notification_channels', function (Blueprint $table): void {
            $table->enum('type', ['email', 'telegram', 'browser_push'])->change();
        });
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::statement("ALTER TABLE `notification_channels` MODIFY `type` ENUM('email','telegram') NOT NULL");

            return;
        }

        Schema::table('notification_channels', function (Blueprint $table): void {
            $table->enum('type', ['email', 'telegram'])->change();
        });
    }

    private function isMysql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }
};
