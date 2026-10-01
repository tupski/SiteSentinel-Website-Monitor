<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->enum('type', ['availability', 'security']);
            $table->string('category', 64)->nullable();
            $table->enum('severity', ['INFO', 'WARNING', 'CRITICAL']);
            $table->enum('status', ['DETECTED', 'ACKNOWLEDGED', 'RESOLVED'])->default('DETECTED');
            $table->unsignedInteger('score')->default(0);
            $table->json('triggered_rules')->nullable();
            $table->text('message')->nullable();
            $table->json('technical_metadata')->nullable();
            $table->string('dedupe_key', 191);
            $table->timestamp('detected_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('resolution_mode', ['manual', 'auto'])->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status'], 'idx_incidents_website_status');
            $table->index(['status', 'detected_at'], 'idx_incidents_status_detected_at');
            $table->index('dedupe_key', 'idx_incidents_dedupe_key');
            $table->index('created_at', 'idx_incidents_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
