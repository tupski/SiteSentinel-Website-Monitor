@props([
    'name',
    'title' => null,
    'action',
    'method' => 'POST',
    'maxWidth' => 'max-w-lg',
])

{{-- Confirmation variant of `x-modal` (ADR-034) that wraps its body in a real
     form posting to `$action`. Use it for destructive/state-changing actions so
     a submit can never be triggered without the modal being open. The hidden
     `_method` field carries the verb; CSRF is emitted by `@csrf`. --}}
<x-modal :name="$name" :title="$title" :max-width="$maxWidth">
    <form method="POST" action="{{ $action }}">
        @csrf
        @if (strtoupper($method) !== 'POST')
            @method(strtoupper($method))
        @endif

        {{ $slot }}

        @isset($footer)
            <div class="mt-6 flex items-center justify-end gap-3">
                {{ $footer }}
            </div>
        @endisset
    </form>
</x-modal>
