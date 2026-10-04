<x-admin-layout>
    <x-slot name="title">{{ __('Documentation') }} — SiteSentinel</x-slot>

    <div
        class="mx-auto max-w-6xl"
        x-data="docsFilter()"
    >
        <header class="mb-6">
            <nav class="mb-2 text-sm" aria-label="{{ __('Breadcrumb') }}">
                <a href="{{ route('admin.dashboard') }}" class="text-text-muted underline hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface">{{ __('Dashboard') }}</a>
                <span class="mx-1 text-text-subtle" aria-hidden="true">/</span>
                <span class="text-text">{{ __('Documentation') }}</span>
            </nav>

            <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Documentation') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-text-muted">
                {{ __('How SiteSentinel is configured and operated. This guide describes the features as they exist in this deployment, including a step-by-step Telegram bot setup.') }}
            </p>

            <div class="mt-5">
                <label for="docs-search" class="block text-sm font-medium text-text">{{ __('Search documentation') }}</label>
                <div class="mt-1 max-w-md">
                    <x-ui.input
                        id="docs-search"
                        type="search"
                        x-model.debounce.150ms="q"
                        placeholder="{{ __('Filter sections and entries…') }}"
                        autocomplete="off"
                        aria-describedby="docs-search-hint"
                    />
                </div>
                <p id="docs-search-hint" class="mt-1 text-xs text-text-subtle">{{ __('Filters the sections below as you type. Clear the box to show everything again.') }}</p>
                <p class="mt-2 text-sm text-text-muted" role="status" aria-live="polite">
                    <span x-show="q.trim() !== ''" x-cloak>
                        <span x-text="visibleCount"></span> {{ __('matching sections') }}
                    </span>
                </p>
            </div>
        </header>

        <div class="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-8">
            {{-- Table of contents. In-flow list on mobile, sticky sidebar on desktop.
                 Entries are filtered live by the same query and restored when cleared. --}}
            <nav
                class="mb-6 lg:mb-0"
                aria-labelledby="docs-toc-heading"
            >
                <div class="rounded-lg border border-border bg-surface-elevated p-4 lg:sticky lg:top-6 lg:max-h-[calc(100vh-4rem)] lg:overflow-y-auto">
                    <h2 id="docs-toc-heading" class="text-sm font-semibold text-text">{{ __('On this page') }}</h2>
                    <ol class="mt-3 space-y-1 text-sm">
                        @foreach ([
                            'getting-started' => __('Getting Started'),
                            'system-settings' => __('System Settings'),
                            'admin-profile' => __('Admin Profile'),
                            'navigation' => __('Navigation'),
                            'website-monitoring' => __('Website Monitoring'),
                            'detection-rules' => __('Detection / Security Rules'),
                            'notifications' => __('Notifications'),
                            'telegram-setup' => __('Telegram Bot setup'),
                            'troubleshooting' => __('Troubleshooting'),
                        ] as $anchor => $label)
                            <li data-toc-item data-search="{{ mb_strtolower($label) }}">
                                <a
                                    href="#{{ $anchor }}"
                                    class="block rounded px-2 py-1.5 text-text-muted hover:bg-surface-hover hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated"
                                >{{ $label }}</a>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </nav>

            <div class="space-y-6">
                {{-- ============================ 1. Getting Started ============================ --}}
                <section id="getting-started" data-doc-section data-search="{{ mb_strtolower(__('getting started what is sitesentinel monitoring security admin provisioning install-admin sign in login two dimensional status model availability security http 200 theme light dark system')) }}" class="scroll-mt-6" aria-labelledby="getting-started-heading">
                    <x-ui.card :title="__('Getting Started')">
                        <div class="space-y-5">
                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('What SiteSentinel is') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">
                                    {{ __('SiteSentinel monitors websites you add. A scheduled check probes each URL, records the result and evaluates a set of detection rules. Findings open incidents, and incidents are delivered to you through notification channels (email, Telegram, browser push).') }}
                                </p>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('The two-dimensional status model') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">
                                    {{ __('Each website carries two independent statuses. They answer different questions and one never replaces the other:') }}
                                </p>
                                <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div class="rounded border border-border bg-surface-muted p-3">
                                        <dt class="text-sm font-medium text-text">{{ __('Availability') }}</dt>
                                        <dd class="mt-1 text-sm text-text-muted">{{ __('UP or DOWN — whether the probe could reach the site and get the response it expects.') }}</dd>
                                    </div>
                                    <div class="rounded border border-border bg-surface-muted p-3">
                                        <dt class="text-sm font-medium text-text">{{ __('Security') }}</dt>
                                        <dd class="mt-1 text-sm text-text-muted">{{ __('OK, INFO, SUSPECT or INCIDENT — what the detection rules concluded from the response content and headers.') }}</dd>
                                    </div>
                                </dl>
                                <x-ui.alert variant="warning" class="mt-3">
                                    <p class="font-medium">{{ __('HTTP 200 does not mean healthy.') }}</p>
                                    <p class="mt-1">{{ __('A page can return 200 and still be compromised, defaced, or serving injected content — availability will read UP while the security dimension flags it. Always read both.') }}</p>
                                </x-ui.alert>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Provisioning the first admin') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">
                                    {{ __('There is no public registration page. The first admin account is created out of band with an Artisan command. The password must be at least 12 characters.') }}
                                </p>
                                <div class="mt-2 rounded border border-border bg-surface-muted p-3">
                                    <pre class="overflow-x-auto text-xs text-text"><code>php artisan sentinel:install-admin --email=you@example.com --name="Your Name"</code></pre>
                                </div>
                                <p class="mt-2 text-sm text-text-muted">
                                    {{ __('Run it on the server. Omit --password and you are prompted with a hidden input. The account is created with the admin role, active.') }}
                                </p>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Signing in') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">
                                    {{ __('The login screen is the site root. Sign in with the email and password created above. The admin area is protected by an idle timeout and an absolute session window; if a session is left idle it expires and you are returned to the login screen.') }}
                                </p>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Theme switcher') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">
                                    {{ __('The theme control in the header switches between Light, Dark and System. System follows your operating system preference. The choice applies across the admin shell.') }}
                                </p>
                            </div>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ============================ 2. System Settings ============================ --}}
                <section id="system-settings" data-doc-section data-search="{{ mb_strtolower(__('system settings site name description logo favicon timezone branding identity secrets')) }}" class="scroll-mt-6" aria-labelledby="system-settings-heading">
                    <x-ui.card :title="__('System Settings')">
                        <div class="space-y-5">
                            <p class="text-sm text-text-muted">
                                {{ __('Presentational and identity settings live on the Settings page, which you reach from the header menu or directly.') }}
                                <a href="{{ route('admin.settings.edit') }}" class="text-text underline hover:text-text-muted">{{ __('Open System settings') }}</a>.
                            </p>

                            <dl class="space-y-3">
                                <div class="border-b border-border pb-3">
                                    <dt class="text-sm font-medium text-text">{{ __('Site name') }}</dt>
                                    <dd class="mt-1 text-sm text-text-muted">{{ __('The application name shown in the header and page titles. Maximum 255 characters.') }}</dd>
                                </div>
                                <div class="border-b border-border pb-3">
                                    <dt class="text-sm font-medium text-text">{{ __('Site description') }}</dt>
                                    <dd class="mt-1 text-sm text-text-muted">{{ __('An optional short tagline. Maximum 500 characters. Clearing it restores the default.') }}</dd>
                                </div>
                                <div class="border-b border-border pb-3">
                                    <dt class="text-sm font-medium text-text">{{ __('Site logo') }}</dt>
                                    <dd class="mt-1 text-sm text-text-muted">{{ __('An uploaded image file used as the logo. It is stored on the public disk; you can replace or remove it.') }}</dd>
                                </div>
                                <div class="border-b border-border pb-3">
                                    <dt class="text-sm font-medium text-text">{{ __('Favicon') }}</dt>
                                    <dd class="mt-1 text-sm text-text-muted">{{ __('An uploaded browser tab icon. Stored on the public disk; replaceable and removable.') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-text">{{ __('Timezone') }}</dt>
                                    <dd class="mt-1 text-sm text-text-muted">{{ __('The display timezone for the admin area. Chosen from the full list of valid timezones grouped by region.') }}</dd>
                                </div>
                            </dl>

                            <x-ui.alert variant="info">
                                <p class="font-medium">{{ __('Secrets are not here.') }}</p>
                                <p class="mt-1">{{ __('Infrastructure secrets — application key, database credentials, SMTP passwords, API keys and queue credentials — are never part of this page and cannot be edited from it. Notification secrets such as a Telegram bot token belong to a notification channel, not to System settings.') }}</p>
                            </x-ui.alert>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ============================ 3. Admin Profile ============================ --}}
                <section id="admin-profile" data-doc-section data-search="{{ mb_strtolower(__('admin profile name email change password current confirmation minimum length session')) }}" class="scroll-mt-6" aria-labelledby="admin-profile-heading">
                    <x-ui.card :title="__('Admin Profile')">
                        <div class="space-y-5">
                            <p class="text-sm text-text-muted">
                                {{ __('Your own account details are edited on the Profile page, reached from the header.') }}
                                <a href="{{ route('admin.profile.edit') }}" class="text-text underline hover:text-text-muted">{{ __('Open your profile') }}</a>.
                            </p>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Identity') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('Edit your name and email address. The email is the address you sign in with, so it must stay unique and valid. Your role and active status cannot be changed from this page.') }}</p>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Changing your password') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('The password form asks for three values:') }}</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-text-muted">
                                    <li>{{ __('Current password — verified against your live credentials; an incorrect value is rejected as a field error.') }}</li>
                                    <li>{{ __('New password — must be at least 12 characters and must differ from your current password.') }}</li>
                                    <li>{{ __('Confirm new password — must match the new password exactly.') }}</li>
                                </ul>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Session behaviour') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('Changing your password keeps your current session signed in; you are not forced to log in again. Other sessions are left untouched. Sessions are also bound by the idle and absolute timeouts described above.') }}</p>
                            </div>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ============================ 4. Navigation ============================ --}}
                <section id="navigation" data-doc-section data-search="{{ mb_strtolower(__('navigation menu areas dashboard websites incidents notification delivery log status pages profile settings documentation')) }}" class="scroll-mt-6" aria-labelledby="navigation-heading">
                    <x-ui.card :title="__('Navigation')" :subtitle="__('Every area in the admin header and what it is for.')">
                        <div class="overflow-hidden rounded border border-border">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-surface-muted text-text-muted">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 font-medium">{{ __('Area') }}</th>
                                        <th scope="col" class="px-3 py-2 font-medium">{{ __('Purpose') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-text"><a href="{{ route('admin.dashboard') }}" class="underline hover:text-text-muted">{{ __('Dashboard') }}</a></td>
                                        <td class="px-3 py-2 text-text-muted">{{ __('Counters, monitoring pipeline health, and the current availability and security status of every website.') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-text"><a href="{{ route('admin.websites.index') }}" class="underline hover:text-text-muted">{{ __('Websites') }}</a></td>
                                        <td class="px-3 py-2 text-text-muted">{{ __('Add, edit, enable/disable and remove monitored websites; trigger a manual check and manage bulk actions.') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-text"><a href="{{ route('admin.incidents.index') }}" class="underline hover:text-text-muted">{{ __('Incidents') }}</a></td>
                                        <td class="px-3 py-2 text-text-muted">{{ __('The incident lifecycle: filter by status, severity, type or website, then acknowledge or resolve.') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-text"><a href="{{ route('admin.notifications.index') }}" class="underline hover:text-text-muted">{{ __('Notification') }}</a></td>
                                        <td class="px-3 py-2 text-text-muted">{{ __('Manage notification channels (add, edit, enable, test, delete) and browser push opt-in.') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-text"><a href="{{ route('admin.notification-logs.index') }}" class="underline hover:text-text-muted">{{ __('Delivery log') }}</a></td>
                                        <td class="px-3 py-2 text-text-muted">{{ __('Every delivery attempt with its status (queued, sent, failed, suppressed), filterable by channel, status or incident.') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-text"><a href="{{ route('admin.status-pages.index') }}" class="underline hover:text-text-muted">{{ __('Status pages') }}</a></td>
                                        <td class="px-3 py-2 text-text-muted">{{ __('Create and manage public status pages, choose their visibility mode and assign websites to them.') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-text"><a href="{{ route('admin.profile.edit') }}" class="underline hover:text-text-muted">{{ __('Profile') }}</a></td>
                                        <td class="px-3 py-2 text-text-muted">{{ __('Your own name, email and password.') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-text"><a href="{{ route('admin.settings.edit') }}" class="underline hover:text-text-muted">{{ __('Settings') }}</a></td>
                                        <td class="px-3 py-2 text-text-muted">{{ __('Site identity, branding and display timezone for the whole application.') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-text"><a href="{{ route('admin.documentation') }}" class="underline hover:text-text-muted">{{ __('Documentation') }}</a></td>
                                        <td class="px-3 py-2 text-text-muted">{{ __('This guide.') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ============================ 5. Website Monitoring ============================ --}}
                <section id="website-monitoring" data-doc-section data-search="{{ mb_strtolower(__('website monitoring add edit enable disable check interval timeout expected http status title final domain toggles notification channel assignment status last check run check queued')) }}" class="scroll-mt-6" aria-labelledby="website-monitoring-heading">
                    <x-ui.card :title="__('Website Monitoring')">
                        <div class="space-y-5">
                            <p class="text-sm text-text-muted">
                                {{ __('Add a website from the Websites area.') }}
                                <a href="{{ route('admin.websites.index') }}" class="text-text underline hover:text-text-muted">{{ __('Open Websites') }}</a>.
                            </p>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Fields on a website') }}</h3>
                                <dl class="mt-2 space-y-3">
                                    <div>
                                        <dt class="text-sm font-medium text-text">{{ __('Name') }}</dt>
                                        <dd class="text-sm text-text-muted">{{ __('A short label used only inside SiteSentinel, shown in listings and alerts.') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-text">{{ __('URL') }}</dt>
                                        <dd class="text-sm text-text-muted">{{ __('The full URL including https://. It must resolve to a public destination; private, loopback and link-local addresses are rejected.') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-text">{{ __('Check interval (seconds)') }}</dt>
                                        <dd class="text-sm text-text-muted">{{ __('How often the monitor probes the URL. Minimum 60 seconds; 300 (five minutes) is a common default.') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-text">{{ __('Timeout (seconds)') }}</dt>
                                        <dd class="text-sm text-text-muted">{{ __('How long to wait for a response before treating the check as failed. Between 3 and 30 seconds; keep it below the check interval.') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-text">{{ __('Expected HTTP status') }}</dt>
                                        <dd class="text-sm text-text-muted">{{ __('The status code a healthy response should return (for example 200, or 301/302 if the site is expected to redirect). 304 and 401 are always tolerated.') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-text">{{ __('Expected page title') }}</dt>
                                        <dd class="text-sm text-text-muted">{{ __('Optional. When set, the page title is compared against this value and drift is flagged. Leave blank to skip.') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-text">{{ __('Expected final domain') }}</dt>
                                        <dd class="text-sm text-text-muted">{{ __('Optional. The domain the site should end on after redirects. A mismatch is flagged as a redirect-hijack signal. Leave blank to skip.') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-text">{{ __('Note') }}</dt>
                                        <dd class="text-sm text-text-muted">{{ __('Free text for operators — ownership, contacts, maintenance windows. Not sent in alerts.') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-text">{{ __('Active') }}</dt>
                                        <dd class="text-sm text-text-muted">{{ __('Whether this website is monitored at all. You can also toggle a website from the list without opening the form.') }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Toggles') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('These checkboxes switch individual parts of the check on and off:') }}</p>
                                <ul class="mt-2 flex flex-wrap gap-2">
                                    @foreach (['follow_redirects', 'monitor_ssl', 'monitor_redirects', 'monitor_content', 'monitor_security'] as $toggle)
                                        <li class="rounded-full border border-border bg-surface-muted px-3 py-1 text-sm text-text">{{ __(str_replace('_', ' ', ucfirst($toggle))) }}</li>
                                    @endforeach
                                </ul>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Notification channels') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('A website can be assigned specific notification channels. Leave all channels unchecked to use every enabled channel — assignment narrows delivery, it does not disable alerting.') }}</p>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Current status and last check') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('The list shows each website’s availability and security status together with when it was last checked. A website that has never been checked shows no status.') }}</p>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Manual “Run check”') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('The play control on a website queues a check immediately. It does not run inline — the job is placed on the queue, so the result appears once a worker has processed it.') }}</p>
                            </div>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ============================ 6. Detection / Security Rules ============================ --}}
                <section id="detection-rules" data-doc-section data-search="{{ mb_strtolower(__('detection security rules severities info warning critical per website rule configuration clean limitation detection-rules.md')) }}" class="scroll-mt-6" aria-labelledby="detection-rules-heading">
                    <x-ui.card :title="__('Detection / Security Rules')">
                        <div class="space-y-5">
                            <p class="text-sm text-text-muted">
                                {{ __('After each check, a rule engine evaluates what the probe returned and produces signals. Rules are grouped into categories — availability, TLS/SSL, content, redirects, SEO-based patterns and the like. The exact rule catalogue is defined in the project’s detection rules specification; rules are identified there by stable ids such as RULE-AV-002, and this guide does not invent ids beyond that catalogue.') }}
                            </p>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Severities and scoring') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('Signals carry a severity and are correlated rather than acted on individually:') }}</p>
                                <ul class="mt-2 space-y-2">
                                    <li class="flex items-start gap-2"><span class="mt-0.5"><x-ui.badge variant="info">INFO</x-ui.badge></span><span class="text-sm text-text-muted">{{ __('Low-weight observations and drift. Recorded for context; on their own they do not escalate.') }}</span></li>
                                    <li class="flex items-start gap-2"><span class="mt-0.5"><x-ui.badge variant="warning">WARNING</x-ui.badge></span><span class="text-sm text-text-muted">{{ __('A single rule can raise a warning. This is the highest a lone signal reaches.') }}</span></li>
                                    <li class="flex items-start gap-2"><span class="mt-0.5"><x-ui.badge variant="danger">CRITICAL</x-ui.badge></span><span class="text-sm text-text-muted">{{ __('Requires multiple correlated signals. No single rule can produce a critical on its own.') }}</span></li>
                                </ul>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Interpreting statuses') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('The security status moves from OK to INFO, SUSPECT and then INCIDENT as weighted signals accumulate. Availability is tracked separately as UP or DOWN.') }}</p>
                            </div>

                            <x-ui.alert variant="warning">
                                <p class="font-medium">{{ __('The key limitation') }}</p>
                                <p class="mt-1">{{ __('A clean security status means no configured rule fired on that check — it is not a guarantee that the site is secure. Detection is limited to the rules that exist and the data a single request can observe.') }}</p>
                            </x-ui.alert>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Per-website configuration') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('Rules carry defaults. Per-website overrides exist at the data level — a rule can be enabled or disabled, its weight changed, its threshold changed, or keywords ignored. Not every override is exposed in the admin UI, so treat the defaults as what applies unless you know an override is set.') }}</p>
                            </div>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ============================ 7. Notifications ============================ --}}
                <section id="notifications" data-doc-section data-search="{{ mb_strtolower(__('notifications dispatcher channels severities cooldown suppression delivery log test sends email telegram browser push')) }}" class="scroll-mt-6" aria-labelledby="notifications-heading">
                    <x-ui.card :title="__('Notifications')">
                        <div class="space-y-5">
                            <p class="text-sm text-text-muted">
                                {{ __('Incidents are delivered by a provider-independent dispatcher. The incident produces an event, the dispatcher resolves which channels apply, applies its suppression gates and fans out one delivery per surviving channel. Manage channels in the Notification area and review attempts in the Delivery log.') }}
                            </p>
                            <p class="text-sm">
                                <a href="{{ route('admin.notifications.index') }}" class="text-text underline hover:text-text-muted">{{ __('Open Notification') }}</a>
                                <span class="mx-1 text-text-subtle" aria-hidden="true">·</span>
                                <a href="{{ route('admin.notification-logs.index') }}" class="text-text underline hover:text-text-muted">{{ __('Open Delivery log') }}</a>
                            </p>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Channel types') }}</h3>
                                <ul class="mt-2 space-y-2">
                                    <li class="text-sm text-text-muted"><span class="font-medium text-text">{{ __('Email (SMTP)') }}</span> — {{ __('delivered by the built-in SMTP provider.') }}</li>
                                    <li class="text-sm text-text-muted"><span class="font-medium text-text">{{ __('Telegram (Bot API)') }}</span> — {{ __('delivered by a bot you create; see the dedicated section below.') }}</li>
                                    <li class="text-sm text-text-muted"><span class="font-medium text-text">{{ __('Browser Push') }}</span> — {{ __('enabled per browser from the Browser push panel on the Notification page; delivers to browsers that have opted in.') }}</li>
                                </ul>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Severity and cooldown') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('Each channel has a Minimum severity. Events below that level are not delivered to the channel. A channel set to CRITICAL receives escalations but suppresses routine WARNING incidents.') }}</p>
                                <p class="mt-1 text-sm text-text-muted">{{ __('Repeated events are also suppressed by a cooldown and de-duplicated per channel, so an ongoing problem does not generate an alert on every check. A successful test does not reset or bypass that suppression for real incidents.') }}</p>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Delivery log') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('Every attempt is recorded with a status — queued, sent, failed or suppressed — along with the channel, the incident, the attempt count and any error. Filter by status, channel or incident id.') }}</p>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-text">{{ __('Test sends') }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ __('Each channel can be tested before you rely on it. On the channel form, “Send test” sends the current values without saving; on the channels list, the “Send test” action tests a saved channel. Browser push has its own “Send test push” action.') }}</p>
                            </div>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ============================ 8. Telegram Bot setup ============================ --}}
                <section id="telegram-setup" data-doc-section data-search="{{ mb_strtolower(__('telegram bot setup botfather newbot token chat id topic thread id secret_ref getUpdates start enable test verify troubleshooting api.telegram.org')) }}" class="scroll-mt-6" aria-labelledby="telegram-setup-heading">
                    <x-ui.card :title="__('Telegram Bot setup')" :subtitle="__('A complete walkthrough for a first-time setup.')">
                        <div class="space-y-6">
                            <x-ui.alert variant="info">
                                <p class="font-medium">{{ __('Three things, and only one is secret') }}</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    <li>{{ __('Telegram bot token — the bot’s credential. It is a secret. Never share it, never paste it into a ticket, and never commit it. In SiteSentinel it is stored encrypted and is not shown again after saving.') }}</li>
                                    <li>{{ __('Telegram Chat ID — the numeric identifier of the chat that receives messages. It is not a secret.') }}</li>
                                    <li>{{ __('SiteSentinel Telegram configuration — a notification channel of type Telegram (Bot API) holding the token in the secret field and the chat id in the Chat ID field.') }}</li>
                                </ul>
                            </x-ui.alert>

                            <ol class="space-y-6">
                                {{-- Step 1 --}}
                                <li class="space-y-2">
                                    <h3 class="text-sm font-semibold text-text">{{ __('1. Create a bot with BotFather') }}</h3>
                                    <p class="text-sm text-text-muted">{{ __('Open Telegram and start a chat with the official @BotFather account. Send the command /newbot. BotFather asks for a display name for the bot, then for a username. The username must be unique and must end in “bot”.') }}</p>
                                    <div class="rounded border border-border bg-surface-muted p-3">
                                        <pre class="overflow-x-auto text-xs text-text"><code>/newbot</code></pre>
                                    </div>
                                </li>

                                {{-- Step 2 --}}
                                <li class="space-y-2">
                                    <h3 class="text-sm font-semibold text-text">{{ __('2. Get the bot token from BotFather') }}</h3>
                                    <p class="text-sm text-text-muted">{{ __('When the bot is created, BotFather replies with a token — a long string containing digits, a colon and a random part. Use the /token command at any time to view it again. Treat it as a password.') }}</p>
                                    <x-ui.alert variant="warning">
                                        <p>{{ __('The token is a secret. Anyone holding it can control the bot.') }}</p>
                                    </x-ui.alert>
                                </li>

                                {{-- Step 3 --}}
                                <li class="space-y-2">
                                    <h3 class="text-sm font-semibold text-text">{{ __('3. Enter the token in SiteSentinel') }}</h3>
                                    <p class="text-sm text-text-muted">{{ __('Go to Notification, then Add channel (or edit an existing one). Set Type to “Telegram (Bot API)” and paste the token into the “Secret (SMTP password or bot token)” field.') }}</p>
                                    <p class="text-sm text-text-muted">{{ __('The token is encrypted at rest and never displayed again. When editing later, leave the secret field blank to keep the token that is already stored.') }}</p>
                                </li>

                                {{-- Step 4 --}}
                                <li class="space-y-2">
                                    <h3 class="text-sm font-semibold text-text">{{ __('4. Get the Chat ID') }}</h3>
                                    <p class="text-sm text-text-muted">{{ __('The bot can only message a chat it can see. Message the bot first (or add it to the group or channel), then read the chat id from the Bot API. Replace the token with your own — never paste a real token into a shared document:') }}</p>
                                    <div class="max-w-full rounded border border-border bg-surface-muted p-3">
                                        <pre class="overflow-x-auto text-xs text-text"><code>https://api.telegram.org/bot{{ '<TOKEN>' }}/getUpdates</code></pre>
                                    </div>
                                    <p class="text-sm text-text-muted">{{ __('Open that URL in a browser. The response contains the recent updates; the chat id is the numeric “id” inside the chat object. Direct chats have positive ids; groups and channels usually have negative ids — keep the minus sign.') }}</p>
                                </li>

                                {{-- Step 5 --}}
                                <li class="space-y-2">
                                    <h3 class="text-sm font-semibold text-text">{{ __('5. Start the conversation') }}</h3>
                                    <p class="text-sm text-text-muted">{{ __('A bot cannot message a user who has never messaged it, and cannot post to a group it is not a member of. Send /start to the bot in a direct chat, or add the bot to the group or channel. Without this step the chat id lookup fails or delivery is rejected.') }}</p>
                                </li>

                                {{-- Step 6 --}}
                                <li class="space-y-2">
                                    <h3 class="text-sm font-semibold text-text">{{ __('6. Configure the Chat ID in SiteSentinel') }}</h3>
                                    <p class="text-sm text-text-muted">{{ __('On the same Telegram channel form, put the numeric id in the “Chat ID” field. For a forum topic, also set “Topic thread ID (optional)” to the topic’s thread id; otherwise leave it blank.') }}</p>
                                </li>

                                {{-- Step 7 --}}
                                <li class="space-y-2">
                                    <h3 class="text-sm font-semibold text-text">{{ __('7. Enable the channel') }}</h3>
                                    <p class="text-sm text-text-muted">{{ __('Tick Enabled on the channel and choose the Minimum severity. Then decide where it applies: assign the channel to specific websites, or leave a website’s channels unchecked so it uses every enabled channel. A disabled channel is never delivered to, regardless of the websites assigned.') }}</p>
                                </li>

                                {{-- Step 8 --}}
                                <li class="space-y-2">
                                    <h3 class="text-sm font-semibold text-text">{{ __('8. Send a test notification') }}</h3>
                                    <p class="text-sm text-text-muted">{{ __('Use “Send test” on the channel form to test unsaved values, or the “Send test” action on the channels list to test a saved channel. A successful result is reported as a sent test. The test travels the same provider path as a real alert — same escaping and transport — so a passing test means the credentials and chat id are correct.') }}</p>
                                </li>

                                {{-- Step 9 --}}
                                <li class="space-y-2">
                                    <h3 class="text-sm font-semibold text-text">{{ __('9. Verify it worked') }}</h3>
                                    <ul class="list-disc space-y-1 pl-5 text-sm text-text-muted">
                                        <li>{{ __('The message arrives in the Telegram chat you configured.') }}</li>
                                        <li>{{ __('The Delivery log shows the attempt with the status “sent”.') }}</li>
                                        <li>{{ __('Anything else — for example “failed” — carries an error you can filter on and read.') }}</li>
                                    </ul>
                                </li>
                            </ol>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ============================ 9. Troubleshooting ============================ --}}
                <section id="troubleshooting" data-doc-section data-search="{{ mb_strtolower(__('troubleshooting common problems fixes invalid token chat id minus sign bot member blocked topic thread id disabled minimum severity cooldown network firewall api.telegram.org')) }}" class="scroll-mt-6" aria-labelledby="troubleshooting-heading">
                    <x-ui.card :title="__('Troubleshooting')">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Telegram: invalid token') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('The token was mistyped, was revoked, or belongs to a different bot. Re-copy it from BotFather with /token and paste it into the secret field again.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Telegram: wrong Chat ID') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('For groups and channels the id is negative — a missing minus sign is the most common mistake. Re-read the id from getUpdates and include the sign.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Telegram: bot never started / not a member') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('A bot cannot initiate a direct chat. Send /start to it, or add it to the group or channel, then look up the chat id again.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Telegram: bot blocked') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('If the bot was blocked, delivery fails until it is unblocked and the conversation restarted.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Telegram: wrong topic thread ID') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('If you set a topic thread id that does not belong to the target forum, delivery fails. Clear it unless you are posting to a forum topic.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Channel disabled') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('A channel that is not enabled is skipped entirely. Enable it before expecting any delivery.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Minimum severity too high') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('A channel set to CRITICAL suppresses WARNING incidents. Set the minimum severity to WARNING if you want to see them.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Test passes but real alerts do not arrive') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('A test bypasses the per-channel cooldown and de-duplication gates that apply to real incidents. If you have recently received an alert for the same problem, repeat events are suppressed until the cooldown passes. Check the Delivery log for “suppressed” entries.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Websites not being checked') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('Checks run on the queue. If nothing is being checked, the queue worker is not running, or the website is inactive. A manual “Run check” queued but never processed points at the worker.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-text">{{ __('Network / firewall to api.telegram.org') }}</dt>
                                <dd class="mt-1 text-sm text-text-muted">{{ __('The server must be able to reach api.telegram.org over HTTPS. If outbound traffic is filtered, Telegram deliveries fail with a transport error even though the token and chat id are correct.') }}</dd>
                            </div>
                        </dl>
                    </x-ui.card>
                </section>

                <p x-show="q.trim() !== '' && visibleCount === 0" x-cloak class="text-sm text-text-muted">
                    {{ __('No sections match your search.') }}
                </p>
            </div>
        </div>
    </div>

    <script>
        // Client-side filter for the documentation page. Matches the query against
        // each section's data-search text and hides non-matching sections and their
        // TOC entries. Clearing the input restores everything. No network involved.
        function docsFilter() {
            return {
                q: '',
                visibleCount: 0,
                normalize(value) {
                    return (value || '').toString().toLowerCase();
                },
                get tokens() {
                    return this.normalize(this.q).split(/\s+/).filter(Boolean);
                },
                matches(haystack) {
                    const text = this.normalize(haystack);
                    return this.tokens.every((token) => text.includes(token));
                },
                apply() {
                    let visible = 0;

                    document.querySelectorAll('[data-doc-section]').forEach((section) => {
                        const hit = this.matches(section.dataset.search || '');
                        section.style.display = hit ? '' : 'none';
                        if (hit) {
                            visible += 1;
                        }
                    });

                    document.querySelectorAll('[data-toc-item]').forEach((item) => {
                        const hit = this.matches(item.dataset.search || '');
                        item.style.display = hit ? '' : 'none';
                    });

                    this.visibleCount = visible;
                },
                init() {
                    this.$watch('q', () => this.apply());
                    this.apply();
                },
            };
        }
    </script>
</x-admin-layout>
