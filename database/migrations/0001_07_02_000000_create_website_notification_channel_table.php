<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_notification_channel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['website_id', 'channel_id'], 'uq_website_notification_channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_notification_channel');
    }
};
