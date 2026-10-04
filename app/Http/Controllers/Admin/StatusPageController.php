<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatusPageRequest;
use App\Models\StatusPage;
use App\Models\Website;
use App\Services\Audit\AuditLogger;
use App\Services\StatusPage\StatusPageAnalytics;
use App\Services\StatusPage\StatusPageCache;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Status page admin CRUD (Phase 11, ADR-031, STATUS-PAGE.md §11.4).
 *
 * All routes are behind auth + admin. Deleting a page never deletes websites:
 * `websites.status_page_id` is ON DELETE SET NULL, so affected websites fall
 * back to the default page. The default/first page cannot be deleted.
 */
final class StatusPageController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusPageAnalytics $analytics,
    ) {}

    public function index(Request $request): View
    {
        $query = StatusPage::query()
            ->withCount('websites')
            ->orderByDesc('is_default')
            ->orderBy('name');

        $pages = $query
            ->paginate(PerPage::sizeFor($query, $request))
            ->withQueryString();

        return view('admin.status-pages.index', compact('pages'));
    }

    /**
     * Internal analytics report for one status page (Requirement 23).
     *
     * Admin-only (the route group enforces auth + session timeouts + admin).
     * All aggregation lives in {@see StatusPageAnalytics}; this method only
     * validates the reporting period and hands the report to the view
     * (AGENTS.md §7). The period is allowlisted — unknown values fall back to
     * the default, never an error.
     */
    public function analytics(Request $request, StatusPage $statusPage): View
    {
        $period = StatusPageAnalytics::resolvePeriod($request->query('period'));

        return view('admin.status-pages.analytics', [
            'page' => $statusPage,
            'analytics' => $this->analytics->report($statusPage, $period),
            'periods' => StatusPageAnalytics::periods(),
        ]);
    }

    public function create(): View
    {
        return view('admin.status-pages.form', [
            'page' => null,
            'websites' => $this->assignableWebsites(),
            'selectedIds' => [],
        ]);
    }

    public function store(StatusPageRequest $request, StatusPageCache $cache): RedirectResponse
    {
        $data = $request->validated();

        $page = DB::transaction(function () use ($request, $data): StatusPage {
            $page = new StatusPage;
            $this->applyData($page, $data, $request);

            if (! empty($data['is_default'])) {
                StatusPage::query()->where('is_default', true)->update(['is_default' => false]);
                $page->is_default = true;
            } elseif (! StatusPage::query()->where('is_default', true)->exists()) {
                // Always keep exactly one default page.
                $page->is_default = true;
            }

            $page->save();

            $this->syncWebsites($page, $data['published'] ?? null);

            return $page;
        });

        $this->audit->log('status_page.created', $request->user(), $page);
        $cache->invalidate();

        return redirect()
            ->route('admin.status-pages.index')
            ->with('status', __('Status page created.'));
    }

    public function edit(StatusPage $statusPage): View
    {
        return view('admin.status-pages.form', [
            'page' => $statusPage,
            'websites' => $this->assignableWebsites(),
            'selectedIds' => $statusPage->websites()->pluck('id')->all(),
        ]);
    }

    public function update(StatusPageRequest $request, StatusPage $statusPage, StatusPageCache $cache): RedirectResponse
    {
        $data = $request->validated();
        $previousMode = (string) $statusPage->visibility_mode;

        DB::transaction(function () use ($request, $statusPage, $data): void {
            $this->applyData($statusPage, $data, $request);

            if (! empty($data['is_default']) && ! $statusPage->is_default) {
                StatusPage::query()->where('is_default', true)->update(['is_default' => false]);
                $statusPage->is_default = true;
            }

            $statusPage->save();

            $this->syncWebsites($statusPage, $data['published'] ?? null);
        });

        $statusPage->refresh();

        $this->audit->log('status_page.visibility.changed', $request->user(), $statusPage, [
            'from' => $previousMode,
            'to' => (string) $statusPage->visibility_mode,
        ]);
        $cache->invalidate($statusPage);

        return redirect()
            ->route('admin.status-pages.edit', $statusPage)
            ->with('status', __('Status page saved.'));
    }

    public function destroy(Request $request, StatusPage $statusPage, StatusPageCache $cache): RedirectResponse
    {
        // Delete safety (ADR-031, STATUS-PAGE.md §11.4): never the default page,
        // and never the last remaining page — there must always be a fallback.
        if ($statusPage->is_default) {
            return back()->withErrors([
                'status_page' => __('The default status page cannot be deleted. Make another page the default first.'),
            ]);
        }

        if (StatusPage::query()->count() <= 1) {
            return back()->withErrors([
                'status_page' => __('The last status page cannot be deleted.'),
            ]);
        }

        $auditSubject = $statusPage;
        DB::transaction(function () use ($statusPage): void {
            // Websites are released first so the fallback is deterministic even
            // on drivers that do not honour ON DELETE SET NULL immediately.
            Website::query()->where('status_page_id', $statusPage->id)->update(['status_page_id' => null]);
            $statusPage->delete();
        });

        $this->audit->log('status_page.deleted', $request->user(), $auditSubject);
        $cache->invalidate();

        return redirect()
            ->route('admin.status-pages.index')
            ->with('status', __('Status page deleted. Its websites now fall back to the default page.'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyData(StatusPage $page, array $data, Request $request): void
    {
        $page->name = (string) $data['name'];
        $page->slug = trim((string) $data['slug']);
        $page->visibility_mode = (string) $data['visibility_mode'];
        $page->created_by = $page->exists ? $page->created_by : $request->user()?->id;

        if (! empty($data['clear_password'])) {
            $page->password_hash = null;
        } elseif (isset($data['password']) && $data['password'] !== null && $data['password'] !== '') {
            $page->password_hash = Hash::make((string) $data['password']);
        }
    }

    /**
     * @param  list<int>|null  $published
     */
    private function syncWebsites(StatusPage $page, ?array $published): void
    {
        $ids = array_values(array_unique(array_map('intval', $published ?? [])));

        $valid = $ids === []
            ? []
            : Website::query()->whereIn('id', $ids)->pluck('id')->all();

        // Selecting a website on a page both assigns it (status_page_id) and
        // publishes it (is_visible_on_status). The projection filters on BOTH
        // (StatusProjector::publishedQuery), so assigning without publishing
        // left the website invisible on every page (STATUS-PAGE.md §4).
        if ($valid !== []) {
            Website::query()->whereIn('id', $valid)->update([
                'status_page_id' => $page->id,
                'is_visible_on_status' => true,
            ]);
        }

        // Websites removed from the selection fall back to the default page.
        $release = Website::query()
            ->where('status_page_id', $page->id)
            ->when($valid !== [], fn ($q) => $q->whereNotIn('id', $valid));

        $release->update(['status_page_id' => null]);
    }

    /**
     * Websites selectable on a page assignment form, with their current page id.
     *
     * @return Collection<int, Website>
     */
    private function assignableWebsites(): Collection
    {
        return Website::query()
            ->orderBy('name')
            ->get(['id', 'name', 'status_page_id', 'is_visible_on_status']);
    }
}
