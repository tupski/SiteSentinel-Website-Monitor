@props([
    'name' => 'modal',
    'title' => null,
    'maxWidth' => 'max-w-lg',
])

{{-- Reusable Alpine modal (ADR-034). Focus-trapped, closes on Escape/backdrop,
     keyboard accessible. Open it from anywhere with `$dispatch('open-modal', { name: '{{ $name }}' })`. --}}
<div
    x-data="modal"
    x-on:open-modal.window="if ($event.detail.name === '{{ $name }}') { trigger = $event.detail.trigger || null; open = true; }"
    x-on:close-modal.window="if (!$event.detail.name || $event.detail.name === '{{ $name }}') { open = false; }"
    x-show="open"
    x-cloak
    x-on:keydown.escape.window="close()"
    x-on:keydown.tab="trap($event)"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $name }}-modal-title"
    class="fixed inset-0 z-50 overflow-y-auto"
    style="display: none;"
>
    <div class="flex min-h-screen items-center justify-center px-4 py-8">
        <div
            x-show="open"
            x-transition.opacity
            x-on:click="close()"
            class="fixed inset-0 bg-slate-900/50"
            aria-hidden="true"
        ></div>

        <div
            x-ref="panel"
            x-show="open"
            x-transition
            tabindex="-1"
            class="relative w-full {{ $maxWidth }} rounded-lg bg-white p-6 shadow-xl focus:outline-none"
        >
            @if ($title)
                <div class="mb-4 flex items-start justify-between gap-4">
                    <h2 id="{{ $name }}-modal-title" class="text-lg font-semibold text-slate-900">{{ $title }}</h2>
                    <button type="button" x-on:click="close()" aria-label="{{ __('Close') }}"
                            class="rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-500">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                    </button>
                </div>
            @endif

            <div class="text-sm text-slate-700">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="mt-6 flex items-center justify-end gap-3">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
