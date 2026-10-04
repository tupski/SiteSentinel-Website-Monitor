<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $statusPage->name !== '' ? $statusPage->name : 'System Status' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<main class="mx-auto max-w-3xl px-4 py-10">
    <header class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">{{ $statusPage->name !== '' ? $statusPage->name : 'System Status' }}</h1>
    </header>

    <section aria-label="Overall status" class="mb-6 rounded border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Current status</h2>
        <p class="mt-1 text-lg font-semibold">{{ $dto->banner }}</p>
    </section>

    <section aria-label="Services">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Services</h2>
        <ul class="divide-y divide-slate-200 rounded border border-slate-200 bg-white">
            @forelse($dto->services as $service)
                <li class="flex items-center justify-between gap-4 p-4">
                    <div>
                        <p class="font-medium">{{ $service['displayName'] }}</p>
                        <p class="text-xs text-slate-500">Updated {{ $service['dayBucket'] }}</p>
                    </div>
                    <p class="text-sm font-semibold">{{ $service['publicLabel'] }}@if(isset($service['responseBand'])) <span class="ml-2 font-normal text-slate-500">({{ $service['responseBand'] }})</span>@endif</p>
                </li>
            @empty
                <li class="p-4 text-sm text-slate-500">No services published.</li>
            @endforelse
        </ul>
    </section>

    @if($historyEnabled)
        @include('status._history', ['services' => $dto->services])
    @endif

    <p class="mt-6 text-xs text-slate-500">Updated {{ $dto->updatedDayBucket }} (day-level, UTC).</p>
</main>
</body>
</html>
