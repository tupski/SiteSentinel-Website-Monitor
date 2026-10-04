<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Services\StatusPage\StatusPageCache;
use App\Services\StatusPage\StatusPageUnlockService;
use App\Services\StatusPage\VisibilityGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Public status page (Phase 8 → Phase 11, ADR-031).
 *
 * Each page is addressed by its unique slug and its visibility mode is a
 * per-page property. The projection, cache, and unlock session are all scoped
 * to the page, so no page can expose another page's websites.
 */
final class StatusPageController extends Controller
{
    public function show(Request $request, StatusPage $statusPage, VisibilityGate $gate, StatusPageCache $cache)
    {
        // Fail-closed: password mode without hash never renders data.
        if ($statusPage->isPasswordProtected() && ! $gate->hasUsablePassword($statusPage)) {
            abort(404);
        }

        if (! $gate->canView($request, $statusPage)) {
            if ($statusPage->isPrivate()) {
                abort(404);
            }

            return response()->view('status.unlock', ['statusPage' => $statusPage], 200)
                ->header('Cache-Control', 'no-store, private')
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $dto = $cache->remember($statusPage);
        $historyEnabled = (bool) config('sentinel.status_page.history_enabled', false);

        $response = response()->view('status.show', [
            'dto' => $dto,
            'statusPage' => $statusPage,
            'historyEnabled' => $historyEnabled,
        ], 200);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', $statusPage->isPublic() ? 'public, max-age=60' : 'no-store, private');

        return $response;
    }

    public function json(Request $request, StatusPage $statusPage, VisibilityGate $gate, StatusPageCache $cache)
    {
        // Fail-closed: password mode without a usable hash must not advertise
        // the page's existence. Mirrors show() and SECURITY.md §3.5 (404).
        if ($statusPage->isPasswordProtected() && ! $gate->hasUsablePassword($statusPage)) {
            abort(404);
        }

        if (! $gate->canView($request, $statusPage)) {
            if ($statusPage->isPrivate()) {
                abort(404);
            }

            return response()->json(['message' => 'Locked.'], 403)
                ->header('Cache-Control', 'no-store, private')
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $dto = $cache->remember($statusPage);

        return response()->json($dto->toArray(), 200)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', $statusPage->isPublic() ? 'public, max-age=60' : 'no-store, private');
    }

    /**
     * Legacy `/status` (no slug): 302 to the default page, 404 when none.
     */
    public function legacy(): RedirectResponse
    {
        $page = StatusPage::query()->where('is_default', true)->orderBy('id')->first();

        if ($page === null) {
            abort(404);
        }

        return redirect()->route('status.show', ['statusPage' => $page->slug]);
    }

    /**
     * Legacy `/status.json`: 302 to the default page's JSON, 404 when none.
     */
    public function jsonLegacy(): RedirectResponse
    {
        $page = StatusPage::query()->where('is_default', true)->orderBy('id')->first();

        if ($page === null) {
            abort(404);
        }

        return redirect()->route('status.json', ['statusPage' => $page->slug]);
    }

    public function unlock(Request $request, StatusPage $statusPage, StatusPageUnlockService $unlocks)
    {
        if (! $statusPage->isPasswordProtected()) {
            abort(404);
        }

        $data = $request->validate(['password' => ['required', 'string']]);

        if (! $unlocks->attempt($request, $statusPage, (string) $data['password'])) {
            // Uniform, zero-data failure: a validation error yields 422 for
            // JSON clients and a redirect-back-with-errors for the form, so a
            // wrong password never renders service data in either surface.
            throw ValidationException::withMessages([
                'password' => 'Incorrect password.',
            ]);
        }

        return redirect()->route('status.show', ['statusPage' => $statusPage->slug]);
    }

    public function logout(Request $request, StatusPage $statusPage, StatusPageUnlockService $unlocks)
    {
        $unlocks->lock($request, $statusPage);

        // Never log out admin session here: status unlock is scoped only.
        return redirect()->route('status.show', ['statusPage' => $statusPage->slug]);
    }
}
