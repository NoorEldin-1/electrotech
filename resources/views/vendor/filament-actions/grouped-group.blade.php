{{--
    Override of filament-actions::grouped-group — same fix as grouped-action,
    for nested flyouts inside the ⋮ dropdown (e.g. the print sub-menus): give
    each nested group a stable Livewire key so sibling items disappearing do not
    shift DOM state onto the wrong entry.
--}}
<x-filament-actions::group
    :badge="$getBadge()"
    :badge-color="$getBadgeColor()"
    dynamic-component="filament::dropdown.list.item"
    :group="$group"
    :icon="$getGroupedIcon()"
    class="fi-ac-grouped-group"
    :wire:key="'fi-ac-grouped-group-' . md5($group->getLabel() ?? spl_object_hash($group))"
>
    {{ $getLabel() }}
</x-filament-actions::group>
