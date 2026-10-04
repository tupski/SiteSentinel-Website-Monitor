<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 (ADR-031): multi-row status pages. Additive — replaces the
 * singleton status_page_settings model (DATABASE.md §3.22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('slug', 191);
            $table->boolean('is_default')->default(false);
            // Portable equivalent of ENUM('Private','Public','Password Protected')
            // (DATABASE.md §3.22); the CHECK below enforces the value set on MySQL.
            $table->string('visibility_mode', 32)->default('Private');
            $table->string('password_hash', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('slug', 'uq_status_pages_slug');
            $table->index('is_default', 'idx_status_pages_is_default');
        });

        // CHECK constraint for MySQL 8 / portable enum enforcement.
        // SQLite ignores raw CHECK alters on some versions; keep best-effort.
        try {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                Schema::getConnection()->statement(
                    'ALTER TABLE `status_pages` ADD CONSTRAINT `chk_status_pages_visibility_mode` '
                    ."CHECK (`visibility_mode` IN ('Private','Public','Password Protected'))"
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('status_pages');
    }
};
