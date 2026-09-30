<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule attribution on the check record.
 *
 * `DETECTION-RULES.md` §6.1 and FR-49 require the fired signals to be recorded
 * verbatim so attribution is reviewable and so the canonical decay arithmetic
 * (§6.4) can carry prior signals forward at their own weights.
 *
 * `DATABASE.md` §3.6 does not list this column; it is introduced here as the
 * minimum schema addition Phase 5 requires, rather than the alternative of
 * persisting redundant per-signal rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->json('triggered_rules')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropColumn('triggered_rules');
        });
    }
};
