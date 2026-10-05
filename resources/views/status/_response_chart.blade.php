@php
    // Page-level response-time chart (Requirement: one bar per checked website,
    // taller = slower, bottom axis = the time checked).
    //
    // Built ONLY from the public-safe allowlist the projector emits: the coarse
    // `responseMs` (a rounded millisecond figure — never the exact
    // `checks.duration_ms`) and the coarse `checkedAt` time bucket. A website
    // with no timed check carries neither key and is omitted, so the chart
    // never fabricates a bar. The visible label/value table is intentionally
    // disabled here (the tooltips + axis carry the meaning).
    $points = collect($services)
        ->filter(static fn ($service): bool => isset($service['responseMs'], $service['checkedAt']))
        ->map(static fn ($service): array => [
            'label' => (string) ($service['displayName'] ?? ''),
            'axis' => (string) $service['checkedAt'],
            'value' => (int) $service['responseMs'],
        ])
        // Chronological bottom axis (coarse buckets sort lexicographically);
        // ties broken by display name so the order is stable across requests.
        ->sortBy([
            ['axis', 'asc'],
            ['label', 'asc'],
        ])
        ->values()
        ->all();
@endphp

@if ($points !== [])
    <section aria-label="{{ __('Response time') }}" class="mt-6" data-status-response-chart>
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-text-subtle">{{ __('Response time by website') }}</h2>
        <div class="rounded-lg border border-border bg-surface-elevated p-4 shadow-sm">
            <x-ui.chart
                type="bar"
                :series="$points"
                :title="__('Response time by website')"
                :axis-label="__('Time checked (UTC)')"
                value-suffix=" ms"
                :table="false"
                :caption="__('One bar per published website; a taller bar means a slower response. The bottom axis is the time each site was last checked.')"
            />
        </div>
    </section>
@endif
