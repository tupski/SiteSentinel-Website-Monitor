<x-app-layout>
    <x-slot name="title">{{ __('Sign in') }} — SiteSentinel</x-slot>

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-xl font-bold tracking-tight">SiteSentinel</h1>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Admin sign-in</p>
                </div>
                <x-theme-switcher />
            </div>

            @if (session('status'))
                <div class="mt-4 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="mt-6 space-y-4">
                @csrf

                <x-form.field name="email" :label="__('Email')" required
                              hint="{{ __('Your admin account email.') }}"
                              help="{{ __('The email address provisioned for your admin account. Accounts are created out of band — there is no self-registration.') }}">
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           aria-describedby="email-hint" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                           class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                </x-form.field>

                <x-form.field name="password" :label="__('Password')" required
                              hint="{{ __('Your account password.') }}"
                              help="{{ __('Enter your admin password. Use the eye button to reveal what you typed. Repeated failures are throttled for security.') }}">
                    <input id="password" name="password" type="password" required
                           aria-describedby="password-hint" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                           class="block w-full rounded border border-slate-300 px-3 py-2 pr-10 text-sm focus:border-slate-500 focus:outline-none">
                </x-form.field>

                <button type="submit"
                        class="w-full rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500">
                    {{ __('Sign in') }}
                </button>
            </form>

            <p class="mt-4 text-center text-sm">
                <a href="{{ route('password.request') }}" class="text-slate-600 underline hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                    {{ __('Forgot your password?') }}
                </a>
            </p>
        </div>
    </div>
</x-app-layout>
