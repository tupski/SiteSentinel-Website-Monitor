<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index('idx_audit_logs_user_id');
            $table->string('event', 64);
            // Morph-like columns frozen explicitly by DATABASE.md §3.19 (no morph map indirection)
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            // created_at only — no updated_at: audit rows are immutable (DATABASE.md §3.19)
            $table->timestamp('created_at')->nullable()->index('idx_audit_logs_created_at');

            // Composite index (event, created_at) per DATABASE.md §3.19
            $table->index(['event', 'created_at'], 'idx_audit_logs_event_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
