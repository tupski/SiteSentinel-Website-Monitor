<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Audit\AuditLogger;
use App\Services\Incidents\IncidentStateMachine;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationIntents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Incident lifecycle UI (PLAN.md Phase 6).
 *
 * Business rules live in IncidentStateMachine; this controller only
 * orchestrates (AGENTS.md §7). Every transition is audited with the actor.
 */
final class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentStateMachine $stateMachine,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $incidents = Incident::query()
            ->with(['website', 'acknowledgedBy', 'resolvedBy'])
            ->when($request->filled('status') && $this->isValidStatus((string) $request->query('status')), function ($query) use ($request): void {
                $query->where('status', (string) $request->query('status'));
            })
            ->when($request->filled('severity') && $this->isValidSeverity((string) $request->query('severity')), function ($query) use ($request): void {
                $query->where('severity', (string) $request->query('severity'));
            })
            ->when($request->filled('type') && $this->isValidType((string) $request->query('type')), function ($query) use ($request): void {
                $query->where('type', (string) $request->query('type'));
            })
            ->when($request->filled('website_id') && is_numeric($request->query('website_id')), function ($query) use ($request): void {
                $query->where('website_id', (int) $request->query('website_id'));
            })
            ->orderByDesc('detected_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.incidents.index', [
            'incidents' => $incidents,
            'statuses' => [IncidentStateMachine::DETECTED, IncidentStateMachine::ACKNOWLEDGED, IncidentStateMachine::RESOLVED],
            'severities' => ['INFO', 'WARNING', 'CRITICAL'],
            'types' => ['availability', 'security'],
        ]);
    }

    public function show(Incident $incident): View
    {
        $incident->load(['website', 'acknowledgedBy', 'resolvedBy', 'events.actor', 'snapshots', 'notificationLogs.channel']);

        $deliveryLogs = $incident->notificationLogs->sortByDesc('id');

        return view('admin.incidents.show', ['incident' => $incident, 'deliveryLogs' => $deliveryLogs]);
    }

    public function acknowledge(Request $request, Incident $incident): RedirectResponse
    {
        $acknowledged = $this->stateMachine->acknowledge($incident, $request->user());

        $this->audit->log('incident.acknowledged', $request->user(), $acknowledged, [
            'from_status' => IncidentStateMachine::DETECTED,
            'to_status' => IncidentStateMachine::ACKNOWLEDGED,
        ]);

        try {
            NotificationIntents::enqueue($acknowledged->id, NotificationDispatcher::EVENT_ACKNOWLEDGED, $request->user()?->getKey());
        } catch (Throwable $e) {
            report($e);
        }

        return back()->with('status', __('Incident acknowledged.'));
    }

    public function resolve(Request $request, Incident $incident): RedirectResponse
    {
        $data = $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $resolved = $this->stateMachine->resolveManually($incident, $request->user(), $data['resolution_notes'] ?? null);

        $this->audit->log('incident.resolved', $request->user(), $resolved, [
            'resolution_mode' => 'manual',
            'resolution_notes' => $data['resolution_notes'] ?? null,
        ]);

        try {
            NotificationIntents::enqueue($resolved->id, NotificationDispatcher::EVENT_RESOLVED, $request->user()?->getKey());
        } catch (Throwable $e) {
            report($e);
        }

        return back()->with('status', __('Incident resolved.'));
    }

    private function isValidStatus(string $value): bool
    {
        return in_array($value, [IncidentStateMachine::DETECTED, IncidentStateMachine::ACKNOWLEDGED, IncidentStateMachine::RESOLVED], true);
    }

    private function isValidSeverity(string $value): bool
    {
        return in_array($value, ['INFO', 'WARNING', 'CRITICAL'], true);
    }

    private function isValidType(string $value): bool
    {
        return in_array($value, ['availability', 'security'], true);
    }
}
