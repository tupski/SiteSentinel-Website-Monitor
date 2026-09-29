<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('check_key', 128);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('final_url', 2048)->nullable();
            $table->json('redirect_chain')->nullable();
            $table->unsignedInteger('response_size_bytes')->nullable();
            $table->boolean('ssl_valid')->nullable();
            $table->string('ssl_issuer', 255)->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->string('title', 512)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('error_type', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->enum('availability_state', ['UP', 'DOWN'])->nullable();
            $table->enum('security_state', ['OK', 'INFO', 'SUSPECT', 'INCIDENT'])->nullable();
            $table->unsignedInteger('score')->default(0);
            $table->timestamps();

            $table->unique('check_key', 'uq_checks_check_key');
            $table->index(['website_id', 'started_at'], 'idx_checks_website_started_at');
            $table->index('created_at', 'idx_checks_created_at');
            $table->index('availability_state', 'idx_checks_availability_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checks');
    }
};
