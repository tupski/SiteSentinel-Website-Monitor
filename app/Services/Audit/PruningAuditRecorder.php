<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Events\ModelPruningFinished;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Database\Events\ModelsPruned;

/**
 * Emits the SECURITY.md §9.1 `retention.pruned` audit event for a pruning run.
 *
 * The retention pruner is Laravel's `model:prune` command (scheduled daily by
 * `routes/console.php`). The command fires `ModelPruningStarting`, one
 * `ModelsPruned` per pruned model (carrying the model class + pruned count),
 * and `ModelPruningFinished` when the whole run completes. This recorder
 * listens to those events and writes exactly one `retention.pruned` audit row
 * per run, with the per-model counts in `metadata` (SECURITY.md §9.1: "A
 * pruning job ran, with counts in metadata").
 *
 * Safety (SECURITY.md §9.3, AGENTS.md §11):
 *  - only model class short-names and integer counts are recorded — never a
 *    secret, a monitored URL, or captured content;
 *  - the write is best-effort: a failure is reported, never rethrown, so an
 *    audit failure can never break the pruning run (AGENTS.md §12 principle).
 */
final class PruningAuditRecorder
{
    /** @var array<string, int> */
    private array $counts = [];

    public function onStarting(ModelPruningStarting $event): void
    {
        $this->counts = [];
    }

    public function onModelsPruned(ModelsPruned $event): void
    {
        $model = class_basename($event->model);
        $this->counts[$model] = ($this->counts[$model] ?? 0) + (int) $event->count;
    }

    public function onFinished(ModelPruningFinished $event): void
    {
        $counts = $this->counts;

        try {
            AuditLog::create([
                'user_id' => null,
                'event' => AuditEvent::RETENTION_PRUNED,
                'subject_type' => null,
                'subject_id' => null,
                'ip_address' => null,
                'user_agent' => 'artisan:model:prune',
                'metadata' => [
                    'models' => $counts,
                    'total' => array_sum($counts),
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        } finally {
            $this->counts = [];
        }
    }
}
