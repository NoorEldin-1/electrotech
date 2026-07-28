{{--
    Custom user-menu (avatar) dropdown — ElectroTech Orwa.

    Overrides Filament's default user menu to deliver a modern, organised
    2026-style dropdown:

      • A rich identity header (avatar + name + email) instead of a bare link.
      • A single "Preferences" zone that groups the LANGUAGE switcher (moved
        here from the retired stand-alone topbar pill) directly above the
        built-in THEME switcher, each under its own labelled row.
      • Menu items + a clearly separated, danger-tinted logout action.

    All visual polish lives in resources/css/filament/admin/theme.css, scoped
    to `.fi-user-menu` so no other dropdown is affected. The language segment
    itself is the reusable partial at filament.user-menu.preferences.
--}}
@php
    $user = filament()->auth()->user();
    $items = filament()->getUserMenuItems();

    $profileItem = $items['profile'] ?? $items['account'] ?? null;
    $profileItemUrl = $profileItem?->getUrl();
    $profilePage = filament()->getProfilePage();
    $hasProfileItem = filament()->hasProfile() || filled($profileItemUrl);

    $logoutItem = $items['logout'] ?? null;

    $items = \Illuminate\Support\Arr::except($items, ['account', 'logout', 'profile']);

    $userName = filament()->getUserName($user);
    $userEmail = method_exists($user, 'getAttribute') ? $user->getAttribute('email') : null;

    $showThemeSwitcher = filament()->hasDarkMode() && (! filament()->hasDarkModeForced());
@endphp

{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_BEFORE) }}

<x-filament::dropdown
    placement="bottom-end"
    teleport
    :attributes="
        \Filament\Support\prepare_inherited_attributes($attributes)
            ->class(['fi-user-menu'])
    "
>
    <x-slot name="trigger">
        <button
            aria-label="{{ __('filament-panels::layout.actions.open_user_menu.label') }}"
            type="button"
            class="shrink-0 et-user-trigger"
        >
            <x-filament-panels::avatar.user :user="$user" class="et-user-trigger-avatar" />

            <x-filament::icon
                icon="heroicon-m-chevron-down"
                class="et-user-trigger-chevron"
            />
        </button>
    </x-slot>

    {{-- Identity header: avatar + name + email --}}
    <div class="et-user-header">
        <x-filament-panels::avatar.user :user="$user" class="et-user-header-avatar" />

        <div class="et-user-header-meta">
            <span class="et-user-header-name">{{ $userName }}</span>

            @if (filled($userEmail))
                <span class="et-user-header-email">{{ $userEmail }}</span>
            @endif
        </div>
    </div>

    {{-- Profile link (if a profile page / item exists) --}}
    @if (($profileItem?->isVisible() ?? true) && $hasProfileItem)
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                :color="$profileItem?->getColor()"
                :icon="$profileItem?->getIcon() ?? \Filament\Support\Facades\FilamentIcon::resolve('panels::user-menu.profile-item') ?? 'heroicon-m-user-circle'"
                :href="$profileItemUrl ?? filament()->getProfileUrl()"
                :target="($profileItem?->shouldOpenUrlInNewTab() ?? false) ? '_blank' : null"
                tag="a"
            >
                {{ $profileItem?->getLabel() ?? ($profilePage ? $profilePage::getLabel() : null) ?? $userName }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}
    @endif

    {{-- Preferences zone: language (above) + appearance (below) --}}
    @include('filament.user-menu.preferences')

    @if ($showThemeSwitcher)
        <div class="et-prefs et-prefs-appearance">
            <span class="et-prefs-label">{{ __('navigation.user_menu.appearance') }}</span>

            <x-filament-panels::theme-switcher />
        </div>
    @endif

    {{-- Platform guide: a full-width, gradient "feature" tile rather than a
         plain list row. It is the one link in this menu that leaves the panel
         (a public page, no auth), so it deliberately reads as a destination
         rather than a setting. Styled in theme.css under "USER MENU — GUIDE". --}}
    <a
        href="{{ url('/documentation') }}"
        target="_blank"
        rel="noopener"
        class="et-guide-link"
    >
        <span class="et-guide-icon">
            <x-filament::icon icon="heroicon-o-book-open" />
        </span>

        <span class="et-guide-text">
            <span class="et-guide-title">
                {{ __('navigation.user_menu.documentation') }}
                <span class="et-guide-badge">{{ __('navigation.user_menu.documentation_badge') }}</span>
            </span>
            <span class="et-guide-hint">{{ __('navigation.user_menu.documentation_hint') }}</span>
        </span>

        <x-filament::icon
            icon="heroicon-m-arrow-top-right-on-square"
            class="et-guide-arrow"
        />
    </a>

    {{-- Menu items + logout --}}
    <x-filament::dropdown.list>
        @foreach ($items as $key => $item)
            @php
                $itemPostAction = $item->getPostAction();
            @endphp

            <x-filament::dropdown.list.item
                :action="$itemPostAction"
                :color="$item->getColor()"
                :href="$item->getUrl()"
                :icon="$item->getIcon()"
                :method="filled($itemPostAction) ? 'post' : null"
                :tag="filled($itemPostAction) ? 'form' : 'a'"
                :target="$item->shouldOpenUrlInNewTab() ? '_blank' : null"
            >
                {{ $item->getLabel() }}
            </x-filament::dropdown.list.item>
        @endforeach

        {{-- Logout no longer posts directly: it opens a confirmation modal
             (rendered below, outside the dropdown so it survives the dropdown
             closing on click). The actual POST lives in the modal footer. --}}
        <x-filament::dropdown.list.item
            :color="$logoutItem?->getColor() ?? 'danger'"
            :icon="$logoutItem?->getIcon() ?? \Filament\Support\Facades\FilamentIcon::resolve('panels::user-menu.logout-button') ?? 'heroicon-m-arrow-left-on-rectangle'"
            tag="button"
            x-on:click="$dispatch('open-modal', { id: 'confirm-logout' })"
        >
            {{ $logoutItem?->getLabel() ?? __('filament-panels::layout.actions.logout.label') }}
        </x-filament::dropdown.list.item>
    </x-filament::dropdown.list>
</x-filament::dropdown>

{{-- The logout confirmation modal is intentionally NOT rendered here. A
     Filament modal is `position: fixed`, but `.fi-topbar` (this menu's
     ancestor) sets `backdrop-filter`, which turns it into the containing
     block for fixed descendants — the modal would be trapped inside the
     topbar strip. It is rendered at <body> level instead, via the
     BODY_END render hook in AdminPanelProvider, and opened by the logout
     button's global `open-modal` event. --}}

{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_AFTER) }}
