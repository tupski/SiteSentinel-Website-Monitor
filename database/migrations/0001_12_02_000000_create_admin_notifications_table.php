<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-admin in-app notification centre (DATABASE.md §3.24, ADR-038).
 *
 * Decoupled from outbound channel delivery: this is NOT a provider, does not
 * run on the `notifications` queue, and does not participate in
 * `notification_logs` suppression/cooldown. Rows are generated from the
 * existing incident/security/config events (the same events written to
 * `audit_logs`) and carry durable read/unread state per admin.
 *
 * `dedupe_key` is UNIQUE so generation is idempotent across retries (MySQL and
 * SQLite both allow multiple NULLs in a unique index). `link_url` is an
 * internal relative path only — never an absolute external URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_notifications')) {
            return;
        }

        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('title', 255);
            $table->string('body', 500)->nullable();
            $table->string('severity', 16)->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('dedupe_key', 191)->nullable();
            $table->timestamps();

            $table->unique('dedupe_key', 'uq_admin_notifications_dedupe_key');
            $table->index(['user_id', 'read_at', 'created_at'], 'idx_admin_notifications_user_read_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
