<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Single write path for the audit trail (PLAN.md Phase 2, DATABASE.md §3.19).
 *
 * Writes are best-effort with respect to the caller: an audit failure must
 * never break the action being audited (same principle as AGENTS.md §12 for
 * notifications). Failures are logged, never rethrown.
 */
final class AuditLogger
{
    public function __construct(
        private readonly Request $request
    ) {}

    /**
     * Record an audit event.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        string $event,
        ?User $user = null,
        ?object $subject = null,
        ?array $metadata = null,
    ): ?AuditLog {
        try {
            return AuditLog::create([
                'user_id' => $user?->getKey(),
                'event' => mb_substr($event, 0, 64),
                'subject_type' => $subject !== null ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'ip_address' => mb_substr((string) $this->request->ip(), 0, 45),
                'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 65535),
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
