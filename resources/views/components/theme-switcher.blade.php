@props([
    'class' => '',
])

{{-- Icon-only theme dropdown (ADR-033). Bound to the global Alpine `theme`
     store in resources/js/app.js; the `themeMenu` component adds roving focus,
     focus trap and outside-click/Escape handling. The collapsed trigger shows
     ONLY the active theme's icon — the theme names live inside the opened
     `role="menu"` panel. --}}
<div
    x-data="themeMenu"
    x-on:click.outside="closeMenu()"
    x-on:keydown.escape.window="open && closeMenu(true)"
    {{ $attributes->merge(['class' => 'relative inline-block '.$class]) }}
>
    <button
        type="button"
        data-theme-trigger
        x-ref="trigger"
        x-on:click="toggle()"
        x-on:keydown="onTriggerKeydown($event)"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        x-bind:aria-label="'{{ __('Theme') }}: ' + modeLabel() + ' — {{ __('change theme') }}'"
        aria-label="{{ __('Change theme') }}"
        aria-haspopup="menu"
        aria-expanded="false"
        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-white dark:focus-visible:ring-slate-400"
    >
        {{-- Sun (light) --}}
        <svg x-show="$store.theme.mode === 'light'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
        </svg>
        {{-- Moon (dark) --}}
        <svg x-show="$store.theme.mode === 'dark'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
        </svg>
        {{-- Monitor (system) --}}
        <svg x-show="$store.theme.mode === 'system'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3.75 5.25A2.25 2.25 0 0 1 6 3h12a2.25 2.25 0 0 1 2.25 2.25v7.5A2.25 2.25 0 0 1 18 15H6a2.25 2.25 0 0 1-2.25-2.25v-7.5Z" />
            <path d="M9 18.75h6M12 15v3.75" />
        </svg>
    </button>

    <div
        x-ref="menu"
        x-show="open"
        x-cloak
        x-transition.origin.top.right
        role="menu"
        aria-label="{{ __('Theme') }}"
        x-on:keydown.tab="trap($event)"
        class="absolute right-0 z-20 mt-2 w-44 rounded-md border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-800"
    >
        <div role="presentation" class="px-2 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            {{ __('Theme') }}
        </div>

        @foreach (['light' => __('Light'), 'dark' => __('Dark'), 'system' => __('System')] as $mode => $label)
            <button
                type="button"
                data-theme-option
                role="menuitemradio"
                tabindex="-1"
                aria-checked="false"
                x-bind:aria-checked="$store.theme.mode === '{{ $mode }}' ? 'true' : 'false'"
                x-bind:class="$store.theme.mode === '{{ $mode }}' ? 'bg-slate-100 font-medium text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-700 dark:text-slate-200'"
                x-on:click="select('{{ $mode }}')"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter.prevent="select('{{ $mode }}')"
                x-on:keydown.space.prevent="select('{{ $mode }}')"
                x-on:keydown.escape.prevent="closeMenu(true)"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-slate-100 focus:outline-none focus-visible:bg-slate-100 dark:hover:bg-slate-700 dark:focus-visible:bg-slate-700"
            >
                @switch($mode)
                    @case('light')
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                        @break
                    @case('dark')
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                        </svg>
                        @break
                    @default
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3.75 5.25A2.25 2.25 0 0 1 6 3h12a2.25 2.25 0 0 1 2.25 2.25v7.5A2.25 2.25 0 0 1 18 15H6a2.25 2.25 0 0 1-2.25-2.25v-7.5Z" />
                            <path d="M9 18.75h6M12 15v3.75" />
                        </svg>
                @endswitch

                <span class="flex-1">{{ $label }}</span>

                {{-- Non-colour active marker: check glyph --}}
                <svg x-show="$store.theme.mode === '{{ $mode }}'" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4.5 12.75l6 6 9-13.5" />
                </svg>
            </button>
        @endforeach
    </div>
</div>
