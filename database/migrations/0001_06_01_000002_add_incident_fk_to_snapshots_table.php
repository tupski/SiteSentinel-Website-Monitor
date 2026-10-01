<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->foreign('incident_id', 'fk_snapshots_incident_id')
                ->references('id')
                ->on('incidents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropForeign('fk_snapshots_incident_id');
        });
    }
};
