{{--
    Account widget (dashboard "welcome" card) — overridden so its logout
    button opens the shared confirmation modal instead of posting directly.

    The modal itself is rendered once at <body> level via the BODY_END render
    hook (see AdminPanelProvider) and is opened here by dispatching the global
    `open-modal` event with the same id used by the user-menu logout button.
--}}
@php
    $user = filament()->auth()->user();
@endphp

<x-filament-widgets::widget class="fi-account-widget">
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <x-filament-panels::avatar.user size="lg" :user="$user" />

            <div class="flex-1">
                <h2
                    class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white"
                >
                    {{ __('filament-panels::widgets/account-widget.welcome', ['app' => config('app.name')]) }}
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ filament()->getUserName($user) }}
                </p>
            </div>

            <x-filament::button
                color="gray"
                icon="heroicon-m-arrow-left-on-rectangle"
                icon-alias="panels::widgets.account.logout-button"
                labeled-from="sm"
                tag="button"
                type="button"
                class="my-auto"
                x-on:click="$dispatch('open-modal', { id: 'confirm-logout' })"
            >
                {{ __('filament-panels::widgets/account-widget.actions.logout.label') }}
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
