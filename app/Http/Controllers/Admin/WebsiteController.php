<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkWebsiteActionRequest;
use App\Http\Requests\StoreWebsiteRequest;
use App\Http\Requests\UpdateWebsiteRequest;
use App\Jobs\RunWebsiteCheck;
use App\Models\NotificationChannel;
use App\Models\Website;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\AdminNotificationService;
use App\Services\StatusPage\StatusPageCache;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Monitored website CRUD (PLAN.md Phase 3).
 *
 * Every mutation that can change a projection input (name/alias, `is_active`,
 * `is_visible_on_status`, cadence) invalidates the status-page projection cache
 * so the public page never serves a stale row until the TTL expires
 * (STATUS-PAGE.md §9.2).
 */
final class WebsiteController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusPageCache $statusPageCache,
        private readonly AdminNotificationService $adminNotifications,
    ) {}

    /**
     * Availability values accepted by the `status` filter. Mirrors the
     * `websites.status_availability` ENUM plus `unknown` for the NULL case.
     * Never trust the raw query value — anything outside this set is ignored
     * (the list falls back to unfiltered), matching the incident filter idiom.
     */
    private const AVAILABILITY_FILTERS = ['UP', 'DOWN', 'unknown'];

    public function index(Request $request): View
    {
        $statusFilter = $this->availabilityFilter($request);

        $query = Website::query()
            ->withTrashed(false) // only active (not soft-deleted)
            // Eager-load the assigned status page so the association column
            // renders without an N+1 (one query per page, not per row).
            ->with('statusPage')
            // Requirement 31: operational = `status_availability === 'UP'` (NOT
            // `is_active`). Filtering happens on the real query so the list total
            // equals the dashboard "Operational" counter.
            ->when($statusFilter === 'UP', function ($query): void {
                $query->where('status_availability', 'UP');
            })
            ->when($statusFilter === 'DOWN', function ($query): void {
                $query->where('status_availability', 'DOWN');
            })
            ->when($statusFilter === 'unknown', function ($query): void {
                $query->whereNull('status_availability');
            })
            ->orderBy('name');

        $websites = $query
            ->paginate(PerPage::sizeFor($query, $request))
            ->withQueryString();

        return view('admin.websites.index', [
            'websites' => $websites,
            'statusFilter' => $statusFilter,
        ]);
    }

    /**
     * Resolve a whitelisted availability filter from the request. Unknown or
     * malformed values resolve to null (no filter applied).
     */
    private function availabilityFilter(Request $request): ?string
    {
        $raw = $request->query('status');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return in_array($raw, self::AVAILABILITY_FILTERS, true) ? $raw : null;
    }

    public function create(): View
    {
        return view('admin.websites.form', [
            'website' => null,
            'channels' => NotificationChannel::query()->orderBy('name')->get(['id', 'type', 'name', 'enabled']),
            'selectedChannels' => [],
        ]);
    }

    public function store(StoreWebsiteRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $data = $this->prepareData($validated);

        $website = Website::query()->create($data);
        $this->syncChannels($website, $validated['channel_ids'] ?? null);

        $this->audit->log('website.created', $request->user(), $website);
        $this->statusPageCache->invalidate();

        $this->adminNotifications->recordMonitoringConfigChanged(
            'created',
            'Website '.$website->name.' was added to monitoring.',
            null,
            (int) $website->id,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.websites.index')
            ->with('status', __('Website created.'));
    }

    public function edit(Website $website): View
    {
        $website->load('notificationChannels:id');

        return view('admin.websites.form', [
            'website' => $website,
            'channels' => NotificationChannel::query()->orderBy('name')->get(['id', 'type', 'name', 'enabled']),
            'selectedChannels' => $website->notificationChannels->pluck('id')->all(),
        ]);
    }

    public function update(UpdateWebsiteRequest $request, Website $website): RedirectResponse
    {
        $validated = $request->validated();
        $data = $this->prepareData($validated, $website);

        $website->update($data);
        $this->syncChannels($website, $validated['channel_ids'] ?? null);

        $this->audit->log('website.updated', $request->user(), $website);
        // A website edit can change every projection input the page reads
        // (name/alias, is_active, is_visible_on_status, cadence). Bust the
        // cached projection so the public page cannot serve a stale row
        // (STATUS-PAGE.md §9.2).
        $this->statusPageCache->invalidate();

        $this->adminNotifications->recordMonitoringConfigChanged(
            'updated',
            'Monitoring settings for '.$website->name.' were updated.',
            null,
            (int) $website->id,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.websites.index')
            ->with('status', __('Website updated.'));
    }

    public function destroy(Request $request, Website $website): RedirectResponse
    {
        $this->audit->log('website.deleted', $request->user(), $website);

        $name = $website->name;
        $websiteId = (int) $website->id;
        $website->delete();

        // Removing a website must drop it from the public page immediately,
        // not at the TTL (STATUS-PAGE.md §9.2).
        $this->statusPageCache->invalidate();

        $this->adminNotifications->recordMonitoringConfigChanged(
            'deleted',
            'Website '.$name.' was removed from monitoring.',
            null,
            $websiteId,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.websites.index')
            ->with('status', __('Website deleted.'));
    }

    /**
     * Toggle is_active flag (enable/disable monitoring).
     */
    public function toggle(Request $request, Website $website): RedirectResponse
    {
        $website->update(['is_active' => ! $website->is_active]);

        $this->audit->log('website.toggled', $request->user(), $website, [
            'is_active' => $website->is_active,
        ]);

        // `is_active` gates publication (StatusProjector::publishedQuery), so
        // toggling monitoring on/off must refresh the cached projection.
        $this->statusPageCache->invalidate();

        $this->adminNotifications->recordMonitoringConfigChanged(
            $website->is_active ? 'enabled' : 'disabled',
            'Monitoring for '.$website->name.' was '.($website->is_active ? 'enabled' : 'disabled').'.',
            null,
            (int) $website->id,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.websites.index')
            ->with('status', __('Website status updated.'));
    }

    /**
     * Manually enqueue a monitoring check for one website.
     *
     * The request path queues only — it never performs an outbound probe
     * (AGENTS.md §9; invariant "monitoring stays out of the request path").
     * The per-website lock is enforced by the job itself, so overlapping checks
     * stay protected. Job internals are never exposed to the caller.
     */
    public function runCheck(Request $request, Website $website): RedirectResponse
    {
        RunWebsiteCheck::dispatch($website);

        $this->audit->log('website.check_queued', $request->user(), $website);

        return redirect()
            ->route('admin.websites.index')
            ->with('status', __('Check queued for “:name”.', ['name' => $website->name]));
    }

    /**
     * Bulk enable selected websites.
     */
    public function bulkEnable(BulkWebsiteActionRequest $request): RedirectResponse
    {
        return $this->bulkUpdateActive($request, true);
    }

    /**
     * Bulk disable selected websites.
     */
    public function bulkDisable(BulkWebsiteActionRequest $request): RedirectResponse
    {
        return $this->bulkUpdateActive($request, false);
    }

    /**
     * Bulk delete selected websites.
     *
     * Website rows are soft-deleted (DATABASE.md §7); incident history is
     * append-only and telemetry retention is governed by its own windows, so the
     * soft delete never orphans a FK and never cascades hard-deletes.
     */
    public function bulkDelete(BulkWebsiteActionRequest $request): RedirectResponse
    {
        $websites = Website::query()->whereKey($request->selectedIds())->get();

        if ($websites->isEmpty()) {
            return redirect()
                ->route('admin.websites.index')
                ->with('status', __('No websites selected.'));
        }

        foreach ($websites as $website) {
            $this->audit->log('website.deleted', $request->user(), $website);
            $website->delete();
        }

        $this->statusPageCache->invalidate();

        $this->adminNotifications->recordMonitoringConfigChanged(
            'bulk_deleted',
            trans_choice(
                '{1} A website was removed from monitoring.|[2,*] :count websites were removed from monitoring.',
                $websites->count(),
            ),
            null,
            null,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.websites.index')
            ->with('status', trans_choice(
                '{0} No websites selected.|{1} Website deleted.|[2,*] :count websites deleted.',
                $websites->count(),
            ));
    }

    /**
     * Apply an enable/disable decision to the selected websites only.
     */
    private function bulkUpdateActive(BulkWebsiteActionRequest $request, bool $active): RedirectResponse
    {
        $websites = Website::query()->whereKey($request->selectedIds())->get();

        if ($websites->isEmpty()) {
            return redirect()
                ->route('admin.websites.index')
                ->with('status', __('No websites selected.'));
        }

        $changed = false;

        foreach ($websites as $website) {
            if ($website->is_active === $active) {
                continue; // idempotent: already in the requested state
            }

            $website->update(['is_active' => $active]);
            $changed = true;

            $this->audit->log('website.toggled', $request->user(), $website, [
                'is_active' => $active,
            ]);
        }

        if ($changed) {
            // `is_active` gates publication (StatusProjector::publishedQuery).
            $this->statusPageCache->invalidate();

            $this->adminNotifications->recordMonitoringConfigChanged(
                $active ? 'bulk_enabled' : 'bulk_disabled',
                trans_choice(
                    '{1} A website was :state.|[2,*] :count websites were :state.',
                    $websites->count(),
                    ['state' => $active ? 'enabled for monitoring' : 'disabled for monitoring'],
                ),
                null,
                null,
                $request->user()?->getKey(),
            );
        }

        return redirect()
            ->route('admin.websites.index')
            ->with('status', trans_choice(
                '{0} No websites selected.|{1} Website status updated.|[2,*] :count websites updated.',
                $websites->count(),
            ));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function prepareData(array $validated, ?Website $existing = null): array
    {
        // The WebsiteRequest passedValidation hook already merged scheme + host.
        $data = [
            'name' => $validated['name'],
            'url' => $validated['url'] ?? $existing?->url,
            'scheme' => $validated['scheme'] ?? $existing?->scheme,
            'host' => $validated['host'] ?? $existing?->host,
            'is_active' => $validated['is_active'] ?? true,
            'check_interval_seconds' => $validated['check_interval_seconds'],
            'timeout_seconds' => $validated['timeout_seconds'],
            'expected_status' => $validated['expected_status'],
            'expected_title' => $validated['expected_title'] ?? null,
            'expected_final_domain' => $validated['expected_final_domain'] ?? null,
            'follow_redirects' => $validated['follow_redirects'] ?? true,
            'note' => $validated['note'] ?? null,
            'monitor_ssl' => $validated['monitor_ssl'] ?? true,
            'monitor_redirects' => $validated['monitor_redirects'] ?? true,
            'monitor_content' => $validated['monitor_content'] ?? true,
            'monitor_security' => $validated['monitor_security'] ?? true,
        ];

        // When updating and the URL was not supplied, keep the existing normalized values.
        if ($existing !== null && ! isset($validated['url'])) {
            $data['url'] = $existing->url;
            $data['scheme'] = $existing->scheme;
            $data['host'] = $existing->host;
        }

        return $data;
    }

    /**
     * Absence rule: null/empty selection detaches all rows (all enabled channels).
     *
     * @param  list<int>|null  $channelIds
     */
    private function syncChannels(Website $website, ?array $channelIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $channelIds ?? [])));

        if ($ids === []) {
            $website->notificationChannels()->detach();

            return;
        }

        $valid = NotificationChannel::query()->whereIn('id', $ids)->pluck('id')->all();
        $website->notificationChannels()->sync($valid);
    }
}
