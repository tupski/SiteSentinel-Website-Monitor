<section aria-label="History" class="mt-6">
    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">History</h2>
    <ul class="divide-y divide-slate-200 rounded border border-slate-200 bg-white">
        @foreach($services as $service)
            <li class="flex items-center justify-between gap-4 p-4">
                <p class="font-medium">{{ $service['displayName'] }}</p>
                <p class="text-sm">{{ $service['publicLabel'] }} — {{ $service['dayBucket'] }}</p>
            </li>
        @endforeach
    </ul>
</section>
