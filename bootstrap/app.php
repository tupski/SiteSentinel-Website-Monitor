<?php

declare(strict_types=1);

use App\Http\Middleware\ApplySystemSettings;
use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\ThrottleStatusUnlock;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Admin authorization (SECURITY.md §3.2): alias used by the /admin route group.
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'throttle.status-unlock' => ThrottleStatusUnlock::class,
            'session.timeouts' => EnforceSessionTimeouts::class,
        ]);

        // Apply stored system settings (timezone + branding) to every web request
        // so an admin edit is actually reflected at runtime (ADR-035, ADR-043).
        // Appended last in the web group: it only reads settings and shares view
        // data, so it must run after the session/auth stack is resolved.
        $middleware->appendToGroup('web', ApplySystemSettings::class);

        // Redirect guests (login pages) back to login; authenticated users to the shell.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));

        // Trusted reverse proxy (SECURITY.md §3.4 / §7, Phase 9). The app runs
        // behind Nginx in Docker Compose, so without this every client appears
        // to originate from the proxy and IP-keyed throttling collapses to one
        // bucket. Trust is opt-in and must list the exact proxy addresses/CIDRs
        // (never '*'), so a direct client cannot spoof X-Forwarded-For to evade
        // rate limits. Default: trust nothing (fail-safe).
        $trustedProxies = env('TRUSTED_PROXIES');
        if (is_string($trustedProxies) && trim($trustedProxies) !== '' && $trustedProxies !== '*') {
            $middleware->trustProxies(
                at: array_map('trim', explode(',', $trustedProxies)),
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        // NOTE: CSRF protection is never disabled here. Laravel already skips
        // token validation when running the test suite (APP_ENV=testing); that
        // built-in behaviour must not be replaced by a blanket route exemption,
        // because a wildcard exemption is a real CSRF bypass if it ever ships.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
