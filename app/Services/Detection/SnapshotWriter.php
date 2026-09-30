<?php

declare(strict_types=1);

namespace App\Services\Detection;

use App\Models\Check;
use App\Models\Snapshot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Evidence snapshot writer (DATABASE.md §3.12, DECISIONS.md ADR-015).
 *
 * Canonical storage: HTML lives on the filesystem and `snapshots.html_path`
 * records a **disk-relative** path (never a machine-specific absolute path).
 *
 * Reliability (AGENTS.md §9): a snapshot failure is a monitoring *side effect*
 * failure. It is logged and reported, and it must never destroy the check that
 * produced it — callers run this outside the check's write transaction.
 */
final class SnapshotWriter
{
    /** Upper bound on the HTML persisted per snapshot (SECURITY.md §6). */
    private const MAX_SNAPSHOT_BYTES = 2 * 1024 * 1024;

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $extra
     */
    public function capture(Check $check, string $html, array $headers = [], array $extra = []): ?Snapshot
    {
        try {
            $path = $this->relativePath($check);
            $body = mb_substr($html, 0, self::MAX_SNAPSHOT_BYTES);

            Storage::disk($this->disk())->put($path, $body);

            return Snapshot::create([
                'website_id' => $check->website_id,
                'check_id' => $check->id,
                'html_path' => $path,
                'headers' => $this->sanitizeHeaders($headers),
                'final_url' => $check->final_url,
                'title' => $check->title,
                'keywords' => $extra['keywords'] ?? null,
                'external_links' => $extra['external_links'] ?? null,
                'redirect_chain' => $check->redirect_chain,
                'size_bytes' => strlen($body),
                'captured_at' => now(),
                'expires_at' => now()->addDays((int) config('sentinel.retention.snapshots_days', 14)),
            ]);
        } catch (Throwable $e) {
            // Observable, never silent, never fatal to the monitoring check.
            Log::warning('Snapshot capture failed', [
                'website_id' => $check->website_id,
                'check_id' => $check->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Disk-relative path — no absolute filesystem path, no traversal
     * (SECURITY.md, DATABASE.md §3.12).
     */
    private function relativePath(Check $check): string
    {
        return sprintf('snapshots/%d/%d.html', $check->website_id, $check->id);
    }

    private function disk(): string
    {
        $disk = config('sentinel.snapshots_disk', 'local');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }

    /**
     * Strip credential-bearing headers before storage.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $blocked = ['set-cookie', 'authorization', 'proxy-authorization'];

        $clean = [];
        foreach ($headers as $name => $value) {
            if (in_array(mb_strtolower((string) $name), $blocked, true)) {
                continue;
            }
            $clean[$name] = $value;
        }

        return $clean;
    }
}
