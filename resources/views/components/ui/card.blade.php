@props([
    'title' => null,
    'subtitle' => null,
    'padding' => true,
])

{{-- The sitewide card surface: rounded-lg + hairline border + soft shadow,
     expressed with flip-aware semantic tokens (see resources/css/app.css). --}}
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-border bg-surface-elevated shadow-sm']) }}>
    @if ($title || isset($actions))
        <div class="flex items-start justify-between gap-4 border-b border-border {{ $padding ? 'px-4 py-3' : 'p-0' }}">
            <div>
                @if ($title)
                    <h3 class="text-base font-semibold text-text">{{ $title }}</h3>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 text-sm text-text-muted">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    <div class="{{ $padding ? 'p-4' : '' }}">
        {{ $slot }}
    </div>
</div>
