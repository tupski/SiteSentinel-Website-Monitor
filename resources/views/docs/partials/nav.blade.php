@php
    // Grouped documentation navigation (Laravel-docs style). Derived from
    // config/documentation.php so the sidebar and the rendered content anchors
    // cannot drift. Each link targets a `#section` anchor on the docs page.
    $groups = (array) config('documentation.groups', []);
@endphp

<nav data-docs-nav aria-label="{{ __('Documentation') }}" class="space-y-6 text-sm">
    @foreach ($groups as $group)
        <div class="docs-nav-group">
            @if (! empty($group['label']))
                <p class="docs-nav-group-label">{{ $group['label'] }}</p>
            @endif

            <ul class="mt-2 space-y-0.5 border-l border-border">
                @foreach (($group['items'] ?? []) as $item)
                    @php($id = (string) ($item['id'] ?? ''))
                    @php($label = (string) ($item['title'] ?? $id))
                    <li>
                        <a href="#{{ $id }}"
                           data-docs-nav-link
                           data-target="{{ $id }}"
                           data-search="{{ mb_strtolower($label.' '.$id) }}"
                           class="docs-nav-link"
                           x-on:click="closeDrawer(false)">
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
