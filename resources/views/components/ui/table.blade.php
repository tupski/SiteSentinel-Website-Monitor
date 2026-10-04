@props([
    'caption' => null,
])

{{-- The outer wrapper owns the border, radius and — critically — the
     horizontal scroll. Tables must NEVER push the page into horizontal
     overflow (requirement §12): `overflow-x-auto` keeps wide tables contained
     and scrollable within their card. --}}
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-border bg-surface-elevated shadow-sm']) }}>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border text-sm">
            @if ($caption)
                <caption class="sr-only">{{ $caption }}</caption>
            @endif

            {{ $slot }}
        </table>
    </div>
</div>
