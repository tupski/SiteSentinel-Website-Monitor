@props([
    'lines' => 1,
])

{{-- Minimal pulse placeholder. Decorative only, so it is hidden from AT. --}}
<div {{ $attributes->merge(['class' => 'space-y-2']) }} aria-hidden="true">
    @for ($i = 0; $i < max(1, (int) $lines); $i++)
        <div class="h-4 w-full animate-pulse rounded bg-surface-muted"></div>
    @endfor
</div>
