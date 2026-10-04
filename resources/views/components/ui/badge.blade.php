@props([
    'variant' => 'neutral',
])

@php
    $variants = [
        'neutral' => 'bg-surface-muted text-text-muted border-border',
        'success' => 'bg-success-muted text-success border-success/30',
        'warning' => 'bg-warning-muted text-warning border-warning/30',
        'danger' => 'bg-danger-muted text-danger border-danger/30',
        'info' => 'bg-info-muted text-info border-info/30',
    ];

    $classes = 'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold '
        .($variants[$variant] ?? $variants['neutral']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
