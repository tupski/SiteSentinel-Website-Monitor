<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detection_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_id', 64)->unique('uq_detection_rules_rule_id');
            $table->string('name', 255);
            $table->string('category', 64)->index('idx_detection_rules_category');
            $table->enum('severity', ['INFO', 'WARNING', 'CRITICAL'])->default('INFO');
            $table->unsignedInteger('default_weight')->default(1);
            $table->boolean('enabled')->default(true)->index('idx_detection_rules_enabled');
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_rules');
    }
};
