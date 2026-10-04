@props([
    'title' => null,
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-4 py-10 text-center']) }}>
    @isset($icon)
        <div class="mb-3 text-text-subtle" aria-hidden="true">
            {{ $icon }}
        </div>
    @endisset

    @if ($title)
        <p class="text-sm font-semibold text-text">{{ $title }}</p>
    @endif

    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-text-muted">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endisset
</div>
