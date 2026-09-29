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
        Schema::create('website_rule_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->unsignedBigInteger('detection_rule_id');
            $table->boolean('enabled')->nullable();
            $table->unsignedInteger('weight_override')->nullable();
            $table->unsignedInteger('threshold_override')->nullable();
            $table->json('ignored_keywords')->nullable();
            $table->timestamps();

            $table->unique(
                ['website_id', 'detection_rule_id'],
                'uq_website_rule_settings_website_rule'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_rule_settings');
    }
};
