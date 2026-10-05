@php
    // One public service card (STATUS-PAGE.md §4.3, §5, §7.2).
    //
    // Shows ONLY the public-safe allowlist: display name, coarse status label,
    // sample-based uptime, and a failed-check count. The response-time signal
    // is drawn once for the whole page (status._response_chart), not per card.
    // Never the exact response time, security state, rules, domains, or IPs.
    $label = (string) ($service['publicLabel'] ?? 'Unknown');
    $badgeVariant = match ($label) {
        'Operational' => 'success',
        'Degraded' => 'warning',
        'Incident' => 'warning',
        'Partial Outage', 'Major Outage' => 'danger',
        default => 'neutral',
    };
    $iconName = match ($label) {
        'Operational' => 'check-circle',
        'Partial Outage', 'Major Outage', 'Incident' => 'x-circle',
        default => 'exclamation-triangle',
    };
    $uptime = $service['uptime'] ?? null;
    $failed = is_array($uptime) && ($uptime['available'] ?? false) ? (int) $uptime['down'] : null;
@endphp

<article class="rounded-lg border border-border bg-surface-elevated p-4 shadow-sm"
         data-status-service="{{ $service['opaqueIndex'] ?? '' }}">
    {{-- Site name on the left, its status badge inline and right-aligned on the
         same row (Requirement: badges sit to the right of the site name). --}}
    <header class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
            <x-ui.icon :name="$iconName" class="h-5 w-5 {{ $badgeVariant === 'success' ? 'text-success' : ($badgeVariant === 'danger' ? 'text-danger' : 'text-text-muted') }}" />
            <h3 class="font-semibold text-text">{{ $service['displayName'] }}</h3>
        </div>

        <x-ui.badge :variant="$badgeVariant" data-service-label>{{ $label }}</x-ui.badge>
    </header>

    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-text-muted">
        <div data-service-uptime>
            @if (is_array($uptime) && ($uptime['available'] ?? false))
                <span class="font-semibold text-text">{{ number_format((float) $uptime['percent'], 2) }}%</span>
                <span class="mx-1 text-text-subtle">·</span>
                <span>{{ $failed }} {{ __('failed checks') }}</span>
            @else
                <span>{{ __('No availability data yet') }}</span>
            @endif
        </div>

        <span data-service-bucket>{{ __('Updated') }} {{ $service['dayBucket'] }}</span>
    </div>
</article>
