<?php

declare(strict_types=1);

namespace App\Services\Detection;

use App\Models\Check;
use App\Models\Snapshot;
use Illuminate\Support\Facades\Storage;

final class SnapshotWriter
{
    public function capture(Check $check, array $html, array $headers): Snapshot
    {
        $disk = Storage::disk('local');
        $path = "snapshots/{$check->website_id}/{$check->id}.html";
        $disk->put($path, $html['body'] ?? '');

        return Snapshot::create([
            'website_id' => $check->website_id,
            'check_id' => $check->id,
            'html_path' => $disk->path($path),
            'headers' => $headers,
            'final_url' => $check->final_url,
            'title' => $check->title,
            'captured_at' => now(),
            'expires_at' => now()->addDays((int) config('sentinel.retention.snapshots_days', 14)),
        ]);
    }
}
