<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\StatusPageSetting;
use App\Services\StatusPage\VisibilityGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureStatusVisibility
{
    public function __construct(
        private readonly VisibilityGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var StatusPageSetting $settings */
        $settings = StatusPageSetting::singleton();

        $allowed = $this->gate->canView($request, $settings);

        if (! $allowed) {
            if ($settings->isPrivate()) {
                abort(404);
            }

            if ($settings->isPasswordProtected() && ! $this->gate->hasUsablePassword($settings)) {
                abort(404);
            }

            // Locked password mode: render password form only, no data.
            $response = response()->view('status.unlock', [], 200);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        /** @var Response $response */
        $response = $next($request);

        if ($settings->isPublic()) {
            $response->headers->set('Cache-Control', 'public, max-age=60');
        } else {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
