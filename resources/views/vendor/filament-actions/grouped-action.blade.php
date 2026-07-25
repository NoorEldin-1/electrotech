{{--
    Override of filament-actions::grouped-action.

    Filament renders dropdown (⋮) items without a `wire:key`, so Livewire morphs
    them BY POSITION. When an action disappears after a request — the technical
    approval vanishing once it is signed, "record invoice" once fully invoiced —
    every later item shifts up one slot and inherits the previous item's DOM
    state. That is why the loading spinner appeared on the item BELOW the one
    that was clicked, and why the clicked item showed none.

    Keying each item by its own click handler (which already encodes the action
    name and the record key) gives Livewire a stable identity, so state stays on
    the action it belongs to. Applies to every table in the app.
--}}
@php
    $groupedActionKey = $action->getLivewireClickHandler() ?? $action->getName();
@endphp

<x-filament-actions::action
    :action="$action"
    :badge="$getBadge()"
    :badge-color="$getBadgeColor()"
    dynamic-component="filament::dropdown.list.item"
    :icon="$getGroupedIcon()"
    class="fi-ac-grouped-action"
    :wire:key="'fi-ac-grouped-' . md5($groupedActionKey)"
>
    {{ $getLabel() }}
</x-filament-actions::action>
