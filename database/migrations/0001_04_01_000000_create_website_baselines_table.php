<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('final_url', 2048)->nullable();
            $table->string('title', 512)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('hash_algorithm', 20)->default('sha256');
            $table->json('keyword_counts')->nullable();
            $table->unsignedInteger('external_link_count')->default(0);
            $table->boolean('ssl_valid')->nullable();
            $table->string('ssl_issuer', 255)->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['website_id', 'version'], 'uq_website_baselines_website_version');
            $table->index(['website_id', 'is_active'], 'idx_website_baselines_website_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_baselines');
    }
};
