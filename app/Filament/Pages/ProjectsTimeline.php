<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Services\OperationTimelineService;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * قسم إدارة المشروعات — الخط الزمني للعمليات (Presentation.pptx سلايد 1).
 *
 *   «عبارة عن خدمة للعملاء ... عند طلب اى عميل لميعاد التسليم او معرفة باقى قد
 *    ايه على التسليم نكون عارفين العملية فى انى مرحلة»
 *
 * Every active operation with where it stands, how far along it is and how
 * long is left until the promised delivery; a row opens the full timeline.
 */
class ProjectsTimeline extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static string $view = 'filament.pages.projects-timeline';

    protected static ?int $navigationSort = 1;

    /**
     * Timelines of the rows on the current table page, built in one batch
     * (OperationTimelineService::forMany) the first time a cell asks.
     *
     * @var array<int, Collection<int, array<string, mixed>>>|null
     */
    private ?array $timelines = null;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.technical_office');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.projects_timeline.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.projects_timeline.title');
    }

    public function getSubheading(): ?string
    {
        return __('resources.projects_timeline.subheading');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('operations.timeline');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Project::query()
                    ->whereIn('status', [ProjectStatus::InProgress, ProjectStatus::OnHold])
                    ->with('customer:id,name')
            )
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('resources.projects.columns.code'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.projects.columns.name'))
                    ->description(fn (Project $record): ?string => $record->customer?->name ?? $record->client_name)
                    ->searchable(['name', 'client_name'])
                    ->limit(40),
                Tables\Columns\TextColumn::make('current_stage')
                    ->label(__('resources.projects_timeline.columns.current_stage'))
                    ->state(fn (Project $record): string => $this->summaryFor($record)['current_stage']['label'])
                    ->description(fn (Project $record): ?string => $this->summaryFor($record)['current_stage']['group_label'])
                    ->badge()
                    ->color(fn (Project $record): string => match ($this->summaryFor($record)['current_stage']['group']) {
                        'preliminary' => 'info',
                        'executive' => 'warning',
                        'handover' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('progress')
                    ->label(__('resources.projects_timeline.columns.progress'))
                    ->state(fn (Project $record): string => $this->summaryFor($record)['progress_percent'].'%'),
                Tables\Columns\TextColumn::make('end_date')
                    ->label(__('resources.projects_timeline.columns.expected_delivery'))
                    ->date('Y-m-d')
                    ->sortable()
                    ->placeholder(__('resources.operation_timeline.summary.not_set')),
                Tables\Columns\TextColumn::make('remaining')
                    ->label(__('resources.projects_timeline.columns.remaining'))
                    ->state(function (Project $record): ?string {
                        $summary = $this->summaryFor($record);

                        return match (true) {
                            $summary['delivered_at'] !== null => __('resources.operation_timeline.summary.delivered_on', ['date' => $summary['delivered_at']->format('Y-m-d')]),
                            $summary['days_overdue'] !== null => __('resources.operation_timeline.summary.days_overdue', ['days' => $summary['days_overdue']]),
                            $summary['days_remaining'] !== null => __('resources.operation_timeline.summary.days_remaining', ['days' => $summary['days_remaining']]),
                            default => null,
                        };
                    })
                    ->color(fn (Project $record): ?string => match (true) {
                        $this->summaryFor($record)['delivered_at'] !== null => 'success',
                        $this->summaryFor($record)['days_overdue'] !== null => 'danger',
                        default => null,
                    })
                    ->placeholder(__('resources.common.no_data')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.projects.columns.status'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // The stage is derived, not stored, so the filter resolves it
                // for the active operations (a small set) and narrows by id.
                Tables\Filters\SelectFilter::make('current_group')
                    ->label(__('resources.projects_timeline.filters.current_group'))
                    ->options(collect(OperationTimelineService::GROUPS)
                        ->mapWithKeys(fn (string $g): array => [$g => __("resources.operation_timeline.groups.{$g}")])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereIn('id', $this->projectIdsInGroup($data['value']));
                    }),
                Tables\Filters\TernaryFilter::make('overdue')
                    ->label(__('resources.projects_timeline.filters.overdue'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereDate('end_date', '<', now())
                            ->whereDoesntHave('deliveryVouchers', fn (Builder $d) => $d->whereNotNull('activated_at')),
                        false: fn (Builder $q) => $q,
                    ),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('timeline')
                        ->label(__('resources.projects_timeline.actions.view_timeline'))
                        ->icon('heroicon-o-queue-list')
                        ->slideOver()
                        ->modalWidth('7xl')
                        ->modalHeading(fn (Project $record): string => "{$record->code} — {$record->name}")
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('resources.common.close'))
                        ->modalContent(function (Project $record) {
                            $service = app(OperationTimelineService::class);
                            $timeline = $service->for($record);

                            return view('filament.components.operation-timeline', [
                                'timeline' => $timeline,
                                'summary' => $service->summary($record, $timeline),
                            ]);
                        }),
                    Tables\Actions\Action::make('open')
                        ->label(__('resources.projects_timeline.actions.open_operation'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (Project $record): string => ProjectResource::getUrl('edit', ['record' => $record]))
                        ->visible(fn (Project $record): bool => auth()->user()?->can('update', $record) ?? false),
                ])
                    ->tooltip(__('resources.common.actions')),
            ])
            ->recordAction('timeline')
            ->defaultSort('end_date')
            ->emptyStateHeading(__('resources.projects_timeline.empty'));
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryFor(Project $record): array
    {
        if ($this->timelines === null || ! isset($this->timelines[$record->getKey()])) {
            $records = $this->getTableRecords();
            $collection = $records instanceof Paginator ? collect($records->items()) : collect($records);
            if (! $collection->contains(fn (Project $p): bool => $p->is($record))) {
                $collection->push($record);
            }
            $this->timelines = ($this->timelines ?? []) + app(OperationTimelineService::class)->forMany($collection);
        }

        return app(OperationTimelineService::class)->summary($record, $this->timelines[$record->getKey()]);
    }

    /**
     * @return array<int, int>
     */
    private function projectIdsInGroup(string $group): array
    {
        $projects = Project::query()
            ->whereIn('status', [ProjectStatus::InProgress, ProjectStatus::OnHold])
            ->get(['id', 'created_at', 'customer_id', 'client_name', 'end_date']);

        $service = app(OperationTimelineService::class);

        return collect($service->forMany($projects))
            ->filter(fn (Collection $timeline, int $id): bool => $service->currentStage($projects->find($id), $timeline)['group'] === $group)
            ->keys()
            ->all();
    }
}
