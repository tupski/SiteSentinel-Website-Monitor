@props([
    // Admins are the only users of the shell, so fall back gracefully if the
    // name is somehow absent rather than emitting an empty avatar.
    'name' => null,
    'email' => null,
])

@php
    $user = auth()->user();
    $name = $name ?: ($user?->name ?? __('Admin'));
    $email = $email ?: ($user?->email ?? '');

    // Initials from the display name — first letter of the first two words.
    $initials = collect(preg_split('/\s+/', trim((string) $name)))
        ->filter()
        ->take(2)
        ->map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');

    if ($initials === '') {
        $initials = mb_strtoupper(mb_substr((string) $name, 0, 2));
    }
@endphp

<div
    x-data="profileMenu"
    x-on:click.outside="closeMenu()"
    x-on:keydown.escape.window="open && closeMenu(true)"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    <button
        type="button"
        data-profile-trigger
        x-ref="trigger"
        x-on:click="toggle()"
        x-on:keydown="onTriggerKeydown($event)"
        aria-haspopup="menu"
        aria-expanded="false"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        aria-label="{{ __('Account menu') }}"
        title="{{ __('Account menu') }}"
        class="inline-flex items-center justify-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated"
    >
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary text-sm font-semibold text-primary-foreground" aria-hidden="true">
            {{ $initials }}
        </span>
    </button>

    <div
        x-ref="menu"
        x-show="open"
        x-cloak
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.tab="trap($event)"
        role="menu"
        aria-label="{{ __('Account menu') }}"
        class="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-lg border border-border bg-surface-elevated shadow-lg focus:outline-none"
    >
        <div class="border-b border-border px-4 py-3">
            <p class="truncate text-sm font-semibold text-text">{{ $name }}</p>
            @if ($email !== '')
                <p class="truncate text-xs text-text-muted">{{ $email }}</p>
            @endif
        </div>

        <div class="p-1">
            <a
                href="{{ route('admin.profile.edit') }}"
                data-profile-item
                role="menuitem"
                tabindex="-1"
                class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-text-muted hover:bg-surface-hover hover:text-text focus:bg-surface-hover focus:text-text focus:outline-none"
            >
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 21a8 8 0 0 0-16 0" /><circle cx="12" cy="8" r="4" />
                </svg>
                {{ __('View / Edit Profile') }}
            </a>

            <a
                href="{{ route('admin.settings.edit') }}"
                data-profile-item
                role="menuitem"
                tabindex="-1"
                class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-text-muted hover:bg-surface-hover hover:text-text focus:bg-surface-hover focus:text-text focus:outline-none"
            >
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" />
                </svg>
                {{ __('Settings') }}
            </a>
        </div>

        <div class="border-t border-border p-1">
            {{-- Real form POST — logout semantics preserved (not a GET link). --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button
                    type="submit"
                    data-profile-item
                    role="menuitem"
                    tabindex="-1"
                    class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-danger hover:bg-danger-muted focus:bg-danger-muted focus:outline-none"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" />
                    </svg>
                    {{ __('Log out') }}
                </button>
            </form>
        </div>
    </div>
</div>
