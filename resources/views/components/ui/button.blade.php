@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'iconOnly' => false,
])

@php
    // Semantic token utilities only — no `dark:` duplication needed, the tokens
    // flip under `.dark` (Phase 2 design layer).
    $base = 'inline-flex items-center justify-center gap-2 font-medium transition-colors '
        .'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 '
        .'focus-visible:ring-offset-surface-elevated disabled:pointer-events-none disabled:opacity-50';

    $variants = [
        'primary' => 'bg-primary text-primary-foreground hover:bg-primary-hover',
        'secondary' => 'border border-border bg-surface-elevated text-text hover:bg-surface-hover',
        'outline' => 'border border-border-muted bg-transparent text-text hover:bg-surface-hover',
        'ghost' => 'bg-transparent text-text-muted hover:bg-surface-hover hover:text-text',
        'danger' => 'bg-danger text-danger-foreground hover:bg-danger-hover',
        // Semantic filled actions (Phase 3a) — no `*-hover` token exists for these
        // yet, so hover uses an opacity shift that is theme-agnostic.
        'warning' => 'bg-warning text-warning-foreground hover:opacity-90',
        'success' => 'bg-success text-success-foreground hover:opacity-90',
    ];

    $sizes = [
        'sm' => $iconOnly ? 'h-8 w-8' : 'px-3 py-1.5 text-sm',
        'md' => $iconOnly ? 'h-9 w-9' : 'px-4 py-2 text-sm',
        'lg' => $iconOnly ? 'h-11 w-11' : 'px-5 py-2.5 text-base',
    ];

    $classes = trim($base.' '.($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @isset($icon){{ $icon }}@endisset
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @isset($icon){{ $icon }}@endisset
        {{ $slot }}
    </button>
@endif
