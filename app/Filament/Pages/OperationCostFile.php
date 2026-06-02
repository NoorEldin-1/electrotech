<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\OperationCostService;
use Filament\Pages\Page;

/**
 * الإدارة العامة — Operation Cost File / Cost Center (سلايد 1: "العملية = مركز
 * تكلفة"). Pick an operation and see every cost component aggregated across
 * departments (materials, ledger expenses, purchases) plus revenue and
 * profitability. Read-only — all numbers come from OperationCostService.
 */
class OperationCostFile extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static string $view = 'filament.pages.operation-cost-file';

    protected static ?int $navigationSort = 2;

    /** Bound to the operation selector in the view. */
    public ?int $projectId = null;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.general_management');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.operations_cost.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.operations_cost.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('operations.view_cost');
    }

    /**
     * Operations eligible for a cost file: active, on-hold or completed.
     *
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

    /**
     * Cost breakdown for the selected operation, or null if none chosen.
     *
     * @return array<string, mixed>|null
     */
    public function getBreakdown(): ?array
    {
        $project = $this->getProject();

        return $project ? app(OperationCostService::class)->breakdown($project) : null;
    }
}
