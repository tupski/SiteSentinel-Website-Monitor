<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->json('keywords')->nullable();
            $table->json('external_domains')->nullable();
            $table->json('suspicious_patterns')->nullable();
            $table->timestamps();

            $table->index('website_id', 'idx_check_extractions_website_id');
            $table->unique('check_id', 'uq_check_extractions_check_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_extractions');
    }
};
