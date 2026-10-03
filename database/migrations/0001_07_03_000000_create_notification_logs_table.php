<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('notification_channels')->nullOnDelete();
            $table->enum('status', ['queued', 'sent', 'failed', 'suppressed'])->default('queued');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('provider_message_id', 255)->nullable();
            $table->text('error')->nullable();
            $table->string('dedupe_key', 191)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['incident_id', 'channel_id'], 'idx_notification_logs_incident_channel');
            $table->index('dedupe_key', 'idx_notification_logs_dedupe_key');
            $table->index('created_at', 'idx_notification_logs_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
