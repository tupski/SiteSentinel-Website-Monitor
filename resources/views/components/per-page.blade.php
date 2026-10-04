@props([
    'target' => null,
])

@php
    use App\Support\PerPage;

    $current = PerPage::resolve(request());
    $raw = request()->query('per_page');
    $currentValue = $current === PerPage::ALL ? 'all' : (string) $current;
@endphp

{{-- Per-page selector (ADR-034). Navigates to the current URL with the new
     `per_page` value while preserving every other query filter. --}}
<form
    method="GET"
    action="{{ $target ?? url()->current() }}"
    class="inline-flex items-center gap-2 text-sm"
    x-data="{
        change() {
            const url = new URL(this.$el.action, window.location.origin);
            const data = new FormData(this.$el);
            for (const [key, value] of data.entries()) {
                if (value !== '') url.searchParams.set(key, value);
                else url.searchParams.delete(key);
            }
            url.searchParams.delete('page');
            window.location.assign(url.toString());
        },
    }"
>
    @foreach (request()->query() as $key => $value)
        @if ($key !== 'per_page' && $key !== 'page' && ! is_array($value))
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach

    <label for="per_page" class="text-slate-600">{{ __('Per page') }}</label>
    <select
        id="per_page"
        name="per_page"
        class="rounded border border-slate-300 px-2 py-1 text-sm focus:border-slate-500 focus:outline-none"
        x-on:change="change()"
        aria-label="{{ __('Items per page') }}"
    >
        @foreach (PerPage::options() as $option)
            <option value="{{ $option }}" @selected($currentValue === (string) $option)>
                {{ $option === 'all' ? __('All') : $option }}
            </option>
        @endforeach
    </select>

    <noscript>
        <button type="submit" class="rounded bg-slate-900 px-2 py-1 text-xs font-medium text-white">{{ __('Apply') }}</button>
    </noscript>
</form>
