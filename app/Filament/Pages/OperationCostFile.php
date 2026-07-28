<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ProjectStatus;
use App\Models\CostCenterClosing;
use App\Models\Project;
use App\Services\CostCenterClosingService;
use App\Services\OperationCostService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * الإدارة العامة — Operation Cost File / Cost Center (سلايد 1: "العملية = مركز
 * تكلفة"). Pick an operation and see every cost component aggregated across
 * departments (materials, ledger expenses, purchases) plus revenue and
 * profitability. Read-only — all numbers come from OperationCostService.
 *
 * The one place that writes is the cost-centre closing (Financial Department
 * سلايد 12): carrying the operation's remaining inventory cost to cost of goods
 * sold once the customer has been delivered, and reversing a closing made in
 * error.
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
                $p->id => trim(($p->code ? $p->code.' — ' : '').$p->name),
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

    /**
     * Closing history of the selected operation, newest first — closings and
     * the reversals that undid them.
     *
     * @return Collection<int, CostCenterClosing>
     */
    public function getClosings(): Collection
    {
        $project = $this->getProject();

        if ($project === null) {
            return new Collection;
        }

        return $project->costCenterClosings()
            ->with(['journalEntry', 'deliveryVoucher', 'closedBy', 'reverses'])
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Closing status badge for the selected operation: closed / partially
     * closed / open.
     *
     * @return array{key: string, color: string}|null
     */
    public function getClosingState(): ?array
    {
        $project = $this->getProject();

        if ($project === null) {
            return null;
        }

        $service = app(CostCenterClosingService::class);

        return match (true) {
            $service->isClosed($project) => ['key' => 'closed', 'color' => 'success'],
            $service->isPartiallyClosed($project) => ['key' => 'partially_closed', 'color' => 'warning'],
            default => ['key' => 'open', 'color' => 'gray'],
        };
    }

    public function canCloseCostCenter(): bool
    {
        return (bool) auth()->user()?->can('operations.close_cost_center');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->closeCostCenterAction(),
            $this->reverseClosingAction(),
        ];
    }

    /**
     * سلايد 12 — carry whatever the operation still holds in inventory to cost
     * of goods sold. Normally the delivery does this automatically; this is the
     * manual path for partial deliveries and late costs.
     */
    private function closeCostCenterAction(): Action
    {
        return Action::make('closeCostCenter')
            ->label(__('resources.operations_cost.closing.actions.close'))
            ->icon('heroicon-o-lock-closed')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('resources.operations_cost.closing.actions.close'))
            ->modalDescription(__('resources.operations_cost.closing.actions.close_description'))
            ->form([
                Placeholder::make('amount')
                    ->label(__('resources.operations_cost.closing.columns.amount'))
                    ->content(fn (): string => number_format(
                        app(CostCenterClosingService::class)->unclosedBalance($this->getProject()),
                        2,
                    )),
            ])
            ->visible(fn (): bool => $this->canCloseCostCenter()
                && $this->getProject() !== null
                && app(CostCenterClosingService::class)->unclosedBalance($this->getProject()) > 0)
            ->action(function (): void {
                try {
                    $closing = app(CostCenterClosingService::class)->close(
                        project: $this->getProject(),
                        user: auth()->user(),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('resources.operations_cost.closing.notifications.closed'))
                        ->body(__('resources.operations_cost.closing.notifications.closed_body', [
                            'amount' => number_format((float) $closing->amount, 2),
                            'entry' => $closing->journalEntry?->entry_number ?? '—',
                        ]))
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    /**
     * Undo a closing with a reversing entry. Posted entries are immutable, so
     * this never deletes anything.
     */
    private function reverseClosingAction(): Action
    {
        return Action::make('reverseClosing')
            ->label(__('resources.operations_cost.closing.actions.reverse'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->form([
                Select::make('closing_id')
                    ->label(__('resources.operations_cost.closing.fields.closing'))
                    ->options(fn (): array => $this->reversibleClosings()
                        ->mapWithKeys(fn (CostCenterClosing $c): array => [
                            $c->id => $c->closed_at->format('Y-m-d').' — '.number_format((float) $c->amount, 2),
                        ])->all())
                    ->required(),
                Textarea::make('reason')
                    ->label(__('resources.operations_cost.closing.fields.reason'))
                    ->required()
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->visible(fn (): bool => $this->canCloseCostCenter() && $this->reversibleClosings()->isNotEmpty())
            ->action(function (array $data): void {
                try {
                    app(CostCenterClosingService::class)->reverse(
                        closing: CostCenterClosing::findOrFail($data['closing_id']),
                        user: auth()->user(),
                        reason: $data['reason'],
                    );

                    Notification::make()
                        ->success()
                        ->title(__('resources.operations_cost.closing.notifications.reversed'))
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    /**
     * Closings of the selected operation that can still be reversed: real
     * closings (not reversals) that have not been reversed yet.
     *
     * @return Collection<int, CostCenterClosing>
     */
    private function reversibleClosings(): Collection
    {
        $project = $this->getProject();

        if ($project === null) {
            return new Collection;
        }

        return $project->costCenterClosings()
            ->whereNull('reverses_id')
            ->whereDoesntHave('reversal')
            ->orderByDesc('closed_at')
            ->get();
    }
}
