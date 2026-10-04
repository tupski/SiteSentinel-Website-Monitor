@props([
    'variant' => 'info',
    'title' => null,
    'dismissible' => false,
    'dismissLabel' => 'Dismiss notification',
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

<div role="alert"
     @if ($dismissible)
         x-data="flashMessage"
         x-show="visible"
         x-transition
         x-cloak
     @endif
     {{ $attributes->merge(['class' => trim("flex items-start gap-3 rounded border px-4 py-3 text-sm {$bg} {$border}")]) }}>
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

    @if ($dismissible)
        <button type="button"
                x-on:click.stop="dismiss()"
                aria-label="{{ $dismissLabel }}"
                title="{{ $dismissLabel }}"
                class="shrink-0 rounded-md p-1 text-text-muted transition-colors hover:bg-surface-hover hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
            <x-ui.icon name="x-mark" class="h-4 w-4" />
        </button>
    @endif
</div>
