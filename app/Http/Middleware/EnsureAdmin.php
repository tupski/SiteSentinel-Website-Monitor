<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin authorization gate for /admin/* (SECURITY.md §3.2).
 *
 * Enforcement order: authenticated → is_active → role admin.
 * Disabled accounts get their session invalidated and a 403.
 * Runs on EVERY /admin route regardless of HTTP method (AC-02 / AC-2-02).
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            // Unauthenticated: redirect browsers, 401 for JSON expectations
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->route('login');
        }

        if (! $user->isActive()) {
            // Disabled account: kill the session, deny
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'Account disabled.');
        }

        if (! $user->isAdmin()) {
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
