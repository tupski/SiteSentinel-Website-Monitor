{{-- The Basics — navigation, dashboard, websites, incidents. --}}
<section id="navigation" data-doc-section data-search="navigation menu areas routes admin shell" class="docs-section">
    <x-docs.partials.anchor id="navigation" :title="__('Navigation')" />

    <p class="docs-lead">
        {{ __('The admin shell groups every screen. Each area below is linked by its real route so the guide cannot drift from the product.') }}
    </p>

    <table class="docs-table">
        <thead>
            <tr>
                <th>{{ __('Area') }}</th>
                <th>{{ __('Purpose') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><a href="{{ route('admin.dashboard') }}">{{ __('Dashboard') }}</a></td>
                <td>{{ __('Health at a glance.') }}</td>
            </tr>
            <tr>
                <td><a href="{{ route('admin.websites.index') }}">{{ __('Websites') }}</a></td>
                <td>{{ __('The monitored fleet and per-site actions.') }}</td>
            </tr>
            <tr>
                <td><a href="{{ route('admin.incidents.index') }}">{{ __('Incidents') }}</a></td>
                <td>{{ __('Open, acknowledged and resolved incidents.') }}</td>
            </tr>
            <tr>
                <td><a href="{{ route('admin.notifications.index') }}">{{ __('Notification') }}</a></td>
                <td>{{ __('Delivery channels and tests.') }}</td>
            </tr>
            <tr>
                <td><a href="{{ route('admin.notification-logs.index') }}">{{ __('Delivery log') }}</a></td>
                <td>{{ __('What was sent, and whether it succeeded.') }}</td>
            </tr>
            <tr>
                <td><a href="{{ route('admin.status-pages.index') }}">{{ __('Status pages') }}</a></td>
                <td>{{ __('Public or private status pages.') }}</td>
            </tr>
            <tr>
                <td><a href="{{ route('admin.profile.edit') }}">{{ __('Profile') }}</a></td>
                <td>{{ __('Your account and password.') }}</td>
            </tr>
            <tr>
                <td><a href="{{ route('admin.settings.edit') }}">{{ __('Settings') }}</a></td>
                <td>{{ __('Site identity, branding and tuning.') }}</td>
            </tr>
            <tr>
                <td><a href="{{ route('admin.documentation') }}">{{ __('Documentation') }}</a></td>
                <td>{{ __('This guide.') }}</td>
            </tr>
        </tbody>
    </table>

    <p class="docs-muted">
        {{ __('This documentation page uses its own dedicated layout with a grouped sidebar and a per-page table of contents; the link above returns you to it from the admin shell.') }}
    </p>
</section>

<section id="dashboard" data-doc-section data-search="dashboard health widget summary" class="docs-section">
    <x-docs.partials.anchor id="dashboard" :title="__('Dashboard')" />

    <p>
        {{ __('The dashboard is the entry point after sign-in. It summarises system health and the state of the fleet so you can spot a problem without opening each screen.') }}
    </p>
    <ul class="docs-list">
        <li>{{ __('A health widget reports the state of the application, queue and scheduler.') }}</li>
        <li>{{ __('Recent incidents and websites needing attention are surfaced above the fold.') }}</li>
    </ul>
</section>

<section id="websites" data-doc-section data-search="websites add edit bulk actions manual check enable disable delete interval" class="docs-section">
    <x-docs.partials.anchor id="websites" :title="__('Websites')" />

    <p class="docs-lead">
        {{ __('The monitored fleet. Add a site once and SiteSentinel probes it on its configured interval.') }}
    </p>

    <h3 class="docs-subheading">{{ __('Adding and editing') }}</h3>
    <p>{{ __('Each website carries a URL, a check interval (60 s–3600 s) and a timeout. The SSRF guard rejects internal or reserved addresses.') }}</p>

    <h3 class="docs-subheading">{{ __('Per-site actions') }}</h3>
    <ul class="docs-list">
        <li>{{ __('Enable / disable — pause checks without deleting history.') }}</li>
        <li>{{ __('Run now — trigger a manual check.') }}</li>
        <li>{{ __('Bulk actions — enable, disable or delete several sites at once.') }}</li>
        <li>{{ __('Per-page row selector — choose how many sites render per page.') }}</li>
    </ul>

    <x-ui.alert variant="info">
        {{ __('Deleting a website removes its snapshots, checks and incidents; prefer disabling while you investigate.') }}
    </x-ui.alert>
</section>

<section id="incidents" data-doc-section data-search="incidents open acknowledged resolved lifecycle transitions" class="docs-section">
    <x-docs.partials.anchor id="incidents" :title="__('Incidents')" />

    <p>
        {{ __('An incident tracks a problem from detection to recovery. Transitions are gated by consecutive-failure and consecutive-success thresholds so a single blip does not raise an alert.') }}
    </p>
    <ul class="docs-list">
        <li><strong>{{ __('Open') }}</strong> — {{ __('the threshold was crossed and a problem is active.') }}</li>
        <li><strong>{{ __('Acknowledged') }}</strong> — {{ __('an operator has seen it; the incident stays visible.') }}</li>
        <li><strong>{{ __('Resolved') }}</strong> — {{ __('the recovery threshold was met and the site is healthy again.') }}</li>
    </ul>
</section>
