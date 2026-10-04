<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value application settings (DATABASE.md §3.17, ADR-035).
 *
 * The shape is frozen by DATABASE.md §3.17 verbatim: `key` unique, `value`
 * TEXT nullable, `is_encrypted` TINYINT(1). `is_encrypted` is carried for
 * forward compatibility but is NOT used at MVP — no secret is stored here
 * (ADR-035 secret boundary).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            return;
        }

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 191)->unique('uq_settings_key');
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
