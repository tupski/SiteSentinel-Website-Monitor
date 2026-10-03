<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStatusPageSettingsRequest;
use App\Models\StatusPageSetting;
use App\Models\Website;
use App\Services\Audit\AuditLogger;
use App\Services\StatusPage\StatusPageCache;
use Illuminate\Support\Facades\Hash;

final class StatusPageSettingController extends Controller
{
    public function edit()
    {
        $settings = StatusPageSetting::singleton();
        $websites = Website::query()->orderBy('name')->get(['id', 'name', 'is_visible_on_status', 'status_alias']);

        return view('admin.status-settings.form', [
            'settings' => $settings,
            'websites' => $websites,
        ]);
    }

    public function update(UpdateStatusPageSettingsRequest $request, AuditLogger $audit, StatusPageCache $cache)
    {
        $settings = StatusPageSetting::singleton();
        $data = $request->validated();

        $previousMode = (string) $settings->visibility_mode;
        $settings->visibility_mode = (string) $data['visibility_mode'];

        $branding = [
            'title' => isset($data['branding']['title']) ? (string) $data['branding']['title'] : null,
            'message' => isset($data['branding']['message']) ? (string) $data['branding']['message'] : null,
            'footer' => isset($data['branding']['footer']) ? (string) $data['branding']['footer'] : null,
        ];
        $settings->branding = array_filter($branding, fn ($v) => $v !== null && $v !== '');

        $slug = isset($data['slug']) ? trim((string) $data['slug']) : '';
        $settings->slug = $slug !== '' ? $slug : null;

        if (! empty($data['clear_password'])) {
            $settings->password_hash = null;
        } elseif (! empty($data['password'])) {
            $settings->password_hash = Hash::make((string) $data['password']);
        }

        // Fail-closed: password mode must keep a hash.
        if ($settings->visibility_mode === StatusPageSetting::MODE_PASSWORD_PROTECTED && empty($settings->password_hash)) {
            return back()->withErrors(['password' => 'Password Protected mode requires a password.'])->withInput();
        }

        $settings->save();

        // Per-website publish + alias (display mapping only).
        $published = array_map('intval', (array) ($data['published'] ?? []));
        $aliases = (array) ($data['aliases'] ?? []);
        $websites = Website::query()->get(['id']);
        foreach ($websites as $website) {
            $id = (int) $website->id;
            $website->is_visible_on_status = in_array($id, $published, true);
            $alias = isset($aliases[$id]) ? trim((string) $aliases[$id]) : '';
            $website->status_alias = $alias !== '' ? mb_substr($alias, 0, 255) : null;
            $website->save();
        }

        // History toggle is deployment config default false; store request intent in branding-adjacent
        // settings row is out of frozen schema, so honor config only (no-op here beyond validation).

        $audit->log('status_page.visibility.changed', $request->user(), $settings, [
            'from' => $previousMode,
            'to' => (string) $settings->visibility_mode,
        ]);

        $cache->invalidate();

        return redirect()->route('admin.status-settings.edit')->with('status', 'Status page settings saved.');
    }
}
