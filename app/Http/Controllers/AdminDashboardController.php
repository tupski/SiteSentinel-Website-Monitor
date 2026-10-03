<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Check;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Admin dashboard (PLAN.md Phase 2 shell + Phase 6 counters/timeline, AC-6-07).
 *
 * Counters expose the canonical four numbers — total / operational / warning /
 * incident — and the timeline interleaves check results with incident
 * lifecycle events (FR-61). Availability and security are NEVER merged into a
 * single status (AGENTS.md §15.2).
 */
final class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        $websites = Website::query()->orderBy('name')->get();

        $counters = [
            'total' => $websites->count(),
            'operational' => $websites->where('status_availability', 'UP')->count(),
            'warning' => Incident::query()
                ->whereIn('status', ['DETECTED', 'ACKNOWLEDGED'])
                ->where('severity', 'WARNING')
                ->count(),
            'incident' => Incident::query()
                ->whereIn('status', ['DETECTED', 'ACKNOWLEDGED'])
                ->where('severity', 'CRITICAL')
                ->count(),
        ];

        // FR-101: failure visibility reuses counter style, no charting.
        $failedLogs = NotificationLog::query()->where('status', 'failed')->orderByDesc('id')->limit(5)->with('channel:id,name,type')->get();

        return view('admin.dashboard', [
            'counters' => $counters,
            'websites' => $websites,
            'openIncidents' => Incident::query()
                ->with('website')
                ->whereIn('status', ['DETECTED', 'ACKNOWLEDGED'])
                ->orderByDesc('detected_at')
                ->limit(10)
                ->get(),
            'timeline' => $this->timeline(),
            'failedNotificationCount' => NotificationLog::query()->where('status', 'failed')->count(),
            'failedNotifications' => $failedLogs,
            'disabledChannels' => NotificationChannel::query()->where('enabled', false)->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * Timeline interleaving check results and incident lifecycle events
     * (FR-61, AC-6-07).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function timeline(): Collection
    {
        $checks = Check::query()
            ->with('website:id,name')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Check $check): array => [
                'kind' => 'check',
                'at' => $check->finished_at ?? $check->started_at,
                'website' => $check->website?->name,
                'availability_state' => $check->availability_state,
                'security_state' => $check->security_state,
                'http_status' => $check->http_status,
                'score' => $check->score,
            ]);

        $incidents = Incident::query()
            ->with('website:id,name')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Incident $incident): array => [
                'kind' => 'incident',
                'at' => $incident->detected_at,
                'website' => $incident->website?->name,
                'status' => $incident->status,
                'severity' => $incident->severity,
                'type' => $incident->type,
            ]);

        return $checks
            ->concat($incidents)
            ->sortByDesc(fn (array $row) => $row['at']?->getTimestamp() ?? 0)
            ->values()
            ->take(30);
    }
}
