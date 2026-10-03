<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('is_visible_on_status')->default(false)->after('is_active');
            $table->string('status_alias', 255)->nullable()->after('is_visible_on_status');
            $table->index(['is_active', 'is_visible_on_status'], 'idx_websites_visible_status');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex('idx_websites_visible_status');
            $table->dropColumn(['is_visible_on_status', 'status_alias']);
        });
    }
};
