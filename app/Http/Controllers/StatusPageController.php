<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StatusPageSetting;
use App\Services\StatusPage\StatusPageCache;
use App\Services\StatusPage\StatusPageUnlockService;
use App\Services\StatusPage\VisibilityGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class StatusPageController extends Controller
{
    public function show(Request $request, VisibilityGate $gate, StatusPageCache $cache)
    {
        $settings = StatusPageSetting::singleton();

        // Fail-closed: password mode without hash never renders data.
        if ($settings->isPasswordProtected() && ! $gate->hasUsablePassword($settings)) {
            abort(404);
        }

        if (! $gate->canView($request, $settings)) {
            if ($settings->isPrivate()) {
                abort(404);
            }

            return response()->view('status.unlock', [], 200)
                ->header('Cache-Control', 'no-store, private')
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $dto = $cache->remember($settings);
        $branding = is_array($settings->branding) ? $settings->branding : [];
        $historyEnabled = (bool) config('sentinel.status_page.history_enabled', false);

        $response = response()->view('status.show', [
            'dto' => $dto,
            'branding' => $branding,
            'historyEnabled' => $historyEnabled,
        ], 200);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', $settings->isPublic() ? 'public, max-age=60' : 'no-store, private');

        return $response;
    }

    public function json(Request $request, VisibilityGate $gate, StatusPageCache $cache)
    {
        $settings = StatusPageSetting::singleton();

        if (! $gate->canView($request, $settings)) {
            if ($settings->isPrivate()) {
                abort(404);
            }

            return response()->json(['message' => 'Locked.'], 403)
                ->header('Cache-Control', 'no-store, private')
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $dto = $cache->remember($settings);

        return response()->json($dto->toArray(), 200)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', $settings->isPublic() ? 'public, max-age=60' : 'no-store, private');
    }

    public function unlock(Request $request, StatusPageUnlockService $unlocks)
    {
        $settings = StatusPageSetting::singleton();

        if (! $settings->isPasswordProtected()) {
            abort(404);
        }

        $data = $request->validate(['password' => ['required', 'string']]);

        if (! $unlocks->attempt($request, (string) $data['password'])) {
            // Uniform, zero-data failure: a validation error yields 422 for
            // JSON clients and a redirect-back-with-errors for the form, so a
            // wrong password never renders service data in either surface.
            throw ValidationException::withMessages([
                'password' => 'Incorrect password.',
            ]);
        }

        return redirect()->route('status.show');
    }

    public function logout(Request $request, StatusPageUnlockService $unlocks)
    {
        $unlocks->lock($request);

        // Never log out admin session here: status unlock is scoped only.
        if (Auth::check()) {
            return redirect()->route('status.show');
        }

        return redirect()->route('status.show');
    }
}
