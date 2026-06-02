<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * الإدارة العامة — Operations Overview (سلايد 1: "الإدارة العامة").
 *
 * Read-only command-center listing every active operation (project) with its
 * cross-department readiness at a glance: BOMs, work orders, purchase orders,
 * deliveries, and budget-vs-actual. The first surface of the General
 * Management layer that ties all departments together around the operation.
 */
class OperationsOverview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string $view = 'filament.pages.operations-overview';

    protected static ?int $navigationSort = 1;

    /** Bound to the search box in the view. */
    public string $search = '';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.general_management');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.operations_overview.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.operations_overview.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('operations.overview');
    }

    /**
     * Active operations (status = InProgress) with cross-department counts,
     * filtered by the search box (name / code / client).
     */
    public function getOperations(): Collection
    {
        $term = trim($this->search);

        return Project::query()
            ->where('status', ProjectStatus::InProgress)
            ->when($term !== '', function ($query) use ($term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('client_name', 'like', $like);
                });
            })
            ->withCount([
                'boms',
                'workOrders',
                'purchaseOrders',
                'deliveryVouchers',
            ])
            ->with('customer')
            ->orderByDesc('id')
            ->get();
    }
}
