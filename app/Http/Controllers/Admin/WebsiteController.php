<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebsiteRequest;
use App\Http\Requests\UpdateWebsiteRequest;
use App\Models\Website;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Monitored website CRUD (PLAN.md Phase 3).
 */
final class WebsiteController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit
    ) {}

    public function index(): View
    {
        $websites = Website::query()
            ->withTrashed(false) // only active (not soft-deleted)
            ->orderBy('name')
            ->get();

        return view('admin.websites.index', compact('websites'));
    }

    public function create(): View
    {
        return view('admin.websites.form', ['website' => null]);
    }

    public function store(StoreWebsiteRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $data = $this->prepareData($validated);

        $website = Website::query()->create($data);

        $this->audit->log('website.created', $request->user(), $website);

        return redirect()
            ->route('admin.websites.index')
            ->with('status', __('Website created.'));
    }

    public function edit(Website $website): View
    {
        return view('admin.websites.form', compact('website'));
    }

    public function update(UpdateWebsiteRequest $request, Website $website): RedirectResponse
    {
        $validated = $request->validated();
        $data = $this->prepareData($validated, $website);

        $website->update($data);

        $this->audit->log('website.updated', $request->user(), $website);

        return redirect()
            ->route('admin.websites.index')
            ->with('status', __('Website updated.'));
    }

    public function destroy(Request $request, Website $website): RedirectResponse
    {
        $this->audit->log('website.deleted', $request->user(), $website);

        $website->delete();

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

        return redirect()
            ->route('admin.websites.index')
            ->with('status', __('Website status updated.'));
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
}
