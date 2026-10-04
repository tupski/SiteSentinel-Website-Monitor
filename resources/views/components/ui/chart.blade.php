@props([
    'type' => 'bar',
    'series' => [],
    'title' => null,
    'caption' => null,
    'max' => null,
    'valueSuffix' => '',
    'height' => 180,
])

@php
    // Server-rendered inline SVG chart (ADR-037). No JS chart library, no
    // fabricated data: the caller passes a real series, and an empty series
    // renders an explicit empty state. The chart is responsive via viewBox +
    // preserveAspectRatio + `w-full max-w-full` (it can never push the page
    // into horizontal overflow), theme-aware via `currentColor`, and carries
    // BOTH an accessible <title>/aria-label and a visible data table fallback.
    $points = collect($series)->values()->map(static function ($point): array {
        return [
            'label' => (string) ($point['label'] ?? ''),
            'value' => (float) ($point['value'] ?? 0),
        ];
    })->all();

    $count = count($points);
    $hasData = $count > 0;

    $dataMax = $hasData ? max(array_column($points, 'value')) : 0.0;
    $scaleMax = $max !== null ? (float) $max : $dataMax;
    if ($scaleMax <= 0) {
        $scaleMax = 1.0;
    }

    $viewW = 600;
    $viewH = max(80, (int) $height);
    $padTop = 16;
    $padBottom = 30;
    $padLeft = 10;
    $padRight = 10;
    $plotW = $viewW - $padLeft - $padRight;
    $plotH = $viewH - $padTop - $padBottom;

    $step = $count > 0 ? $plotW / $count : $plotW;
    $barGap = $count > 1 ? min(10.0, $step * 0.25) : 0.0;
    $barW = max(2.0, $step - $barGap);

    $bars = [];
    $linePoints = [];
    foreach ($points as $i => $point) {
        $ratio = $point['value'] / $scaleMax;
        $h = max(0.0, min(1.0, $ratio)) * $plotH;
        $x = $padLeft + $i * $step + ($barGap / 2);
        $y = $padTop + ($plotH - $h);
        $bars[] = ['x' => round($x, 2), 'y' => round($y, 2), 'w' => round($barW, 2), 'h' => round($h, 2)];

        $cx = $padLeft + $i * $step + ($step / 2);
        $linePoints[] = round($cx, 2).','.round($y, 2);
    }
    $linePointsStr = implode(' ', $linePoints);

    $baselineY = $padTop + $plotH;
    $ariaLabel = trim((string) ($title ?? $caption ?? 'Chart'));
    $ariaLabel .= ' — '.$count.' '.($count === 1 ? 'data point' : 'data points');
@endphp

<figure {{ $attributes->merge(['class' => 'max-w-full']) }}>
    @if (! $hasData)
        <x-ui.empty-state :title="$title" :description="$caption ?? __('No data for the selected period.')">
            <x-slot name="icon">
                <x-ui.icon name="chart-bar" class="h-8 w-8" />
            </x-slot>
        </x-ui.empty-state>
    @else
        <svg
            viewBox="0 0 {{ $viewW }} {{ $viewH }}"
            preserveAspectRatio="xMidYMid meet"
            class="h-auto w-full max-w-full"
            role="img"
            aria-label="{{ $ariaLabel }}"
        >
            <title>{{ $ariaLabel }}</title>

            {{-- Axis + baseline (semantic tokens, inherits the theme). --}}
            <line x1="{{ $padLeft }}" y1="{{ $baselineY }}" x2="{{ $viewW - $padRight }}" y2="{{ $baselineY }}"
                  class="text-border" stroke="currentColor" stroke-width="1" opacity="0.6" />

            @if ($type === 'line')
                <polyline
                    points="{{ $linePointsStr }}"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="text-info"
                />
                @foreach ($bars as $bar)
                    <circle cx="{{ $bar['x'] + ($bar['w'] / 2) }}" cy="{{ $bar['y'] }}" r="2.5"
                            fill="currentColor" class="text-info" />
                @endforeach
            @else
                @foreach ($bars as $bar)
                    <rect
                        x="{{ $bar['x'] }}"
                        y="{{ $bar['y'] }}"
                        width="{{ $bar['w'] }}"
                        height="{{ $bar['h'] }}"
                        rx="2"
                        fill="currentColor"
                        class="text-info"
                    />
                @endforeach
            @endif
        </svg>

        @if ($caption)
            <figcaption class="mt-2 text-xs text-text-muted">{{ $caption }}</figcaption>
        @endif

        {{-- Visible fallback table: the underlying numbers, always readable
             without the chart (accessibility + "no fabricated data"). --}}
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-xs text-text-muted">
                <caption class="sr-only">{{ $title ?? $caption ?? __('Chart data') }}</caption>
                <thead>
                    <tr class="text-text-subtle">
                        <th scope="col" class="py-1 pr-4 font-medium">{{ __('Label') }}</th>
                        <th scope="col" class="py-1 font-medium">{{ __('Value') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($points as $point)
                        <tr class="border-t border-border">
                            <td class="py-1 pr-4">{{ $point['label'] }}</td>
                            <td class="py-1 tabular-nums">{{ $point['value'] }}{{ $valueSuffix }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</figure>
