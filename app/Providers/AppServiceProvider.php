<?php

namespace App\Providers;

use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use App\Policies\RolePolicy;
use App\Observers\RoleObserver;
use App\Observers\PermissionObserver;
use App\Observers\ProjectObserver;
use App\Observers\PurchaseOrderObserver;
use App\Observers\WorkOrderObserver;
use Spatie\Permission\Models\Permission;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
                ]);
        });

        Gate::policy(Role::class, RolePolicy::class);

        Role::observe(RoleObserver::class);
        Permission::observe(PermissionObserver::class);
        Project::observe(ProjectObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        WorkOrder::observe(WorkOrderObserver::class);
    }
}
