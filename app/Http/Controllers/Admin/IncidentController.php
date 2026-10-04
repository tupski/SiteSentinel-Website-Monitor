<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Snapshot;
use App\Services\Audit\AuditLogger;
use App\Services\Incidents\IncidentStateMachine;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationIntents;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        private readonly AdminNotificationService $adminNotifications,
    ) {}

    public function index(Request $request): View
    {
        // Requirement 31: `open=1` restricts to the incident states that the
        // dashboard counters count (DETECTED/ACKNOWLEDGED), so the "Warning" /
        // "Critical" card deep-links land on a list whose total equals the card.
        // The `status` filter (when present and valid) takes precedence, since a
        // resolved severity bucket is a deliberate operator choice.
        $openOnly = $request->query('open') === '1';
        $hasValidStatus = $request->filled('status') && $this->isValidStatus((string) $request->query('status'));

        $query = Incident::query()
            ->with(['website', 'acknowledgedBy', 'resolvedBy'])
            ->when($hasValidStatus, function ($query) use ($request): void {
                $query->where('status', (string) $request->query('status'));
            })
            ->when($openOnly && ! $hasValidStatus, function ($query): void {
                $query->whereIn('status', [IncidentStateMachine::DETECTED, IncidentStateMachine::ACKNOWLEDGED]);
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
            ->orderByDesc('detected_at');

        $incidents = $query
            ->paginate(PerPage::sizeFor($query, $request))
            ->withQueryString();

        return view('admin.incidents.index', [
            'incidents' => $incidents,
            'statuses' => [IncidentStateMachine::DETECTED, IncidentStateMachine::ACKNOWLEDGED, IncidentStateMachine::RESOLVED],
            'severities' => ['INFO', 'WARNING', 'CRITICAL'],
            'types' => ['availability', 'security'],
            'openOnly' => $openOnly,
        ]);
    }

    public function show(Incident $incident): View
    {
        $incident->load(['website', 'acknowledgedBy', 'resolvedBy', 'events.actor', 'snapshots', 'notificationLogs.channel']);

        $deliveryLogs = $incident->notificationLogs->sortByDesc('id');

        return view('admin.incidents.show', ['incident' => $incident, 'deliveryLogs' => $deliveryLogs]);
    }

    /**
     * Serve one evidence snapshot's captured HTML for the incident detail modal.
     *
     * The bytes are untrusted (they were captured from a monitored external
     * site), so they are never rendered as trusted markup here (AGENTS.md §11).
     * The response is a sandboxed document: `Content-Security-Policy:
     * sandbox` + `X-Content-Type-Options: nosniff` neutralise scripts, plugins,
     * forms and same-origin access even if the admin previews it outside the
     * `<iframe sandbox>` the view uses. Delivery is scoped to the incident so a
     * snapshot can never be read through another incident (IDOR).
     */
    public function snapshot(Incident $incident, Snapshot $snapshot): Response
    {
        // Ownership: the snapshot must belong to the incident in the URL.
        if ((int) $snapshot->incident_id !== (int) $incident->getKey()) {
            throw new NotFoundHttpException;
        }

        $path = (string) ($snapshot->html_path ?? '');
        $disk = Storage::disk((string) config('sentinel.snapshots_disk', 'local'));

        if ($path === '' || ! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        return response((string) $disk->get($path), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'",
            'Cache-Control' => 'no-store, private',
        ]);
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

        // In-app notification is additive and independent of outbound delivery
        // (ADR-038). Best-effort: never block the resolve response.
        try {
            $this->adminNotifications->recordIncidentTransition($resolved, 'resolved');
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
