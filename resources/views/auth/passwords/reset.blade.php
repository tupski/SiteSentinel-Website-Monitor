<x-app-layout>
    <x-slot name="title">{{ __('Choose a new password') }} — SiteSentinel</x-slot>

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
            <h1 class="text-xl font-bold tracking-tight text-text">{{ __('Choose a new password') }}</h1>

            <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <x-form.field name="email" :label="__('Email')">
                    <x-ui.input id="email" name="email" type="email" :value="old('email', $email)" required
                                :invalid="$errors->has('email')" />
                </x-form.field>

                <x-form.field name="password" :label="__('New password')" hint="{{ __('Minimum 12 characters.') }}">
                    <x-ui.input id="password" name="password" type="password" required minlength="12"
                                :invalid="$errors->has('password')" />
                </x-form.field>

                <x-form.field name="password_confirmation" :label="__('Confirm new password')">
                    <x-ui.input id="password_confirmation" name="password_confirmation" type="password" required minlength="12"
                                :invalid="$errors->has('password_confirmation')" />
                </x-form.field>

                <x-ui.button type="submit" class="w-full">
                    {{ __('Reset password') }}
                </x-ui.button>
            </form>
        </div>
    </div>
</x-app-layout>
