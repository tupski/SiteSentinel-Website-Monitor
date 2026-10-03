<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_cooldowns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->string('event_kind', 64);
            $table->string('cooldown_key', 191);
            $table->timestamp('window_started_at');
            $table->timestamp('expires_at');
            $table->unsignedInteger('suppressed_count')->default(0);
            $table->timestamps();

            $table->unique('cooldown_key', 'uq_notification_cooldowns_key');
            $table->index('expires_at', 'idx_notification_cooldowns_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_cooldowns');
    }
};
