<?php

declare(strict_types=1);

use App\Services\Settings\SettingsRepository;

if (! function_exists('settings')) {
    /**
     * Resolve the system settings repository (ADR-035).
     *
     * `settings('site_name')` returns one typed value; `settings()` returns the
     * whole key => value map. Always resolves through the container so the
     * cached, memoized singleton is reused within the request.
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        /** @var SettingsRepository $repository */
        $repository = app(SettingsRepository::class);

        return $key === null ? $repository->all() : $repository->get($key, $default);
    }
}
