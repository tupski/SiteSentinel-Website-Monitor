<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_settings', function (Blueprint $table) {
            $table->id();
            $table->string('visibility_mode', 32)->default('Private');
            $table->string('password_hash', 255)->nullable();
            $table->string('slug', 191)->nullable();
            $table->json('branding')->nullable();
            $table->timestamps();

            $table->unique('slug', 'uq_status_page_settings_slug');
        });

        // CHECK constraint for MySQL 8 / portable enum enforcement.
        // SQLite ignores raw CHECK alters on some versions; keep best-effort.
        try {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                Schema::getConnection()->statement(
                    'ALTER TABLE `status_page_settings` ADD CONSTRAINT `chk_status_page_settings_visibility_mode` '
                    ."CHECK (`visibility_mode` IN ('Private','Public','Password Protected'))"
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_settings');
    }
};
