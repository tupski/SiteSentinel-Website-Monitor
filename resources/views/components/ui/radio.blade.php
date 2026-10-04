@props([
    'label' => null,
])

{{-- Native radio; `accent-color` is set globally on inputs in app.css so it
     stays visible against both light and dark surfaces. --}}
<label {{ $attributes->only('class')->merge(['class' => 'inline-flex items-center gap-2 text-sm text-text']) }}>
    <input
        type="radio"
        {{ $attributes->except('class')->merge(['class' => 'h-4 w-4 border-border-muted text-primary focus:ring-2 focus:ring-focus focus:ring-offset-0 disabled:cursor-not-allowed disabled:opacity-60']) }}
    >
    @if ($label)
        <span>{{ $label }}</span>
    @endif
</label>
