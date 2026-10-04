@props([
    'columns' => 1,
    'title' => null,
    'description' => null,
])

<tr>
    <td colspan="{{ $columns }}" class="px-4 py-10 text-center">
        <x-ui.empty-state :title="$title" :description="$description">
            @isset($icon)
                <x-slot name="icon">{{ $icon }}</x-slot>
            @endisset

            @isset($action)
                <x-slot name="action">{{ $action }}</x-slot>
            @endisset
        </x-ui.empty-state>
    </td>
</tr>
