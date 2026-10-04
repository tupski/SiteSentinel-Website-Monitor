<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\StatusPage;
use App\Services\StatusPage\VisibilityGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-page visibility gate (STATUS-PAGE.md §2).
 *
 * Never a login redirect: Private is a 404, Password Protected renders the
 * password form with zero data (unless no hash is configured — fail closed).
 */
final class EnsureStatusVisibility
{
    public function __construct(
        private readonly VisibilityGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var StatusPage $page */
        $page = $request->route('statusPage');

        $allowed = $this->gate->canView($request, $page);

        if (! $allowed) {
            if ($page->isPrivate()) {
                abort(404);
            }

            if ($page->isPasswordProtected() && ! $this->gate->hasUsablePassword($page)) {
                abort(404);
            }

            // Locked password mode: render password form only, no data.
            $response = response()->view('status.unlock', ['statusPage' => $page], 200);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        /** @var Response $response */
        $response = $next($request);

        if ($page->isPublic()) {
            $response->headers->set('Cache-Control', 'public, max-age=60');
        } else {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
