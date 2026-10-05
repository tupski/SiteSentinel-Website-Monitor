@props([
    'caption' => null,
    // `flush` renders the table inside its parent card instead of wrapping it in
    // a second bordered card. Used by detail pages where the table lives inside
    // an already-bordered section: the table's left edge then aligns with the
    // section heading / first column rather than being inset by a nested card.
    // Defaults to false, so every existing caller is unchanged.
    'flush' => false,
])

@php
    // The outer wrapper owns the horizontal scroll in BOTH modes. Tables must
    // NEVER push the page into horizontal overflow (requirement §12):
    // `overflow-x-auto` keeps wide tables contained and scrollable.
    //
    // Non-flush (default): the wrapper is the bordered, rounded card.
    // Flush: the wrapper is just the scroll container; the parent card provides
    // the border/surface so there is no double border or extra left inset.
    $wrapperClass = $flush
        ? 'overflow-x-auto'
        : 'overflow-hidden rounded-lg border border-border bg-surface-elevated shadow-sm';
@endphp

<div {{ $attributes->merge(['class' => $wrapperClass]) }}>
    @if (! $flush)
        <div class="overflow-x-auto">
    @endif

    <table class="min-w-full divide-y divide-border text-sm">
        @if ($caption)
            <caption class="sr-only">{{ $caption }}</caption>
        @endif

        {{ $slot }}
    </table>

    @if (! $flush)
        </div>
    @endif
</div>
