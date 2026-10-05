{{-- Digging Deeper — monitoring engine, detection model, notifications, status pages. --}}
<section id="monitoring-engine" data-doc-section data-search="monitoring engine probe queue scheduler redirects ssl response size ssrf" class="docs-section">
    <x-docs.partials.anchor id="monitoring-engine" :title="__('Monitoring engine')" />

    <p class="docs-lead">
        {{ __('Monitoring runs entirely on the queue, never inside a web request. A scheduler fans due checks onto the monitoring queue; a worker performs each probe through the SSRF guard.') }}
    </p>

    <h3 class="docs-subheading">{{ __('What a check records') }}</h3>
    <ul class="docs-list">
        <li>{{ __('HTTP status, total response time and the final URL after redirects.') }}</li>
        <li>{{ __('The redirect chain, captured hop by hop, with the hop cap enforced.') }}</li>
        <li>{{ __('TLS certificate validity and expiry.') }}</li>
        <li>{{ __('A bounded response body, used for content detection and snapshots.') }}</li>
    </ul>

    <p>
        {{ __('Failures — timeout, DNS, connection or policy — are recorded as check results, never thrown away. A blocked redirect is a policy failure, not a website outage.') }}
    </p>
</section>

<section id="detection-model" data-doc-section data-search="detection model rules scoring severity info warning critical correlation guard defacement keywords redirect seo" class="docs-section">
    <x-docs.partials.anchor id="detection-model" :title="__('Detection model')" />

    <p class="docs-lead">
        {{ __('Probe results are compared against a baseline to produce detection signals, which are scored into a severity and, when warranted, an incident.') }}
    </p>

    <h3 class="docs-subheading">{{ __('Rule categories') }}</h3>
    <ul class="docs-list">
        <li>{{ __('Availability and redirect integrity (e.g. RULE-AV-002 by shape).') }}</li>
        <li>{{ __('Content defacement and injected spam keywords.') }}</li>
        <li>{{ __('Unauthorized redirects and SEO-structural regressions.') }}</li>
        <li>{{ __('SSL certificate problems.') }}</li>
    </ul>

    <h3 class="docs-subheading">{{ __('Severity and the correlation guard') }}</h3>
    <p>
        {{ __('Signals accumulate a score mapped to info, warning or critical. A critical security escalation requires at least two independent rule categories to fire, which suppresses single-signal false positives.') }}
    </p>
</section>

<section id="notifications" data-doc-section data-search="notifications dispatcher providers email telegram push cooldown suppression circuit breaker delivery log" class="docs-section">
    <x-docs.partials.anchor id="notifications" :title="__('Notifications')" />

    <p class="docs-lead">
        {{ __('The incident engine is provider-agnostic. Providers implement one contract and register in the provider registry.') }}
    </p>

    <h3 class="docs-subheading">{{ __('Providers') }}</h3>
    <ul class="docs-list">
        <li><strong>{{ __('Email') }}</strong> — {{ __('SMTP delivery.') }}</li>
        <li><strong>{{ __('Telegram') }}</strong> — {{ __('bot delivery, including forum topics.') }}</li>
        <li><strong>{{ __('Browser push') }}</strong> — {{ __('VAPID push, opted in from the admin UI.') }}</li>
    </ul>

    <h3 class="docs-subheading">{{ __('Suppression and cooldown') }}</h3>
    <p>
        {{ __('Duplicate incident events are deduplicated and a per-website cooldown applies. A circuit breaker pauses a failing provider; while open, sends are suppressed until it resets.') }}
    </p>

    <p>
        {{ __('Every delivery attempt is logged, redacted, in the Delivery log — including failures. Notification failure never fails a monitoring job.') }}
    </p>
</section>

<section id="status-pages" data-doc-section data-search="status pages public private password visibility modes redaction slug json" class="docs-section">
    <x-docs.partials.anchor id="status-pages" :title="__('Status pages')" />

    <p class="docs-lead">
        {{ __('Publish one or many status pages at /status/{slug}, each in HTML and JSON.') }}
    </p>

    <h3 class="docs-subheading">{{ __('Visibility modes') }}</h3>
    <ul class="docs-list">
        <li><strong>{{ __('Public') }}</strong> — {{ __('open to anyone with the link.') }}</li>
        <li><strong>{{ __('Private') }}</strong> — {{ __('not listed publicly.') }}</li>
        <li><strong>{{ __('Password-protected') }}</strong> — {{ __('requires a shared password to unlock.') }}</li>
    </ul>

    <x-ui.alert variant="info">
        {{ __('The public view is strictly redacted: no security detail, keyword, domain, redirect target, rule id or snapshot is ever exposed. The boundary holds per page.') }}
    </x-ui.alert>
</section>
