<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use App\Observers\PermissionObserver;
use App\Observers\ProjectObserver;
use App\Observers\ProjectOfferObserver;
use App\Observers\PurchaseOrderObserver;
use App\Observers\RoleObserver;
use App\Observers\WorkOrderObserver;
use App\Policies\ActivityPolicy;
use App\Policies\RolePolicy;
use App\Services\QueuedActivityLogger;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Push activity log writes onto a queue so model events do not pay
        // an extra DB insert during the user's request cycle.
        $this->app->bind(ActivityLogger::class, QueuedActivityLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['en', 'ar'])
                ->labels([
                    'en' => 'English',
                    'ar' => 'العربية',
                ])
                // The stand-alone topbar language pill is retired: the switcher
                // now lives inside the user-menu dropdown next to the theme
                // switcher (see AdminPanelProvider's USER_MENU_PROFILE_AFTER
                // render hook + resources/views/filament/user-menu). We keep the
                // package configured only for its LocaleChanged event, cookie,
                // and the shared LanguageSwitch::trigger() used by our route.
                ->visible(insidePanels: false, outsidePanels: false);
        });

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(ActivityContract::class, ActivityPolicy::class);

        Role::observe(RoleObserver::class);
        Permission::observe(PermissionObserver::class);
        Project::observe(ProjectObserver::class);
        ProjectOffer::observe(ProjectOfferObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        WorkOrder::observe(WorkOrderObserver::class);

        // NOTE: the OperationActivated → NotifyDepartmentsOfActivation binding
        // is registered automatically via Laravel's listener auto-discovery
        // (app/Listeners). No manual Event::listen needed here.
    }
}
