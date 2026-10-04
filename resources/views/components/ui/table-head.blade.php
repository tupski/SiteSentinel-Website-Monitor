@props([])

<thead {{ $attributes->merge(['class' => 'bg-surface-muted text-text-muted']) }}>
    {{ $slot }}
</thead>
