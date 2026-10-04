<section aria-label="History" class="mt-6">
    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-text-subtle">History</h2>
    <ul class="divide-y divide-border rounded border border-border bg-surface-elevated">
        @foreach($services as $service)
            <li class="flex items-center justify-between gap-4 p-4">
                <p class="font-medium text-text">{{ $service['displayName'] }}</p>
                <p class="text-sm text-text">{{ $service['publicLabel'] }} — {{ $service['dayBucket'] }}</p>
            </li>
        @endforeach
    </ul>
</section>
