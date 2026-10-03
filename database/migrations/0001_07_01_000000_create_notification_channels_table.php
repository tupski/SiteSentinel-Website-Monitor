<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['email', 'telegram']);
            $table->string('name', 255);
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->text('secret_ref')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'enabled'], 'idx_notification_channels_type_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
