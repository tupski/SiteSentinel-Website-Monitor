<x-app-layout>
    <x-slot name="title">{{ __('Sign in') }} — SiteSentinel</x-slot>

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-text">SiteSentinel</h1>
                    <p class="mt-1 text-sm text-text-muted">Admin sign-in</p>
                </div>
                <x-theme-switcher />
            </div>

            @if (session('status'))
                <x-ui.alert variant="success" class="mt-4" :dismissible="true">
                    {{ session('status') }}
                </x-ui.alert>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="mt-6 space-y-4">
                @csrf

                <x-form.field name="email" :label="__('Email')" required
                              hint="{{ __('Your admin account email.') }}"
                              help="{{ __('The email address provisioned for your admin account. Accounts are created out of band — there is no self-registration.') }}">
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           aria-describedby="email-hint" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                           class="block w-full rounded border border-border-muted px-3 py-2 text-sm text-text placeholder:text-text-subtle focus:border-focus focus:outline-none focus:ring-2 focus:ring-focus">
                </x-form.field>

                <x-form.field name="password" :label="__('Password')" required
                              hint="{{ __('Your account password.') }}"
                              help="{{ __('Enter your admin password. Use the eye button to reveal what you typed. Repeated failures are throttled for security.') }}">
                    <input id="password" name="password" type="password" required
                           aria-describedby="password-hint" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                           class="block w-full rounded border border-border-muted px-3 py-2 pr-10 text-sm text-text placeholder:text-text-subtle focus:border-focus focus:outline-none focus:ring-2 focus:ring-focus">
                </x-form.field>

                <x-ui.button type="submit" class="w-full">
                    {{ __('Sign in') }}
                </x-ui.button>
            </form>

            <p class="mt-4 text-center text-sm">
                <a href="{{ route('password.request') }}" class="text-text-muted underline hover:text-text">
                    {{ __('Forgot your password?') }}
                </a>
            </p>
        </div>
    </div>
</x-app-layout>
