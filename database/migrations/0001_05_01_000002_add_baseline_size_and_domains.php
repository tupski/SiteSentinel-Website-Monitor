<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_baselines', function (Blueprint $table) {
            $table->unsignedInteger('response_size_bytes')->nullable()->after('content_hash');
            $table->json('external_domains')->nullable()->after('keyword_counts');
        });
    }

    public function down(): void
    {
        Schema::table('website_baselines', function (Blueprint $table) {
            $table->dropColumn(['response_size_bytes', 'external_domains']);
        });
    }
};
