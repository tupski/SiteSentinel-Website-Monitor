@props([])

<tbody {{ $attributes->merge(['class' => 'divide-y divide-border bg-surface-elevated text-text']) }}>
    {{ $slot }}
</tbody>
