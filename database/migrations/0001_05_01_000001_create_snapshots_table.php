<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('check_id')->nullable()->constrained('checks')->cascadeOnDelete();
            // incident_id is deferred to Phase 6; snapshots are still captured and linked by check/website.
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->string('html_path', 1024)->nullable();
            $table->json('headers')->nullable();
            $table->string('final_url', 2048)->nullable();
            $table->string('title', 512)->nullable();
            $table->json('keywords')->nullable();
            $table->json('external_links')->nullable();
            $table->json('redirect_chain')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('expires_at')->nullable()->index('idx_snapshots_expires_at');
            $table->timestamps();

            $table->index('incident_id', 'idx_snapshots_incident_id');
            $table->index('created_at', 'idx_snapshots_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
