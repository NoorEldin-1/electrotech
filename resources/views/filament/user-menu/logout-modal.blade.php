{{--
    Logout confirmation modal — rendered at <body> level (BODY_END render
    hook) rather than inside the user-menu, so its `position: fixed` layout
    resolves against the viewport instead of `.fi-topbar` (whose
    `backdrop-filter` would otherwise become the containing block and trap
    the modal in the topbar strip).

    Opened by the user-menu logout button via the global `open-modal` event
    (id: confirm-logout). The confirm button submits a real POST form to the
    logout route with CSRF; cancel just closes the modal.
--}}
<x-filament::modal
    id="confirm-logout"
    icon="heroicon-o-arrow-left-on-rectangle"
    icon-color="danger"
    :heading="__('navigation.user_menu.logout_confirm_heading')"
    :description="__('navigation.user_menu.logout_confirm_description')"
    width="md"
>
    <x-slot name="footerActions">
        <form method="POST" action="{{ filament()->getLogoutUrl() }}">
            @csrf

            <x-filament::button
                type="submit"
                color="danger"
                icon="heroicon-m-arrow-left-on-rectangle"
            >
                {{ __('navigation.user_menu.logout_confirm_button') }}
            </x-filament::button>
        </form>

        <x-filament::button
            color="gray"
            x-on:click="$dispatch('close-modal', { id: 'confirm-logout' })"
        >
            {{ __('navigation.user_menu.cancel') }}
        </x-filament::button>
    </x-slot>
</x-filament::modal>
