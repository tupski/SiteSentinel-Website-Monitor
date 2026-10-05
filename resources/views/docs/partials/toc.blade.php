@php
    // "On this page" table of contents. One entry per documented section,
    // derived from the same config as the sidebar. The current section is
    // highlighted by the `docsShell` Alpine component (scroll-spy).
    $sections = [];
    foreach ((array) config('documentation.groups', []) as $group) {
        foreach (($group['items'] ?? []) as $item) {
            if (! empty($item['id'])) {
                $sections[] = $item;
            }
        }
    }
@endphp

<nav data-docs-toc-nav aria-label="{{ __('On this page') }}" class="mt-6 xl:mt-0">
    <p class="text-xs font-semibold uppercase tracking-wide text-text-subtle">{{ __('On this page') }}</p>

    <ol class="mt-3 space-y-1 border-l border-border text-sm">
        @foreach ($sections as $section)
            @php($id = (string) $section['id'])
            @php($label = (string) ($section['title'] ?? $id))
            <li>
                <a href="#{{ $id }}"
                   data-docs-toc-link
                   data-target="{{ $id }}"
                   data-search="{{ mb_strtolower($label.' '.$id) }}"
                   class="docs-toc-link"
                   x-on:click="closeDrawer(false)">
                    {{ $label }}
                </a>
            </li>
        @endforeach
    </ol>
</nav>
