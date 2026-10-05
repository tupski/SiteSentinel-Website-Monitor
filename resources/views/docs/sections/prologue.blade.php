{{-- Prologue — Release notes + About. --}}
<section id="release-notes" data-doc-section data-search="release notes version changelog prologue" class="docs-section">
    <x-docs.partials.anchor id="release-notes" :title="__('Release notes')" />

    <p class="docs-lead">
        {{ __('SiteSentinel ships as a self-hosted application. This guide documents the features present in this deployment; the version label in the header tracks the release line.') }}
    </p>

    <x-ui.alert variant="info">
        {{ __('This guide describes only implemented behaviour. Nothing here is a roadmap item.') }}
    </x-ui.alert>
</section>

<section id="about" data-doc-section data-search="about what is sentsentinel overview prologue" class="docs-section">
    <x-docs.partials.anchor id="about" :title="__('About SiteSentinel')" />

    <p>
        {{ __('SiteSentinel is a self-hosted, server-rendered website monitor. It watches a fleet of sites you own or operate along two independent dimensions:') }}
    </p>

    <ul class="docs-list">
        <li><strong>{{ __('Availability') }}</strong> — {{ __('reachability, HTTP status, response time and TLS certificate validity.') }}</li>
        <li><strong>{{ __('Security / content health') }}</strong> — {{ __('content defacement, injected spam or keywords, unauthorized redirects and SEO-structural regressions.') }}</li>
    </ul>

    <p>
        {{ __('A scheduled dispatcher fans due work onto a Redis-backed queue; a provider-independent detection engine turns probe results into checks, detection signals and incidents, which are delivered through Email, Telegram and browser push.') }}
    </p>
</section>
