<x-docs-layout :title="__('Documentation')">
    {{--
        Laravel-framework-docs-style operator guide.
        Content is authored as Blade section partials (see resources/views/docs/
        sections/*), grouped by config/documentation.php. The right-hand "On this
        page" TOC and the `#` heading anchors are generated from the same config,
        so navigation and content can never drift apart (a test enforces this).
    --}}
    <article class="docs-article">
        <header class="mb-8 border-b border-border pb-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-text-subtle">{{ __('SiteSentinel documentation') }}</p>
            <h1 class="mt-1 text-3xl font-bold tracking-tight text-text">{{ __('Documentation') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-text-muted">
                {{ __('How SiteSentinel is configured and operated. This guide describes the features as they exist in this deployment, including a step-by-step Telegram bot setup.') }}
            </p>

            {{-- Live search result count. The filtering itself is driven by the
                 header search box (`q` on the shared docsShell component). --}}
            <p class="mt-3 text-sm text-text-muted" role="status" aria-live="polite">
                <span data-docs-result-count x-show="q.trim() !== ''" x-cloak>
                    <span x-text="visibleCount"></span> {{ __('matching sections') }}
                </span>
                <span data-docs-empty x-show="q.trim() !== '' && visibleCount === 0" x-cloak class="text-text-subtle">
                    {{ __('No sections match your search.') }}
                </span>
            </p>
        </header>

        @include('docs.sections.prologue')
        @include('docs.sections.getting-started')
        @include('docs.sections.basics')
        @include('docs.sections.digging-deeper')
        @include('docs.sections.operations')
    </article>
</x-docs-layout>
