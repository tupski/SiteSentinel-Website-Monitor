@props([
    // Anchor slug — must match a `groups[].items[].id` in config/documentation.php.
    'id',
    // Visible heading text.
    'title',
])

{{--
    A Laravel-docs-style anchored heading. The `#` glyph is a real link to the
    fragment so it can be copied/shared; it is revealed on hover/focus for
    pointer users but always present for keyboard and screen-reader users
    (the docs contract requires every heading to expose an anchor).
--}}
<h2 id="{{ $id }}" class="docs-anchor group scroll-mt-24">
    <a href="#{{ $id }}"
       class="docs-anchor-link"
       aria-label="{{ __('Link to :title', ['title' => $title]) }}">
        <span aria-hidden="true">#</span>
    </a>
    {{ $title }}
</h2>
