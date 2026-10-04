<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStatusPageSettingsRequest;
use App\Models\StatusPage;
use App\Models\Website;
use App\Services\Audit\AuditLogger;
use App\Services\StatusPage\StatusPageCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Legacy Phase 8 singleton settings screen (DATABASE.md §3.18), retained for
 * one release. It now edits the default {@see StatusPage} and its website
 * assignment — full multi-page management lives at /admin/status-pages.
 */
final class StatusPageSettingController extends Controller
{
    public function edit()
    {
        $settings = StatusPage::resolveDefault();

        $websites = Website::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_visible_on_status', 'status_alias', 'status_page_id']);

        $publishedIds = $settings->websites()->pluck('id')->all();

        return view('admin.status-settings.form', [
            'settings' => $settings,
            'websites' => $websites,
            'publishedIds' => $publishedIds,
        ]);
    }

    public function update(UpdateStatusPageSettingsRequest $request, AuditLogger $audit, StatusPageCache $cache)
    {
        $settings = StatusPage::resolveDefault();
        $data = $request->validated();

        $previousMode = (string) $settings->visibility_mode;
        $settings->visibility_mode = (string) $data['visibility_mode'];

        $slug = isset($data['slug']) ? trim((string) $data['slug']) : '';
        if ($slug !== '') {
            $settings->slug = $slug;
        }

        if (! empty($data['clear_password'])) {
            $settings->password_hash = null;
        } elseif (! empty($data['password'])) {
            $settings->password_hash = Hash::make((string) $data['password']);
        }

        // Fail-closed: password mode must keep a hash.
        if ($settings->visibility_mode === StatusPage::MODE_PASSWORD_PROTECTED && ! $settings->hasUsablePassword()) {
            return back()->withErrors(['password' => 'Password Protected mode requires a password.'])->withInput();
        }

        $settings->save();

        // Per-website publish + alias (display mapping only) on the default page.
        $published = array_map('intval', (array) ($data['published'] ?? []));
        $aliases = (array) ($data['aliases'] ?? []);

        DB::transaction(function () use ($settings, $published, $aliases): void {
            Website::query()->where('status_page_id', $settings->id)->update(['status_page_id' => null]);

            foreach (Website::query()->get(['id', 'is_visible_on_status', 'status_alias']) as $website) {
                $id = (int) $website->id;
                $website->is_visible_on_status = in_array($id, $published, true);
                $website->status_page_id = in_array($id, $published, true) ? $settings->id : null;
                $alias = isset($aliases[$id]) ? trim((string) $aliases[$id]) : '';
                $website->status_alias = $alias !== '' ? mb_substr($alias, 0, 255) : null;
                $website->save();
            }
        });

        $audit->log('status_page.visibility.changed', $request->user(), $settings, [
            'from' => $previousMode,
            'to' => (string) $settings->visibility_mode,
        ]);

        $settings->refresh();
        $cache->invalidate($settings);

        return redirect()->route('admin.status-settings.edit')->with('status', 'Status page settings saved.');
    }
}
