<x-admin-layout>
    <x-slot name="title">{{ __('Analytics') }} — {{ $page->name }} — SiteSentinel</x-slot>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Analytics') }}</h1>
                <x-ui.badge variant="neutral">/{{ $page->slug }}</x-ui.badge>
            </div>
            <p class="mt-1 text-sm text-text-muted">{{ $page->name }}</p>
        </div>
        <x-ui.button :href="route('admin.status-pages.index')" variant="secondary" size="sm">
            {{ __('Back to status pages') }}
        </x-ui.button>
    </div>

    {{-- Reporting period selector (allowlisted server-side; UTC boundaries). --}}
    <form method="GET" action="{{ route('admin.status-pages.analytics', $page) }}"
          class="mb-6 flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block text-text-muted">{{ __('Reporting period') }}</span>
            <x-ui.select name="period" class="mt-1 !w-auto" aria-label="{{ __('Reporting period') }}">
                @foreach ($periods as $value => $label)
                    <option value="{{ $value }}" @selected($analytics['period'] === $value)>{{ __($label) }}</option>
                @endforeach
            </x-ui.select>
        </label>
        <x-ui.button type="submit" variant="secondary" size="sm">{{ __('Apply') }}</x-ui.button>

        <p class="text-xs text-text-muted">
            {{ __('Showing :label', ['label' => __($analytics['periodLabel'])]) }}
            —
            {{ $analytics['periodStart']->format('Y-m-d H:i') }}
            {{ __('to') }}
            {{ $analytics['periodEnd']->format('Y-m-d H:i') }}
            {{ __('UTC') }}
        </p>
    </form>

    @if ($analytics['checksRetentionLimited'])
        <x-ui.alert variant="warning" class="mb-6">
            {{ __('Check telemetry is retained for :days days, so the availability and response-time series only cover the most recent :days days of this :period period. Incident metrics use the full period (incidents are retained 365 days).', [
                'days' => $analytics['checksRetentionDays'],
                'period' => __($analytics['periodLabel']),
            ]) }}
        </x-ui.alert>
    @endif

    {{-- Summary cards. --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface-elevated p-4 shadow-sm">
            <div class="text-sm text-text-muted">{{ __('Overall uptime') }}</div>
            @if ($analytics['overallUptime']['available'])
                <div class="mt-1 text-2xl font-bold text-text">{{ number_format($analytics['overallUptime']['percent'], 2) }}%</div>
                <div class="mt-1 text-xs text-text-muted">
                    {{ $analytics['overallUptime']['up'] }}/{{ $analytics['overallUptime']['total'] }} {{ __('checks up') }}
                </div>
            @else
                <div class="mt-1 text-lg font-semibold text-text-muted">{{ __('Insufficient data') }}</div>
                <div class="mt-1 text-xs text-text-muted">{{ __('No availability checks in this period.') }}</div>
            @endif
        </div>

        <div class="rounded-lg border border-border bg-surface-elevated p-4 shadow-sm">
            <div class="text-sm text-text-muted">{{ __('Incidents') }}</div>
            <div class="mt-1 text-2xl font-bold text-text">{{ $analytics['incidents']['total'] }}</div>
            <div class="mt-1 text-xs text-text-muted">{{ $analytics['incidents']['open'] }} {{ __('open') }}</div>
        </div>

        <div class="rounded-lg border border-border bg-surface-elevated p-4 shadow-sm">
            <div class="text-sm text-text-muted">{{ __('Incident frequency') }}</div>
            @if ($analytics['incidents']['total'] > 0)
                <div class="mt-1 text-2xl font-bold text-text">{{ $analytics['incidents']['perWeek'] }}</div>
                <div class="mt-1 text-xs text-text-muted">{{ __('per week') }} · {{ $analytics['incidents']['perDay'] }} {{ __('per day') }}</div>
            @else
                <div class="mt-1 text-2xl font-bold text-text">0</div>
                <div class="mt-1 text-xs text-text-muted">{{ __('No incidents in this period.') }}</div>
            @endif
        </div>

        <div class="rounded-lg border border-border bg-surface-elevated p-4 shadow-sm">
            <div class="text-sm text-text-muted">{{ __('Websites') }}</div>
            <div class="mt-1 text-2xl font-bold text-text">{{ $analytics['websiteCount'] }}</div>
            <div class="mt-1 text-xs text-text-muted">{{ __('on this page') }}</div>
        </div>
    </div>

    {{-- Incident breakdown. --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('Incidents by severity')">
            <ul class="space-y-2 text-sm">
                @foreach (['CRITICAL', 'WARNING', 'INFO'] as $severity)
                    <li class="flex items-center justify-between">
                        <x-ui.badge :variant="['CRITICAL' => 'danger', 'WARNING' => 'warning', 'INFO' => 'info'][$severity]">{{ $severity }}</x-ui.badge>
                        <span class="font-semibold tabular-nums text-text">{{ $analytics['incidents']['bySeverity'][$severity] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card :title="__('Incidents by status')">
            <ul class="space-y-2 text-sm">
                @foreach (['DETECTED', 'ACKNOWLEDGED', 'RESOLVED'] as $status)
                    <li class="flex items-center justify-between">
                        <span class="text-text-muted">{{ $status }}</span>
                        <span class="font-semibold tabular-nums text-text">{{ $analytics['incidents']['byStatus'][$status] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card :title="__('Incidents by type')">
            <ul class="space-y-2 text-sm">
                @foreach (['availability', 'security'] as $type)
                    <li class="flex items-center justify-between">
                        <span class="text-text-muted">{{ ucfirst($type) }}</span>
                        <span class="font-semibold tabular-nums text-text">{{ $analytics['incidents']['byType'][$type] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>

    {{-- Charts (server-rendered inline SVG, ADR-037). --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Historical availability')" :subtitle="__('Per-day share of checks that were UP (from real checks).')">
            <x-ui.chart
                type="line"
                :series="$analytics['availabilitySeries']"
                :title="__('Daily availability')"
                :max="100"
                value-suffix="%"
                :caption="$analytics['hasChecks']
                    ? __('Derived from :count checks. Days with no checks are omitted.', ['count' => $analytics['overallUptime']['total']])
                    : __('No checks were recorded in this period — availability cannot be shown.')"
            />
        </x-ui.card>

        <x-ui.card :title="__('Incident frequency')" :subtitle="__('Incidents detected per day in the selected period.')">
            <x-ui.chart
                type="bar"
                :series="$analytics['incidents']['dailySeries']"
                :title="__('Incidents per day')"
                :caption="__('Derived from the incidents table (365-day retention).')"
            />
        </x-ui.card>
    </div>

    <div class="mt-6">
        <x-ui.card :title="__('Response time trend')" :subtitle="__('Average check duration per day (from checks.duration_ms).')">
            @if ($analytics['responseTime']['available'])
                <x-ui.chart
                    type="line"
                    :series="$analytics['responseTime']['series']"
                    :title="__('Average response time')"
                    value-suffix=" ms"
                    :caption="__('Average :ms ms across :count timed checks.', ['ms' => $analytics['responseTime']['averageMs'], 'count' => $analytics['responseTime']['samples']])"
                />
            @else
                <x-ui.empty-state
                    :title="__('Response time unavailable')"
                    :description="__('No timed checks were recorded in this period. Response-time trends require check telemetry (checks.duration_ms), which is retained for :days days.', ['days' => $analytics['checksRetentionDays']])">
                    <x-slot name="icon"><x-ui.icon name="chart-bar" class="h-8 w-8" /></x-slot>
                </x-ui.empty-state>
            @endif
        </x-ui.card>
    </div>

    {{-- Per-website uptime table. --}}
    <div class="mt-6">
        <h2 class="mb-3 text-lg font-semibold text-text">{{ __('Per-website uptime') }}</h2>
        <x-ui.table>
            <x-ui.table-head>
                <tr>
                    <th scope="col" class="px-4 py-3 text-left font-medium">{{ __('Website') }}</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Uptime') }}</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Checks (up / total)') }}</th>
                </tr>
            </x-ui.table-head>
            <x-ui.table-body>
                @forelse ($analytics['websites'] as $row)
                    <tr>
                        <td class="px-4 py-3 font-medium text-text">{{ $row['name'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            @if ($row['uptime']['available'])
                                {{ number_format($row['uptime']['percent'], 2) }}%
                            @else
                                <span class="text-text-muted">{{ __('Insufficient data') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-text-muted">
                            @if ($row['uptime']['available'])
                                {{ $row['uptime']['up'] }} / {{ $row['uptime']['total'] }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.table-empty :columns="3" :title="__('No websites are assigned to this page.')" />
                @endforelse
            </x-ui.table-body>
        </x-ui.table>
    </div>

    <p class="mt-6 text-xs text-text-subtle">
        {{ __('Uptime is the share of observed checks that reported the site reachable (sample-based). It is not a time-weighted uptime. Metrics are computed only from persisted checks and incidents; nothing is estimated or fabricated.') }}
    </p>
</x-admin-layout>
