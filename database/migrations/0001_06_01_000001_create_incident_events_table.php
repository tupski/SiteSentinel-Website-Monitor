<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->enum('from_status', ['DETECTED', 'ACKNOWLEDGED', 'RESOLVED'])->nullable();
            $table->enum('to_status', ['DETECTED', 'ACKNOWLEDGED', 'RESOLVED'])->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['incident_id', 'created_at'], 'idx_incident_events_incident_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_events');
    }
};
