<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings version snapshots (ADR-043).
 *
 * Every settings save writes an immutable snapshot of the full key/value map so
 * an admin can roll back to a known-good state. `version` is a monotonic
 * counter; `checksum` is a sha256 over the canonical (ksort + json) map so the
 * same logical state is deduplicated and tampering is detectable; `author_id`
 * is nullOnDelete so removing an admin never destroys the audit trail.
 *
 * `source` distinguishes a manual save from a pull/reconcile or a rollback, so
 * the history list can label each entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('setting_versions')) {
            return;
        }

        Schema::create('setting_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version');
            $table->string('label', 255)->nullable();
            $table->json('snapshot');
            $table->string('checksum', 64);
            $table->string('source', 32)->default('save');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('version', 'uq_setting_versions_version');
            $table->index('checksum', 'idx_setting_versions_checksum');
            $table->index(['created_at'], 'idx_setting_versions_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_versions');
    }
};
