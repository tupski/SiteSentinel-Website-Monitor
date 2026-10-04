@props([
    'label' => null,
    'name',
    'hint' => null,
    'help' => null,
    'required' => false,
])

@php
    // `$errors` is normally shared by the framework for HTTP responses; guard so
    // the component also renders standalone (component tests, partials).
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $fieldId = $attributes->get('id', 'field-'.str_replace(['[', ']', '.'], ['-', '', '-'], $name));
    $hintId = $fieldId.'-hint';
    $errorId = $fieldId.'-error';
    $helpId = $fieldId.'-help';
    $describedBy = trim(implode(' ', array_filter([
        $hint ? $hintId : null,
        $errors->has($name) ? $errorId : null,
    ])));
    $hasHelp = filled($help) || filled($hint);
@endphp

<div {{ $attributes->only('class')->merge(['class' => 'space-y-1']) }}>
    @if ($label)
        <div class="flex items-center gap-1.5">
            <label for="{{ $fieldId }}" class="block text-sm font-medium text-slate-700">
                {{ $label }}
                @if ($required)
                    <span class="text-red-600" aria-hidden="true">*</span>
                @endif
            </label>

            @if ($hasHelp)
                <span
                    x-data="{ tip: false }"
                    class="relative inline-flex"
                    x-on:keydown.escape="tip = false"
                >
                    <button
                        type="button"
                        class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-400 text-[10px] font-semibold leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500"
                        aria-label="{{ __('Help for :field', ['field' => $label]) }}"
                        aria-describedby="{{ $helpId }}"
                        x-on:mouseenter="tip = true"
                        x-on:mouseleave="tip = false"
                        x-on:focus="tip = true"
                        x-on:blur="tip = false"
                        x-on:click="$dispatch('open-modal', { name: 'help-{{ $fieldId }}' })"
                    >?</button>

                    <span
                        x-show="tip"
                        x-cloak
                        role="tooltip"
                        class="absolute left-0 top-6 z-40 w-64 rounded bg-slate-900 px-3 py-2 text-xs font-normal text-white shadow-lg"
                    >{{ $hint ?? $help }}</span>
                </span>
            @endif
        </div>
    @endif

    <div x-data="{ shown: false }" class="relative">
        {{ $slot }}

        @if ($name === 'password' || str_contains($name, 'password'))
            <button
                type="button"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-500 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500"
                x-on:click="shown = ! shown; const i = $el.parentElement.querySelector('input'); if (i) { i.type = shown ? 'text' : 'password'; }"
                x-bind:aria-pressed="shown ? 'true' : 'false'"
                aria-label="{{ __('Show password') }}"
                :aria-label="shown ? @js(__('Hide password')) : @js(__('Show password'))"
                title="{{ __('Toggle password visibility') }}"
            >
                <svg x-show="! shown" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 3c-3.9 0-7.2 2.4-8.7 6a1 1 0 0 0 0 .7C2.8 13.6 6.1 16 10 16s7.2-2.4 8.7-6.3a1 1 0 0 0 0-.7C17.2 5.4 13.9 3 10 3Zm0 10.5A3.5 3.5 0 1 1 10 6.5a3.5 3.5 0 0 1 0 7Zm0-2A1.5 1.5 0 1 0 10 8.5a1.5 1.5 0 0 0 0 3Z" />
                </svg>
                <svg x-show="shown" x-cloak class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M3.28 2.22a.75.75 0 0 0-1.06 1.06l14.5 14.5a.75.75 0 1 0 1.06-1.06l-2.2-2.2A9.02 9.02 0 0 0 18.7 10a1 1 0 0 0 0-.7C17.2 5.4 13.9 3 10 3c-1.3 0-2.5.26-3.6.72L3.28 2.22Zm6.36 9.9-1.76-1.76a1.5 1.5 0 0 0 1.76 1.76ZM10 6.5c1.24 0 2.34.63 2.98 1.58a.75.75 0 0 1 .1.79 3.5 3.5 0 0 1-4.45 4.45.75.75 0 0 1-.79-.1A3.5 3.5 0 0 1 10 6.5Z" />
                </svg>
            </button>
        @endif
    </div>

    @if ($hint)
        <p id="{{ $hintId }}" class="text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $errorId }}" role="alert" class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    @if (filled($help))
        <x-modal :name="'help-'.$fieldId" :title="$label ?? __('Help')">
            <p id="{{ $helpId }}">{{ $help }}</p>
        </x-modal>
    @endif
</div>
