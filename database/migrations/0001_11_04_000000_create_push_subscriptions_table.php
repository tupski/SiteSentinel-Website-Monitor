<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browser Push subscription material (DATABASE.md §3.23, ADR-032, Phase 11).
 *
 * `endpoint`, `p256dh`, and `auth` are subscription secrets; they are cast
 * encrypted at the model layer (SECURITY.md §4). MySQL cannot uniquely index a
 * TEXT column, so a deterministic `endpoint_hash` (SHA-256) backs the
 * `uq_push_subscriptions_endpoint_hash` constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64);
            $table->text('p256dh')->nullable();
            $table->text('auth')->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique('endpoint_hash', 'uq_push_subscriptions_endpoint_hash');
            $table->index('enabled', 'idx_push_subscriptions_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
