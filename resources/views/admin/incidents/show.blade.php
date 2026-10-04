<x-admin-layout>
    <x-slot name="title">{{ __('Incident') }} #{{ $incident->id }} — SiteSentinel Admin</x-slot>

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-text">
            {{ __('Incident') }} #{{ $incident->id }} — {{ $incident->website->name }}
        </h1>
        <a href="{{ route('admin.incidents.index') }}" class="text-sm text-text-muted underline hover:text-text">{{ __('Back to incidents') }}</a>
    </div>

    @if (session('status'))
        <x-ui.alert variant="success" class="mb-4" :dismissible="true">
            {{ session('status') }}
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <section class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-text">{{ __('Summary') }}</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-text-muted">{{ __('Website') }}</dt>
                        <dd class="font-medium">{{ $incident->website->name }} ({{ $incident->website->url }})</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('Type') }}</dt>
                        <dd class="font-medium">{{ $incident->type }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('Severity') }}</dt>
                        <dd class="font-medium">{{ $incident->severity }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('Status') }}</dt>
                        <dd class="font-medium">{{ $incident->status }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('Score') }}</dt>
                        <dd class="font-medium">{{ $incident->score }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ __('Detected at') }}</dt>
                        <dd class="font-medium">{{ $incident->detected_at?->format('Y-m-d H:i:s') }}</dd>
                    </div>
                    @if ($incident->acknowledged_at)
                        <div>
                            <dt class="text-text-muted">{{ __('Acknowledged') }}</dt>
                            <dd class="font-medium">
                                {{ $incident->acknowledged_at->format('Y-m-d H:i:s') }}
                                @if ($incident->acknowledgedBy)
                                    — {{ $incident->acknowledgedBy->name }}
                                @endif
                            </dd>
                        </div>
                    @endif
                    @if ($incident->resolved_at)
                        <div>
                            <dt class="text-text-muted">{{ __('Resolved') }}</dt>
                            <dd class="font-medium">
                                {{ $incident->resolved_at->format('Y-m-d H:i:s') }}
                                @if ($incident->resolvedBy)
                                    — {{ $incident->resolvedBy->name }}
                                @endif
                                ({{ $incident->resolution_mode }})
                            </dd>
                        </div>
                    @endif
                </dl>
                @if ($incident->message)
                    <p class="mt-4 text-sm text-text-muted">{{ $incident->message }}</p>
                @endif
            </section>

            @if ($incident->type === 'security' && is_array($incident->triggered_rules) && $incident->triggered_rules !== [])
                {{-- AC-6-06 / FR-49: exact rule attribution must be visible. --}}
                <section class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-text">{{ __('Rule attribution') }}</h2>
                    <x-ui.table class="mt-3">
                        <x-ui.table-head>
                            <tr class="text-left">
                                <th class="py-2">{{ __('Rule') }}</th>
                                <th class="py-2">{{ __('Category') }}</th>
                                <th class="py-2">{{ __('Weight') }}</th>
                                <th class="py-2">{{ __('Confidence') }}</th>
                                <th class="py-2">{{ __('Reason') }}</th>
                            </tr>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @foreach ($incident->triggered_rules as $ruleId => $meta)
                                <tr>
                                    <td class="py-2 font-mono">{{ $ruleId }}</td>
                                    <td class="py-2">{{ $meta['category'] ?? '—' }}</td>
                                    <td class="py-2">{{ $meta['weight'] ?? '—' }}</td>
                                    <td class="py-2">{{ $meta['confidence'] ?? '—' }}</td>
                                    <td class="py-2 text-text-muted">{{ $meta['reason'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table-body>
                    </x-ui.table>
                </section>
            @endif

            @if ($incident->type === 'availability' && is_array($incident->technical_metadata))
                {{-- AC-6-06: availability incidents show the failure classification. --}}
                <section class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-text">{{ __('Failure classification') }}</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-text-muted">{{ __('Error type') }}</dt>
                            <dd class="font-mono">{{ $incident->technical_metadata['failure_classification'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-text-muted">{{ __('Consecutive failures') }}</dt>
                            <dd>{{ $incident->technical_metadata['consecutive_failures'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-text-muted">{{ __('Last HTTP status') }}</dt>
                            <dd>{{ $incident->technical_metadata['http_status'] ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>
            @endif

            {{-- FR-71: delivery history for incident stays admin-only with escaped output. --}}
            <section class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-text">{{ __('Delivery history') }}</h2>
                    <a href="{{ route('admin.notification-logs.index', ['incident_id' => $incident->id]) }}" class="text-sm text-text-muted underline hover:text-text">{{ __('View in logs') }}</a>
                </div>
                <x-ui.table class="mt-3">
                    <x-ui.table-head>
                        <tr class="text-left">
                            <th class="py-2">{{ __('Channel') }}</th>
                            <th class="py-2">{{ __('Status') }}</th>
                            <th class="py-2">{{ __('Attempt') }}</th>
                            <th class="py-2">{{ __('Error') }}</th>
                            <th class="py-2">{{ __('At') }}</th>
                        </tr>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse (($deliveryLogs ?? $incident->notificationLogs ?? collect()) as $log)
                            <tr>
                                <td class="py-2">{{ $log->channel?->name ?? '—' }} <span class="text-text-subtle">({{ $log->channel?->type ?? '—' }})</span></td>
                                <td class="py-2">{{ $log->status }}</td>
                                <td class="py-2">{{ $log->attempt }}</td>
                                <td class="py-2 text-text-muted">{{ $log->error ?? '—' }}</td>
                                <td class="py-2 text-text-muted">{{ ($log->sent_at ?? $log->created_at)?->format('Y-m-d H:i:s') ?? '—' }}</td>
                            </tr>
                        @empty
                            <x-ui.table-empty :columns="5" :title="__('No deliveries recorded.')" />
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </section>

            {{-- FR-57: immutable audit trail of state transitions. --}}
            <section class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-text">{{ __('Timeline') }}</h2>
                <ol class="mt-3 space-y-3 text-sm">
                    @forelse ($incident->events as $event)
                        <li class="border-l-2 border-border pl-4">
                            <div class="font-medium">
                                {{ $event->event_type }}
                                @if ($event->from_status || $event->to_status)
                                    <span class="text-text-muted">
                                        ({{ $event->from_status ?? '-' }} -> {{ $event->to_status ?? '-' }})
                                    </span>
                                @endif
                            </div>
                            @if ($event->actor)
                                <div class="text-text-muted">{{ $event->actor->name }}</div>
                            @endif
                            @if ($event->note)
                                <div class="text-text-muted">{{ $event->note }}</div>
                            @endif
                            <div class="text-xs text-text-subtle">{{ $event->created_at->format('Y-m-d H:i:s') }}</div>
                        </li>
                    @empty
                        <li class="text-text-muted">{{ __('No events recorded.') }}</li>
                    @endforelse
                </ol>
            </section>
        </div>

        <div class="space-y-6">
            <section class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-text">{{ __('Actions') }}</h2>
                @if ($incident->status === 'DETECTED')
                    <form method="POST" action="{{ route('admin.incidents.acknowledge', $incident) }}" class="mt-3">
                        @csrf
                        <x-ui.button type="submit" variant="warning" class="w-full">
                            {{ __('Acknowledge') }}
                        </x-ui.button>
                    </form>
                @endif
                @if ($incident->status !== 'RESOLVED')
                    <form method="POST" action="{{ route('admin.incidents.resolve', $incident) }}" class="mt-3">
                        @csrf
                        <label class="block text-sm text-text-muted">{{ __('Resolution notes') }}</label>
                        <x-ui.textarea name="resolution_notes" rows="3" class="mt-1" />
                        <x-ui.button type="submit" variant="success" class="mt-2 w-full">
                            {{ __('Resolve') }}
                        </x-ui.button>
                    </form>
                @else
                    <p class="mt-3 text-sm text-text-muted">{{ __('This incident is resolved and terminal.') }}</p>
                @endif
            </section>

            @if ($incident->snapshots->isNotEmpty())
                {{-- Evidence is admin-only and rendered as data, never as markup (AGENTS.md §11). --}}
                <section class="rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-text">{{ __('Evidence snapshots') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($incident->snapshots as $snapshot)
                            <li>
                                <span class="font-mono text-xs">{{ $snapshot->html_path }}</span>
                                <span class="text-text-subtle">— {{ $snapshot->captured_at->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</x-admin-layout>
