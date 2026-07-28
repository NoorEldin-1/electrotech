<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Support\Facades\Blade;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('ElectroTech Orwa')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/electrotech-logo.jpg'))
            ->colors([
                'primary' => Color::hex('#D9723B'),
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->font('Cairo')
            // SPA mode turns every navigation click into a wire:navigate
            // (Livewire) call instead of a full HTTP round-trip. Result:
            //   • No re-bootstrapping the framework on every nav
            //   • No re-fetching CSS/JS/Alpine state
            //   • No re-running every Filament resource's static registration
            // For panels with many resources (you have 9) this is typically
            // a 200–500ms saving per click on cold caches.
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            // Slimmer sidebar (default is 20rem). Narrowing it — together with
            // the smaller nav links styled in theme.css — hands the reclaimed
            // width back to the main module area. The floating/rounded sidebar
            // frame itself is pure CSS (see theme.css "FLOATING SIDEBAR").
            ->sidebarWidth('17rem')
            // Database notifications power the topbar bell — used by the
            // General Management layer to notify departments when an operation
            // is activated or a delivery minute is distributed.
            ->databaseNotifications()
            // Logical business-flow order: Sales → Project Management Office →
            // Procurement → Warehouse → Manufacturing → Finance → System.
            // (In English this array drives the order; in Arabic the
            // matching resolves via each resource's navigationSort block —
            // both are kept consistent so the order is identical in any locale.)
            ->navigationGroups([
                __('navigation.groups.general_management'),
                __('navigation.groups.sales_crm'),
                __('navigation.groups.technical_office'),
                __('navigation.groups.procurement'),
                __('navigation.groups.warehouse'),
                __('navigation.groups.manufacturing'),
                __('navigation.groups.finance'),
                __('navigation.groups.system'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => Blade::render('@vite(\'resources/css/filament/admin/theme.css\')')
            )
            // Logout confirmation modal. Rendered at <body> level (not inside
            // the user-menu) so its fixed positioning escapes the topbar's
            // backdrop-filter containing block. Opened by the user-menu logout
            // button via the global `open-modal` event. Only emitted for an
            // authenticated user, so it never appears on the login screen.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => filament()->auth()->check()
                    ? view('filament.user-menu.logout-modal')->render()
                    : ''
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => request()->routeIs('filament.admin.auth.login')
                    ? Blade::render('<link rel="stylesheet" href="{{ asset(\'css/custom-login.css\') }}">')
                    : ''
            )
            // Premium split-screen login: the cinematic brand showcase panel
            // is injected as the first element in <body> (a sibling of the
            // Filament simple layout) so custom-login.css can pin it to one
            // half of the viewport. Login route only.
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => request()->routeIs('filament.admin.auth.login')
                    ? view('filament.auth.login-showcase')->render()
                    : ''
            )
            // Form-side header (welcome heading + compact logo on mobile),
            // rendered above the login form in place of the default brand block.
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): string => view('filament.auth.login-header')->render()
            )
            // Network-resilience client. Loads on every admin page when
            // enabled in config — see config/resilience.php. When
            // disabled (default for `APP_ENV=local`) we emit a tiny
            // inline kill-switch that unregisters any previously
            // registered Service Worker and clears its caches, so
            // turning the feature off recovers a clean Filament UI on
            // the user's next reload without DevTools surgery.
            //
            // Defer attribute on the main script: it must not block the
            // initial paint on a slow link — it is a progressive
            // enhancement layer; the app must still work without it.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => config('resilience.enabled')
                    ? Blade::render(
                        '<script>window.__electrotechResilienceConfig = @json([
                            "serviceWorker" => (bool) config("resilience.service_worker", true),
                        ]);</script>
                        <script defer src="{{ asset(\'js/network-resilience.js\') }}"></script>'
                    )
                    : Blade::render(
                        '<script>
                        // Resilience layer disabled — clean up any stale
                        // Service Worker from a previous activation.
                        (function () {
                            if ("serviceWorker" in navigator) {
                                navigator.serviceWorker.getRegistrations().then(function (regs) {
                                    regs.forEach(function (r) { r.unregister(); });
                                }).catch(function () {});
                            }
                            if (window.caches && caches.keys) {
                                caches.keys().then(function (keys) {
                                    keys.filter(function (k) { return k.indexOf("electrotech-") === 0; })
                                        .forEach(function (k) { caches.delete(k); });
                                }).catch(function () {});
                            }
                        })();
                        </script>'
                    )
            )
            // SPA fix: on `wire:navigate` the sidebar re-renders and resets its
            // scroll to the top, hiding a deep active link (the user has to hard
            // reload to see it). Filament's own "scroll the active item into
            // view" script only runs on a full `DOMContentLoaded`, so re-run the
            // same logic on every SPA navigation. `data-navigate-once` registers
            // the persistent document listener a single time (Livewire keeps it
            // across navigations) so no duplicate handlers stack up.
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => '<script data-navigate-once>
                    document.addEventListener("livewire:navigated", () => {
                        setTimeout(() => {
                            var nav = document.querySelector(".fi-main-sidebar .fi-sidebar-nav");
                            if (! nav) return;
                            var active = document.querySelector(".fi-main-sidebar .fi-sidebar-item.fi-active");
                            if (! active || active.offsetParent === null) {
                                active = document.querySelector(".fi-main-sidebar .fi-sidebar-group.fi-active");
                            }
                            if (! active || active.offsetParent === null) return;
                            nav.scrollTo(0, active.offsetTop - window.innerHeight / 2);
                        }, 10);
                    });
                </script>'
            );
    }
}
