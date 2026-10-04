<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Status — Unlock</title>
    @include('partials.theme-bootstrap')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface text-text antialiased">
<main class="mx-auto max-w-md px-4 py-10">
    <h1 class="text-xl font-bold text-text">Status page locked</h1>
    <p class="mt-2 text-sm text-text-muted">Enter password for access.</p>
    @if($errors->any())
        <x-ui.alert variant="danger" class="mt-4">
            {{ $errors->first() }}
        </x-ui.alert>
    @endif
    <form method="POST" action="{{ route('status.unlock', ['statusPage' => $statusPage->slug]) }}" class="mt-4 rounded border border-border bg-surface-elevated p-4">
        @csrf
        <x-form.field name="password" label="Password" required
                      hint="{{ __('The status-page access password.') }}"
                      help="{{ __('Enter the password set for this status page. Repeated failures are throttled. Use the eye button to reveal what you typed.') }}">
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   aria-describedby="password-hint" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                   class="block w-full rounded border border-border-muted px-3 py-2 pr-10 text-text focus:border-focus focus:outline-none focus:ring-2 focus:ring-focus">
        </x-form.field>
        <x-ui.button type="submit" class="mt-3 w-full">Unlock</x-ui.button>
    </form>
</main>
</body>
</html>
