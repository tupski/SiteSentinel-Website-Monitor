<x-admin-layout>
    <x-slot name="title">{{ __('Incident') }} #{{ $incident->id }} — SiteSentinel Admin</x-slot>

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight">
            {{ __('Incident') }} #{{ $incident->id }} — {{ $incident->website->name }}
        </h1>
        <a href="{{ route('admin.incidents.index') }}" class="text-sm text-slate-600 underline hover:text-slate-900">{{ __('Back to incidents') }}</a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">{{ __('Summary') }}</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-slate-500">{{ __('Website') }}</dt>
                        <dd class="font-medium">{{ $incident->website->name }} ({{ $incident->website->url }})</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __('Type') }}</dt>
                        <dd class="font-medium">{{ $incident->type }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __('Severity') }}</dt>
                        <dd class="font-medium">{{ $incident->severity }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __('Status') }}</dt>
                        <dd class="font-medium">{{ $incident->status }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __('Score') }}</dt>
                        <dd class="font-medium">{{ $incident->score }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __('Detected at') }}</dt>
                        <dd class="font-medium">{{ $incident->detected_at?->format('Y-m-d H:i:s') }}</dd>
                    </div>
                    @if ($incident->acknowledged_at)
                        <div>
                            <dt class="text-slate-500">{{ __('Acknowledged') }}</dt>
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
                            <dt class="text-slate-500">{{ __('Resolved') }}</dt>
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
                    <p class="mt-4 text-sm text-slate-700">{{ $incident->message }}</p>
                @endif
            </section>

            @if ($incident->type === 'security' && is_array($incident->triggered_rules) && $incident->triggered_rules !== [])
                {{-- AC-6-06 / FR-49: exact rule attribution must be visible. --}}
                <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">{{ __('Rule attribution') }}</h2>
                    <table class="mt-3 min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="py-2">{{ __('Rule') }}</th>
                                <th class="py-2">{{ __('Category') }}</th>
                                <th class="py-2">{{ __('Weight') }}</th>
                                <th class="py-2">{{ __('Confidence') }}</th>
                                <th class="py-2">{{ __('Reason') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($incident->triggered_rules as $ruleId => $meta)
                                <tr>
                                    <td class="py-2 font-mono">{{ $ruleId }}</td>
                                    <td class="py-2">{{ $meta['category'] ?? '—' }}</td>
                                    <td class="py-2">{{ $meta['weight'] ?? '—' }}</td>
                                    <td class="py-2">{{ $meta['confidence'] ?? '—' }}</td>
                                    <td class="py-2 text-slate-600">{{ $meta['reason'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            @if ($incident->type === 'availability' && is_array($incident->technical_metadata))
                {{-- AC-6-06: availability incidents show the failure classification. --}}
                <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">{{ __('Failure classification') }}</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-slate-500">{{ __('Error type') }}</dt>
                            <dd class="font-mono">{{ $incident->technical_metadata['failure_classification'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ __('Consecutive failures') }}</dt>
                            <dd>{{ $incident->technical_metadata['consecutive_failures'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ __('Last HTTP status') }}</dt>
                            <dd>{{ $incident->technical_metadata['http_status'] ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>
            @endif

            {{-- FR-57: immutable audit trail of state transitions. --}}
            <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">{{ __('Timeline') }}</h2>
                <ol class="mt-3 space-y-3 text-sm">
                    @forelse ($incident->events as $event)
                        <li class="border-l-2 border-slate-200 pl-4">
                            <div class="font-medium">
                                {{ $event->event_type }}
                                @if ($event->from_status || $event->to_status)
                                    <span class="text-slate-500">
                                        ({{ $event->from_status ?? '-' }} -> {{ $event->to_status ?? '-' }})
                                    </span>
                                @endif
                            </div>
                            @if ($event->actor)
                                <div class="text-slate-500">{{ $event->actor->name }}</div>
                            @endif
                            @if ($event->note)
                                <div class="text-slate-600">{{ $event->note }}</div>
                            @endif
                            <div class="text-xs text-slate-400">{{ $event->created_at->format('Y-m-d H:i:s') }}</div>
                        </li>
                    @empty
                        <li class="text-slate-500">{{ __('No events recorded.') }}</li>
                    @endforelse
                </ol>
            </section>
        </div>

        <div class="space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">{{ __('Actions') }}</h2>
                @if ($incident->status === 'DETECTED')
                    <form method="POST" action="{{ route('admin.incidents.acknowledge', $incident) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="w-full rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                            {{ __('Acknowledge') }}
                        </button>
                    </form>
                @endif
                @if ($incident->status !== 'RESOLVED')
                    <form method="POST" action="{{ route('admin.incidents.resolve', $incident) }}" class="mt-3">
                        @csrf
                        <label class="block text-sm text-slate-600">{{ __('Resolution notes') }}</label>
                        <textarea name="resolution_notes" rows="3" class="mt-1 w-full rounded border-slate-300 text-sm"></textarea>
                        <button type="submit" class="mt-2 w-full rounded bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800">
                            {{ __('Resolve') }}
                        </button>
                    </form>
                @else
                    <p class="mt-3 text-sm text-slate-500">{{ __('This incident is resolved and terminal.') }}</p>
                @endif
            </section>

            @if ($incident->snapshots->isNotEmpty())
                {{-- Evidence is admin-only and rendered as data, never as markup (AGENTS.md §11). --}}
                <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">{{ __('Evidence snapshots') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($incident->snapshots as $snapshot)
                            <li>
                                <span class="font-mono text-xs">{{ $snapshot->html_path }}</span>
                                <span class="text-slate-400">— {{ $snapshot->captured_at->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</x-admin-layout>
