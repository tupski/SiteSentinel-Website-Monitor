@props([
    'class' => '',
])

{{-- Light / Dark / System theme control (ADR-033). Bound to the global
     Alpine `theme` store defined in resources/js/app.js. --}}
<div
    {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full border border-slate-300 bg-white p-0.5 text-xs font-medium '.$class]) }}
    role="group"
    aria-label="{{ __('Colour theme') }}"
>
    <button
        type="button"
        x-on:click="$store.theme.set('light')"
        x-bind:aria-pressed="$store.theme.mode === 'light' ? 'true' : 'false'"
        x-bind:class="$store.theme.mode === 'light' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
        class="rounded-full px-2.5 py-1 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-500"
    >{{ __('Light') }}</button>
    <button
        type="button"
        x-on:click="$store.theme.set('dark')"
        x-bind:aria-pressed="$store.theme.mode === 'dark' ? 'true' : 'false'"
        x-bind:class="$store.theme.mode === 'dark' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
        class="rounded-full px-2.5 py-1 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-500"
    >{{ __('Dark') }}</button>
    <button
        type="button"
        x-on:click="$store.theme.set('system')"
        x-bind:aria-pressed="$store.theme.mode === 'system' ? 'true' : 'false'"
        x-bind:class="$store.theme.mode === 'system' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
        class="rounded-full px-2.5 py-1 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-500"
    >{{ __('System') }}</button>
</div>
