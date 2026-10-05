{{-- Security + Database & Operations — security model, settings, profile. --}}
<section id="security-model" data-doc-section data-search="security model ssrf guard auth sessions secrets redaction audit throttling" class="docs-section">
    <x-docs.partials.anchor id="security-model" :title="__('Security model')" />

    <p class="docs-lead">
        {{ __('SiteSentinel is security-first by construction: least privilege, no public surface, and secret redaction at every boundary.') }}
    </p>

    <h3 class="docs-subheading">{{ __('Access control') }}</h3>
    <ul class="docs-list">
        <li>{{ __('There is no public registration route. Admin accounts are provisioned out of band.') }}</li>
        <li>{{ __('Every admin route requires authentication, an active account and the admin role.') }}</li>
        <li>{{ __('Idle and absolute session timeouts are enforced before any admin action runs.') }}</li>
    </ul>

    <h3 class="docs-subheading">{{ __('SSRF guard') }}</h3>
    <p>
        {{ __('Outbound probes resolve the target and reject loopback, RFC1918, link-local, reserved/documentation and cloud-metadata addresses, then re-validate after every redirect hop. Monitoring cannot be turned into a request-forgery tool.') }}
    </p>

    <h3 class="docs-subheading">{{ __('Secrets and redaction') }}</h3>
    <ul class="docs-list">
        <li>{{ __('Channel secrets are stored encrypted in secret_ref and never rendered back to the browser.') }}</li>
        <li>{{ __('Delivery logs, audit entries and public views are scrubbed of secret material.') }}</li>
        <li>{{ __('No telemetry is emitted to any third party.') }}</li>
    </ul>
</section>

<section id="settings" data-doc-section data-search="settings system identity branding timezone tuning version snapshot rollback pull retention" class="docs-section">
    <x-docs.partials.anchor id="settings" :title="__('System settings')" />

    <p class="docs-lead">
        {{ __('System settings are stored, applied at runtime and versioned so an operator can always return to a known-good state.') }}
    </p>

    <h3 class="docs-subheading">{{ __('What you can change') }}</h3>
    <ul class="docs-list">
        <li>{{ __('Site identity and branding — name, description, logo and favicon.') }}</li>
        <li>{{ __('Operational tuning — intervals, thresholds and timeouts within documented bounds.') }}</li>
        <li>{{ __('Timezone — every rendered timestamp honours the configured zone.') }}</li>
    </ul>

    <h3 class="docs-subheading">{{ __('Version control') }}</h3>
    <p>
        {{ __('Every save, pull and rollback is captured as an immutable snapshot with a checksum. You can pull the latest snapshot or roll back to a chosen version; the current state is snapshotted first, so a rollback is itself reversible.') }}
    </p>
</section>

<section id="profile" data-doc-section data-search="profile account password change credentials" class="docs-section">
    <x-docs.partials.anchor id="profile" :title="__('Profile & password')" />

    <p>
        {{ __('Your account screen edits the display name, email address and password. Password changes are validated against the configured minimum length and audited.') }}
    </p>
    <x-ui.alert variant="info">
        {{ __('Accounts are provisioned out of band; there is no self-service registration anywhere in the product.') }}
    </x-ui.alert>
</section>
