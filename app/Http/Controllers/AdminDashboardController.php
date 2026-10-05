<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Check;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Website;
use App\Services\Health\SystemHealth;
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
    /**
     * Maximum rows the Availability / Security & Content Health tables render.
     * Beyond this the section defers to the full list page via a "View all"
     * link (the total is exposed separately so the view stays presentational).
     */
    private const TABLE_LIMIT = 10;

    public function __invoke(SystemHealth $health): View
    {
        // Counters are computed with real queries (never from the capped table
        // collection) so the four summary numbers always reflect the full fleet,
        // even when more than TABLE_LIMIT websites exist.
        $websiteCount = Website::query()->count();
        $operationalCount = Website::query()->where('status_availability', 'UP')->count();

        // The Availability / Security & Content Health tables share the fleet,
        // ordered by name and capped at TABLE_LIMIT. The total drives the
        // "View all" affordance and is passed separately from the rows.
        $websites = Website::query()
            ->orderBy('name')
            ->limit(self::TABLE_LIMIT)
            ->get();

        // AC-21: expose DB / Redis / queue-worker readiness on the dashboard so a
        // stalled monitoring pipeline is visible to Admin without hitting /health.
        $healthChecks = $health->checks();

        $counters = [
            'total' => $websiteCount,
            'operational' => $operationalCount,
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
            // Already capped at TABLE_LIMIT; `websiteTotal` is the uncapped fleet
            // size so the Availability / Security tables can decide whether to
            // render the "View all" link without re-querying in the view.
            'websites' => $websites,
            'websiteTotal' => $websiteCount,
            'health' => $healthChecks,
            'healthHealthy' => $health->isHealthy($healthChecks),
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
