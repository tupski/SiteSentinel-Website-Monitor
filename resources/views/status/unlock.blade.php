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
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-slate-900 dark:text-slate-200">
<main class="mx-auto max-w-md px-4 py-10">
    <h1 class="text-xl font-bold">Status page locked</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Enter password for access.</p>
    @if($errors->any())
        <div class="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            {{ e($errors->first()) }}
        </div>
    @endif
    <form method="POST" action="{{ route('status.unlock', ['statusPage' => $statusPage->slug]) }}" class="mt-4 rounded border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
        @csrf
        <x-form.field name="password" label="Password" required
                      hint="{{ __('The status-page access password.') }}"
                      help="{{ __('Enter the password set for this status page. Repeated failures are throttled. Use the eye button to reveal what you typed.') }}">
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   aria-describedby="password-hint" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                   class="block w-full rounded border border-slate-300 px-3 py-2 pr-10">
        </x-form.field>
        <button type="submit" class="mt-3 w-full rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Unlock</button>
    </form>
</main>
</body>
</html>
