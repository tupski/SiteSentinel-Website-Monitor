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
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('url', 2048);
            $table->string('scheme', 10);
            $table->string('host', 255);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('check_interval_seconds')->default(300);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('expected_status')->default(200);
            $table->string('expected_title', 512)->nullable();
            $table->string('expected_final_domain', 255)->nullable();
            $table->boolean('follow_redirects')->default(true);
            $table->text('note')->nullable();
            $table->boolean('monitor_ssl')->default(true);
            $table->boolean('monitor_redirects')->default(true);
            $table->boolean('monitor_content')->default(true);
            $table->boolean('monitor_security')->default(true);

            // Phase 4+ FK placeholder; add without foreign-key constraint now so the
            // table is valid before website_baselines exists. The FK will be added
            // by the Phase 4 migration that creates baselines.
            $table->unsignedBigInteger('current_baseline_id')->nullable();

            $table->enum('status_availability', ['UP', 'DOWN'])->nullable();
            $table->enum('status_security', ['OK', 'INFO', 'SUSPECT', 'INCIDENT'])->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->char('last_lock_token', 36)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Indexes per DATABASE.md §3.4
            $table->index('next_check_at', 'idx_websites_next_check_at');
            $table->index(['is_active', 'next_check_at'], 'idx_websites_is_active_next_check_at');
            $table->index('locked_at', 'idx_websites_locked_at');
            $table->unique('url', 'uq_websites_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
