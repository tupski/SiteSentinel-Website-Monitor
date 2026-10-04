@php
    // Machine-readable UTC ISO-8601 projection stamp (ADR-040). The browser
    // renders it in the VISITOR'S local timezone; the server never emits a
    // pre-formatted local string. Falls back to the coarse day bucket when the
    // precise stamp is absent/invalid (progressive enhancement).
    $updatedAtIso = (string) $dto->updatedAt;
    $parseable = $updatedAtIso !== '' && strtotime($updatedAtIso) !== false;
    $fallbackDay = (string) $dto->updatedDayBucket;
    $fallbackText = $fallbackDay !== ''
        ? 'Last update: '.$fallbackDay.' (day-level, UTC)'
        : 'Last update: —';
    $refreshIntervals = [
        ['value' => 60, 'label' => __('1 minute')],
        ['value' => 300, 'label' => __('5 minutes')],
        ['value' => 600, 'label' => __('10 minutes')],
        ['value' => 1800, 'label' => __('30 minutes')],
        ['value' => 3600, 'label' => __('60 minutes')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $statusPage->name !== '' ? $statusPage->name : 'System Status' }}</title>
    @include('partials.theme-bootstrap')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface text-text antialiased">
<main class="mx-auto max-w-3xl px-4 py-10">
    <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <h1 class="text-2xl font-bold tracking-tight text-text">{{ $statusPage->name !== '' ? $statusPage->name : 'System Status' }}</h1>

        {{-- Auto-refresh control (Requirement 25, ADR-039). Progressive
             enhancement only: Alpine drives the timer and countdown; the
             server-rendered status above is complete without it. The component
             logic lives in app.js (`statusRefresh`), per AGENTS.md §7. --}}
        <div
            x-data="statusRefresh({
                jsonUrl: @js(route('status.json', ['statusPage' => $statusPage->slug])),
                updatedAt: @js($updatedAtIso),
                allowedIntervals: @js(array_column($refreshIntervals, 'value')),
                defaults: { enabled: false, interval: 60 },
                storageKey: 'sentinel.status.refresh',
                messages: {
                    on: @js(__('Auto-refresh ON')),
                    off: @js(__('Auto-refresh OFF')),
                    nextRefreshIn: @js(__('Next refresh in:')),
                    paused: @js(__('Paused')),
                    refreshNow: @js(__('Refresh now')),
                    enabled: @js(__('Enable auto-refresh')),
                    interval: @js(__('Refresh interval')),
                },
            })"
            class="rounded border border-border bg-surface-elevated p-3 text-sm"
        >
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <label class="inline-flex cursor-pointer items-center gap-2 font-medium text-text">
                    <input
                        type="checkbox"
                        class="h-4 w-4 rounded border-border-muted text-primary focus:ring-2 focus:ring-focus focus:ring-offset-0"
                        x-bind:checked="enabled"
                        x-on:change="toggle($event.target.checked)"
                        :aria-label="messages.enabled"
                    >
                    <span x-text="enabled ? messages.on : messages.off" aria-live="polite">Auto-refresh OFF</span>
                </label>

                <label class="inline-flex items-center gap-2 text-text-muted" x-show="enabled" x-cloak>
                    <span class="sr-only" x-text="messages.interval">Refresh interval</span>
                    <x-ui.select
                        class="w-auto py-1 text-sm"
                        name="status_refresh_interval"
                        x-model.number="interval"
                        x-on:change="applyInterval()"
                    >
                        @foreach($refreshIntervals as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </label>

                <span
                    class="tabular-nums font-medium text-text"
                    x-show="enabled"
                    x-cloak
                    x-text="countdownDisplay"
                    aria-live="off"
                ></span>

                <x-ui.button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="ml-auto"
                    x-on:click="refreshNow()"
                    x-bind:disabled="loading"
                >
                    <span x-text="messages.refreshNow">Refresh now</span>
                </x-ui.button>
            </div>
        </div>
    </header>

    <section aria-label="Overall status" class="mb-6 rounded border border-border bg-surface-elevated p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-text-subtle">Current status</h2>
        <p id="status-banner" class="mt-1 text-lg font-semibold text-text">{{ $dto->banner }}</p>
    </section>

    <section aria-label="Services">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-text-subtle">Services</h2>
        <ul id="status-services" class="divide-y divide-border rounded border border-border bg-surface-elevated">
            @forelse($dto->services as $service)
                <li class="flex items-center justify-between gap-4 p-4">
                    <div>
                        <p class="font-medium text-text">{{ $service['displayName'] }}</p>
                        <p class="text-xs text-text-subtle">Updated {{ $service['dayBucket'] }}</p>
                    </div>
                    <p class="text-sm font-semibold text-text">{{ $service['publicLabel'] }}@if(isset($service['responseBand'])) <span class="ml-2 font-normal text-text-subtle">({{ $service['responseBand'] }})</span>@endif</p>
                </li>
            @empty
                <li class="p-4 text-sm text-text-subtle">No services published.</li>
            @endforelse
        </ul>
    </section>

    @if($historyEnabled)
        @include('status._history', ['services' => $dto->services])
    @endif

    <p class="mt-6 text-xs text-text-subtle">
        <time
            id="status-last-update"
            @if($parseable) datetime="{{ $updatedAtIso }}" @endif
            x-data="statusLocalTime({ iso: @js($parseable ? $updatedAtIso : '') })"
            x-text="display"
        >{{ $fallbackText }}</time>
    </p>
</main>
</body>
</html>
