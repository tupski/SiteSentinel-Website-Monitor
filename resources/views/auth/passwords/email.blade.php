<x-app-layout>
    <x-slot name="title">{{ __('Reset password') }} — SiteSentinel</x-slot>

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
            <h1 class="text-xl font-bold tracking-tight text-text">{{ __('Forgot your password?') }}</h1>
            <p class="mt-1 text-sm text-text-muted">
                {{ __('Enter your admin email and we will send a reset link.') }}
            </p>

            @if (session('status'))
                <x-ui.alert variant="success" class="mt-4">
                    {{ session('status') }}
                </x-ui.alert>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
                @csrf
                <x-form.field name="email" :label="__('Email')">
                    <x-ui.input id="email" name="email" type="email" :value="old('email')" required autofocus
                                :invalid="$errors->has('email')" />
                </x-form.field>

                <x-ui.button type="submit" class="w-full">
                    {{ __('Send reset link') }}
                </x-ui.button>
            </form>

            <p class="mt-4 text-center text-sm">
                <a href="{{ route('login') }}" class="text-text-muted underline hover:text-text">{{ __('Back to sign in') }}</a>
            </p>
        </div>
    </div>
</x-app-layout>
