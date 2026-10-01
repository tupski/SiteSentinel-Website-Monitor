<x-admin-layout>
    <x-slot name="title">{{ __('Dashboard') }} — SiteSentinel Admin</x-slot>

    <h1 class="text-2xl font-bold tracking-tight">{{ __('Dashboard') }}</h1>

    {{-- AC-6-07: counters total / operational / warning / incident. --}}
    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">{{ __('Total websites') }}</div>
            <div class="mt-1 text-2xl font-bold">{{ $counters['total'] }}</div>
        </div>
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 shadow-sm">
            <div class="text-sm text-green-700">{{ __('Operational') }}</div>
            <div class="mt-1 text-2xl font-bold text-green-800">{{ $counters['operational'] }}</div>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-sm">
            <div class="text-sm text-amber-700">{{ __('Warning incidents') }}</div>
            <div class="mt-1 text-2xl font-bold text-amber-800">{{ $counters['warning'] }}</div>
        </div>
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 shadow-sm">
            <div class="text-sm text-red-700">{{ __('Critical incidents') }}</div>
            <div class="mt-1 text-2xl font-bold text-red-800">{{ $counters['incident'] }}</div>
        </div>
    </div>

    {{-- AC-2-07 invariant: availability and security stay SEPARATE areas (AGENTS.md 15.2). --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section aria-labelledby="availability-heading" class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h2 id="availability-heading" class="text-lg font-semibold">{{ __('Availability') }}</h2>
            <table class="mt-3 min-w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500">
                        <th class="py-2">{{ __('Website') }}</th>
                        <th class="py-2">{{ __('Availability') }}</th>
                        <th class="py-2">{{ __('Last check') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($websites as $website)
                        <tr>
                            <td class="py-2 font-medium">{{ $website->name }}</td>
                            <td class="py-2">{{ $website->status_availability ?? '-' }}</td>
                            <td class="py-2 text-slate-500">{{ $website->last_checked_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-4 text-center text-slate-500">{{ __('No websites monitored yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section aria-labelledby="security-heading" class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h2 id="security-heading" class="text-lg font-semibold">{{ __('Security & Content Health') }}</h2>
            <table class="mt-3 min-w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500">
                        <th class="py-2">{{ __('Website') }}</th>
                        <th class="py-2">{{ __('Security') }}</th>
                        <th class="py-2">{{ __('Last check') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($websites as $website)
                        <tr>
                            <td class="py-2 font-medium">{{ $website->name }}</td>
                            <td class="py-2">{{ $website->status_security ?? '-' }}</td>
                            <td class="py-2 text-slate-500">{{ $website->last_checked_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-4 text-center text-slate-500">{{ __('No websites monitored yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">{{ __('Open incidents') }}</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($openIncidents as $incident)
                    <li class="flex items-center justify-between">
                        <a href="{{ route('admin.incidents.show', $incident) }}" class="underline hover:text-slate-900">
                            {{ $incident->website->name }} — {{ $incident->type }}
                        </a>
                        <span>{{ $incident->severity }} · {{ $incident->status }}</span>
                    </li>
                @empty
                    <li class="text-slate-500">{{ __('No open incidents.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- FR-61: interleaved per-website timeline of checks and incident events. --}}
        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">{{ __('Recent activity') }}</h2>
            <ol class="mt-3 space-y-2 text-sm">
                @forelse ($timeline as $entry)
                    <li class="flex items-center justify-between border-b border-slate-100 pb-1">
                        <span>
                            @if ($entry['kind'] === 'check')
                                <span class="font-medium">{{ __('Check') }}</span>
                                — {{ $entry['website'] }}
                                ({{ __('availability') }} {{ $entry['availability_state'] }}, {{ __('security') }} {{ $entry['security_state'] }})
                            @else
                                <span class="font-medium">{{ __('Incident') }}</span>
                                — {{ $entry['website'] }} {{ $entry['severity'] }} ({{ $entry['status'] }})
                            @endif
                        </span>
                        <span class="text-xs text-slate-400">{{ $entry['at']?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="text-slate-500">{{ __('No activity yet.') }}</li>
                @endforelse
            </ol>
        </section>
    </div>
</x-admin-layout>
