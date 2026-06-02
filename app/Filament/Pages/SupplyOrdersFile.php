<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\OperationCostService;
use App\Services\OperationPaymentService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * ملف أوامر التوريد — Supply Orders File (سلايد 1: "استلام المالي ورصدها في
 * ملف الأوامر التوريد"). Per-operation consolidated view of supply (purchase
 * orders) and the money received against it, with the outstanding balance.
 * Read-only aggregation.
 */
class SupplyOrdersFile extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string $view = 'filament.pages.supply-orders-file';

    protected static ?int $navigationSort = 3;

    public ?int $projectId = null;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.general_management');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.supply_orders_file.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.supply_orders_file.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('supply_orders_file.view');
    }

    /**
     * @return array<int, string>
     */
    public function getProjectOptions(): array
    {
        return Project::query()
            ->whereIn('status', [
                ProjectStatus::InProgress,
                ProjectStatus::OnHold,
                ProjectStatus::Completed,
            ])
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Project $p): array => [
                $p->id => trim(($p->code ? $p->code . ' — ' : '') . $p->name),
            ])
            ->all();
    }

    public function getProject(): ?Project
    {
        return $this->projectId ? Project::find($this->projectId) : null;
    }

    /** Purchase / supply orders for the selected operation. */
    public function getPurchaseOrders(): Collection
    {
        $project = $this->getProject();

        return $project
            ? $project->purchaseOrders()->orderByDesc('id')->get()
            : collect();
    }

    /**
     * Cash totals + outstanding balance (revenue − received) for the operation.
     *
     * @return array{received: float, paid: float, net: float, revenue: float, outstanding: float}|null
     */
    public function getSummary(): ?array
    {
        $project = $this->getProject();

        if ($project === null) {
            return null;
        }

        $totals = app(OperationPaymentService::class)->totalsForProject($project);
        $revenue = app(OperationCostService::class)->revenue($project);

        return [
            ...$totals,
            'revenue' => $revenue,
            'outstanding' => $revenue - $totals['received'],
        ];
    }
}
