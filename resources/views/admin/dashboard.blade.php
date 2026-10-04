<x-admin-layout>
    <x-slot name="title">{{ __('Dashboard') }} — SiteSentinel Admin</x-slot>

    <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Dashboard') }}</h1>

    {{-- AC-6-07: counters total / operational / warning / incident. --}}
    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface-elevated p-4 shadow-sm">
            <div class="text-sm text-text-muted">{{ __('Total websites') }}</div>
            <div class="mt-1 text-2xl font-bold text-text">{{ $counters['total'] }}</div>
        </div>
        <div class="rounded-lg border border-success/30 bg-success-muted p-4 shadow-sm">
            <div class="text-sm text-success">{{ __('Operational') }}</div>
            <div class="mt-1 text-2xl font-bold text-success">{{ $counters['operational'] }}</div>
        </div>
        <div class="rounded-lg border border-warning/30 bg-warning-muted p-4 shadow-sm">
            <div class="text-sm text-warning">{{ __('Warning incidents') }}</div>
            <div class="mt-1 text-2xl font-bold text-warning">{{ $counters['warning'] }}</div>
        </div>
        <div class="rounded-lg border border-danger/30 bg-danger-muted p-4 shadow-sm">
            <div class="text-sm text-danger">{{ __('Critical incidents') }}</div>
            <div class="mt-1 text-2xl font-bold text-danger">{{ $counters['incident'] }}</div>
        </div>
    </div>

    {{-- AC-21: monitoring-pipeline health/readiness (database, Redis, queue worker).
         Status only — never a credential or internal connection detail (SECURITY.md §11). --}}
    <section aria-labelledby="system-health-heading" class="mt-6 rounded-lg border {{ ($healthHealthy ?? true) ? 'border-border' : 'border-danger/40' }} bg-surface-elevated p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <h2 id="system-health-heading" class="text-lg font-semibold text-text">{{ __('System health') }}</h2>
            <x-ui.badge :variant="($healthHealthy ?? true) ? 'success' : 'danger'">
                {{ ($healthHealthy ?? true) ? __('All systems ready') : __('Pipeline degraded — check components') }}
            </x-ui.badge>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-4">
            @php($healthComponents = [
                'database' => __('Database'),
                'redis' => __('Redis'),
                'queue' => __('Queue worker'),
            ])
            @foreach ($healthComponents as $key => $label)
                @php($component = $health[$key] ?? ['status' => 'fail'])
                <div class="rounded-lg border border-border p-4">
                    <div class="text-sm text-text-muted">{{ $label }}</div>
                    <div class="mt-1 text-lg font-bold {{ ($component['status'] ?? 'fail') === 'ok' ? 'text-success' : 'text-danger' }}">
                        {{ ($component['status'] ?? 'fail') === 'ok' ? __('Ready') : __('Unavailable') }}
                    </div>
                    @if ($key === 'queue' && isset($component['pending_jobs']) && $component['pending_jobs'] >= 0)
                        <div class="mt-1 text-xs text-text-muted">{{ __('Pending jobs') }}: {{ $component['pending_jobs'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- AC-2-07 invariant: availability and security stay SEPARATE areas (AGENTS.md 15.2). --}}
    {{-- FR-101: notification failure visibility reuses counter style. --}}
    <section aria-labelledby="notifications-heading" class="mt-6 rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
        <h2 id="notifications-heading" class="text-lg font-semibold text-text">{{ __('Notifications') }}</h2>
        <div class="mt-3 grid grid-cols-2 gap-4">
            <div class="rounded-lg border border-border bg-surface-elevated p-4">
                <div class="text-sm text-text-muted">{{ __('Failed deliveries') }}</div>
                <div class="mt-1 text-2xl font-bold text-text">{{ $failedNotificationCount ?? 0 }}</div>
            </div>
            <div class="rounded-lg border border-border bg-surface-elevated p-4">
                <div class="text-sm text-text-muted">{{ __('Disabled channels') }}</div>
                <div class="mt-1 text-2xl font-bold text-text">{{ ($disabledChannels ?? collect())->count() }}</div>
            </div>
        </div>
        @if (($failedNotifications ?? collect())->isNotEmpty())
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($failedNotifications as $log)
                    <li class="flex items-center justify-between border-b border-border pb-1">
                        <span>#{{ $log->id }} — {{ $log->channel?->name ?? __('Unknown channel') }} ({{ $log->status }})</span>
                        <a href="{{ route('admin.notification-logs.index', ['status' => 'failed']) }}" class="underline text-text-muted hover:text-text">{{ __('View') }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
        @if (($disabledChannels ?? collect())->isNotEmpty())
            <p class="mt-2 text-sm text-text-muted">{{ __('Disabled') }}: {{ ($disabledChannels ?? collect())->map(fn ($c) => $c->name)->join(', ') }}</p>
        @endif
        <a href="{{ route('admin.notification-logs.index') }}" class="mt-3 inline-block text-sm text-text-muted underline hover:text-text">{{ __('Open delivery log') }}</a>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section aria-labelledby="availability-heading" class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
            <h2 id="availability-heading" class="text-lg font-semibold text-text">{{ __('Availability') }}</h2>
            <x-ui.table class="mt-3">
                <x-ui.table-head>
                    <tr class="text-left">
                        <th class="py-2">{{ __('Website') }}</th>
                        <th class="py-2">{{ __('Availability') }}</th>
                        <th class="py-2">{{ __('Last check') }}</th>
                    </tr>
                </x-ui.table-head>
                <x-ui.table-body>
                    @forelse ($websites as $website)
                        <tr>
                            <td class="py-2 font-medium">{{ $website->name }}</td>
                            <td class="py-2">{{ $website->status_availability ?? '-' }}</td>
                            <td class="py-2 text-text-muted">{{ $website->last_checked_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                    @empty
                        <x-ui.table-empty :columns="3" :title="__('No websites monitored yet.')" />
                    @endforelse
                </x-ui.table-body>
            </x-ui.table>
        </section>

        <section aria-labelledby="security-heading" class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
            <h2 id="security-heading" class="text-lg font-semibold text-text">{{ __('Security & Content Health') }}</h2>
            <x-ui.table class="mt-3">
                <x-ui.table-head>
                    <tr class="text-left">
                        <th class="py-2">{{ __('Website') }}</th>
                        <th class="py-2">{{ __('Security') }}</th>
                        <th class="py-2">{{ __('Last check') }}</th>
                    </tr>
                </x-ui.table-head>
                <x-ui.table-body>
                    @forelse ($websites as $website)
                        <tr>
                            <td class="py-2 font-medium">{{ $website->name }}</td>
                            <td class="py-2">{{ $website->status_security ?? '-' }}</td>
                            <td class="py-2 text-text-muted">{{ $website->last_checked_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                    @empty
                        <x-ui.table-empty :columns="3" :title="__('No websites monitored yet.')" />
                    @endforelse
                </x-ui.table-body>
            </x-ui.table>
        </section>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-text">{{ __('Open incidents') }}</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($openIncidents as $incident)
                    <li class="flex items-center justify-between">
                        <a href="{{ route('admin.incidents.show', $incident) }}" class="underline text-text-muted hover:text-text">
                            {{ $incident->website->name }} — {{ $incident->type }}
                        </a>
                        <span>{{ $incident->severity }} · {{ $incident->status }}</span>
                    </li>
                @empty
                    <li class="text-text-muted">{{ __('No open incidents.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- FR-61: interleaved per-website timeline of checks and incident events. --}}
        <section class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-text">{{ __('Recent activity') }}</h2>
            <ol class="mt-3 space-y-2 text-sm">
                @forelse ($timeline as $entry)
                    <li class="flex items-center justify-between border-b border-border pb-1">
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
                        <span class="text-xs text-text-subtle">{{ $entry['at']?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="text-text-muted">{{ __('No activity yet.') }}</li>
                @endforelse
            </ol>
        </section>
    </div>
</x-admin-layout>
