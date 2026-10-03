<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleStatusUnlock
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('sentinel.status_page.unlock_max', 5);
        $windowMinutes = (int) config('sentinel.status_page.unlock_window', 10);
        $decaySeconds = max(60, $windowMinutes * 60);
        $key = 'status-unlock:'.$request->ip().':'.((string) ($request->session()->getId() ?? 'guest'));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            return response('Too many attempts.', 429)->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        RateLimiter::hit($key, $decaySeconds);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
