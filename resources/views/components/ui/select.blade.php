@props([
    'invalid' => false,
])

@php
    $base = 'block w-full rounded border bg-surface-elevated px-3 py-2 text-sm text-text '
        .'focus:border-focus focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-0 '
        .'disabled:cursor-not-allowed disabled:opacity-60';

    $states = $invalid
        ? 'border-danger focus:border-danger focus:ring-danger'
        : 'border-border-muted';

    $classes = trim($base.' '.$states);
@endphp

<select
    @if ($invalid) aria-invalid="true" @endif
    {{ $attributes->merge(['class' => $classes]) }}
>
    {{ $slot }}
</select>
