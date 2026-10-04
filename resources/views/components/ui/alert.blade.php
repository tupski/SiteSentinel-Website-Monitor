@props([
    'variant' => 'info',
    'title' => null,
])

@php
    $variants = [
        'info' => ['bg-info-muted', 'border-info/30', 'text-info'],
        'success' => ['bg-success-muted', 'border-success/30', 'text-success'],
        'warning' => ['bg-warning-muted', 'border-warning/30', 'text-warning'],
        'danger' => ['bg-danger-muted', 'border-danger/30', 'text-danger'],
    ];

    [$bg, $border, $accent] = $variants[$variant] ?? $variants['info'];
@endphp

<div role="alert" {{ $attributes->merge(['class' => trim("flex items-start gap-3 rounded border px-4 py-3 text-sm {$bg} {$border}")]) }}>
    <div class="flex-1">
        @if ($title)
            <p class="font-semibold {{ $accent }}">{{ $title }}</p>
        @endif

        <div class="{{ $title ? 'mt-0.5 text-text' : 'text-text' }}">
            {{ $slot }}
        </div>
    </div>

    @isset($actions)
        <div class="shrink-0">{{ $actions }}</div>
    @endisset
</div>
