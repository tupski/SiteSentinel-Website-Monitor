{{--
    Browser Push opt-in section (NOTIFICATIONS.md §7.3, ADR-032).

    A "Send test push" affordance is modal-gated (ADR-034) and routes through
    the provider contract. Only the VAPID *public* key is exposed to the browser;
    the private key never reaches the page (SECURITY.md §4).
--}}
<x-ui.card
    class="mt-6"
    :padding="false"
    x-data="pushOptin({
        publicKey: @js($pushPublicKey),
        subscribeUrl: @js(route('admin.push.subscribe')),
        unsubscribeUrl: @js(route('admin.push.unsubscribe')),
        csrfToken: @js(csrf_token()),
        messages: {
            denied: @js(__('Notification permission was not granted.')),
            failed: @js(__('Could not update browser push.')),
        },
    })"
>
    <div class="p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-text">{{ __('Browser push') }}</h2>
                <x-form.field
                    name="push_enabled"
                    :label="__('Enable browser push')"
                    :hint="__('Receive incident alerts in this browser. You will be asked to allow notifications.')"
                >
                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-text">
                        <input
                            id="field-push_enabled"
                            type="checkbox"
                            class="h-4 w-4 rounded border-border-muted text-primary focus:ring-2 focus:ring-focus focus:ring-offset-0"
                            x-bind:checked="subscribed"
                            x-bind:disabled="busy || ! supported"
                            x-on:change="$event.target.checked ? subscribe() : unsubscribe()"
                        />
                        <span>{{ __('Enabled on this browser') }}</span>
                    </label>
                </x-form.field>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-xs font-medium text-text" x-text="subscribed ? @js(__('Subscribed')) : @js(__('Not subscribed'))"></span>
            </div>
        </div>

        <p x-show="! supported" x-cloak class="mt-3 text-sm text-warning">
            {{ __('Browser push is not available in this browser or VAPID keys are not configured.') }}
        </p>

        <p x-show="error" x-cloak class="mt-3 text-sm text-danger" x-text="error"></p>

        <div class="mt-4 flex items-center gap-3 border-t border-border pt-4">
            <x-ui.button
                type="button"
                variant="secondary"
                x-on:click="$dispatch('open-modal', { name: 'push-test-confirm' })"
            >{{ __('Send test push') }}</x-ui.button>
            <span class="text-xs text-text-subtle">{{ __('Sends one labelled test through the push provider.') }}</span>
        </div>

        <x-modal name="push-test-confirm" :title="__('Send test push')">
            <form method="POST" action="{{ route('admin.push.test') }}">
                @csrf
                <p class="text-sm text-text-muted">{{ __('Send a test push notification to all enabled browser subscriptions?') }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button type="button" variant="outline" x-on:click="$dispatch('close-modal', { name: 'push-test-confirm' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Send test push') }}</x-ui.button>
                </div>
            </form>
        </x-modal>
    </div>
</x-ui.card>
