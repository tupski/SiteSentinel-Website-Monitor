<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 (ADR-031): associate a website with a status page. Nullable FK;
 * NULL falls back to the default page (DATABASE.md §3.4, §3.22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('status_page_id')
                ->nullable()
                ->after('is_visible_on_status')
                ->constrained('status_pages')
                ->nullOnDelete();

            $table->index('status_page_id', 'idx_websites_status_page_id');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropForeign(['status_page_id']);
            $table->dropIndex('idx_websites_status_page_id');
            $table->dropColumn('status_page_id');
        });
    }
};
