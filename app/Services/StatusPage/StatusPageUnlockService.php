<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

use App\Models\StatusPageSetting;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class StatusPageUnlockService
{
    public function __construct(
        private readonly VisibilityGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    public function attempt(Request $request, string $password): bool
    {
        $settings = StatusPageSetting::singleton();

        if (! $settings->isPasswordProtected()) {
            return false;
        }

        $hash = (string) ($settings->password_hash ?? '');
        if ($hash === '') {
            $this->audit->log('status_page.unlock.failed', null, $settings, ['reason' => 'no_password_configured']);

            return false;
        }

        if (! Hash::check($password, $hash)) {
            $this->audit->log('status_page.unlock.failed', null, $settings, ['reason' => 'bad_password']);

            return false;
        }

        $request->session()->put(VisibilityGate::UNLOCK_SESSION_KEY, now('UTC')->format('Y-m-d\TH:i:s\Z'));
        $request->session()->put(VisibilityGate::UNLOCK_VERSION_KEY, $this->gate->versionStamp($settings));
        $request->session()->put('status_page.version', 1);

        RateLimiter::clear($this->throttleKey($request));

        return true;
    }

    public function lock(Request $request): void
    {
        $request->session()->forget([
            VisibilityGate::UNLOCK_SESSION_KEY,
            VisibilityGate::UNLOCK_VERSION_KEY,
            'status_page.version',
        ]);
    }

    public function throttleKey(Request $request): string
    {
        $sessionId = (string) ($request->session()->getId() ?? 'guest');

        return 'status-unlock:'.$request->ip().':'.$sessionId;
    }
}
