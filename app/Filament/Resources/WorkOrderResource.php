<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrderResource\Pages;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'wo_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.manufacturing');
    }

    public static function getLabel(): string
    {
        return __('resources.work_orders.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.work_orders.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.work_orders.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.work_orders.sections.wo_details'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('wo_number')
                            ->label(__('resources.work_orders.fields.wo_number'))
                            ->default(fn () => WorkOrder::generateWoNumber())
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->unique(ignoreRecord: true),

                        Forms\Components\TextInput::make('title')
                            ->label(__('resources.work_orders.fields.title'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('project_id')
                            ->label(__('resources.work_orders.fields.project'))
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('bom_id')
                            ->label(__('resources.work_orders.fields.linked_bom'))
                            ->relationship(
                                'bom',
                                'version',
                                fn (Builder $query) => $query->where('status', 'approved')->with('project:id,name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn ($record) => "v{$record->version} — {$record->project?->name}")
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('status')
                            ->label(__('resources.work_orders.fields.status'))
                            ->options(WorkOrderStatus::class)
                            ->default(WorkOrderStatus::Pending)
                            ->required(),

                        Forms\Components\Select::make('priority')
                            ->label(__('resources.work_orders.fields.priority'))
                            ->options(fn () => [
                                'low' => __('resources.work_orders.priority_options.low'),
                                'normal' => __('resources.work_orders.priority_options.normal'),
                                'high' => __('resources.work_orders.priority_options.high'),
                                'urgent' => __('resources.work_orders.priority_options.urgent'),
                            ])
                            ->default('normal')
                            ->required()
                            ->native(false),

                        Forms\Components\Select::make('assigned_to')
                            ->label(__('resources.work_orders.fields.assigned_to'))
                            ->relationship('assignedTo', 'name')
                            ->searchable()
                            ->preload(),

                        Forms\Components\Hidden::make('created_by')
                            ->default(fn () => auth()->id()),
                    ]),

                Forms\Components\Section::make(__('resources.work_orders.sections.quantities_schedule'))
                    ->icon('heroicon-o-calculator')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('planned_quantity')
                            ->label(__('resources.work_orders.fields.planned_quantity'))
                            ->numeric()
                            ->default(0),

                        Forms\Components\TextInput::make('produced_quantity')
                            ->label(__('resources.work_orders.fields.produced_quantity'))
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\TextInput::make('waste_quantity')
                            ->label(__('resources.work_orders.fields.waste_quantity'))
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\DatePicker::make('planned_start_date')
                            ->label(__('resources.work_orders.fields.planned_start_date')),
                        Forms\Components\DatePicker::make('planned_end_date')
                            ->label(__('resources.work_orders.fields.planned_end_date'))
                            ->after('planned_start_date'),
                    ]),

                Forms\Components\Section::make(__('resources.work_orders.sections.qa_gate'))
                    ->icon('heroicon-o-shield-check')
                    ->description(__('resources.work_orders.sections.qa_gate_description'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('qa_status')
                            ->label(__('resources.work_orders.fields.qa_status'))
                            ->content(fn (?WorkOrder $record) => $record?->isQaApproved()
                                ? __('resources.work_orders.qa.approved_by', [
                                    'name' => $record->qaApprovedBy?->name,
                                    'date' => $record->qa_approved_at?->format('Y-m-d H:i'),
                                ])
                                : __('resources.work_orders.qa.pending')),

                        Forms\Components\Textarea::make('qa_notes')
                            ->label(__('resources.work_orders.fields.qa_notes'))
                            ->rows(3)
                            ->disabled()
                            ->dehydrated()
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.work_orders.sections.description'))
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->label(__('resources.work_orders.fields.description'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('wo_number')
                    ->label(__('resources.work_orders.columns.wo_number'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('title')
                    ->label(__('resources.work_orders.columns.title'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.work_orders.columns.project'))
                    ->searchable()
                    ->limit(25),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.work_orders.columns.status'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label(__('resources.work_orders.columns.priority'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'normal' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => __("resources.work_orders.priority_options.{$state}")),

                Tables\Columns\TextColumn::make('planned_quantity')
                    ->label(__('resources.work_orders.columns.planned'))
                    ->numeric(2),

                Tables\Columns\TextColumn::make('produced_quantity')
                    ->label(__('resources.work_orders.columns.produced'))
                    ->numeric(2)
                    ->color('success'),

                Tables\Columns\TextColumn::make('assignedTo.name')
                    ->label(__('resources.work_orders.columns.assigned_to'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('planned_start_date')
                    ->label(__('resources.work_orders.columns.start_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.work_orders.columns.status'))
                    ->options(WorkOrderStatus::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('resources.work_orders.columns.priority'))
                    ->options(fn () => [
                        'low' => __('resources.work_orders.priority_options.low'),
                        'normal' => __('resources.work_orders.priority_options.normal'),
                        'high' => __('resources.work_orders.priority_options.high'),
                        'urgent' => __('resources.work_orders.priority_options.urgent'),
                    ]),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                // Start WO action
                //
                // The action is idempotent under a flaky connection: if a
                // second submission arrives because the user's browser
                // didn't see the first response and retried, we re-read
                // the record from the DB and silently succeed if the WO
                // has already advanced past the target state. Combined
                // with HTTP-level Idempotency-Key middleware, this means
                // a double-click on a 2 s RTT link can never start the
                // WO twice or surface a confusing "wrong state" toast.
                Tables\Actions\Action::make('start')
                    ->label(__('resources.work_orders.actions.start'))
                    ->icon('heroicon-o-play')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::Pending
                        && auth()->user()?->can('work_orders.start'))
                    ->action(function (WorkOrder $record) {
                        $fresh = $record->fresh();
                        if ($fresh && $fresh->status !== WorkOrderStatus::Pending) {
                            Notification::make()->success()->title(__('resources.work_orders.notifications.started'))->send();
                            return;
                        }
                        try {
                            app(WorkOrderService::class)->start($record);
                            Notification::make()->success()->title(__('resources.work_orders.notifications.started'))->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                        }
                    }),

                // Submit for QA — same idempotency story as `start`.
                Tables\Actions\Action::make('submit_qa')
                    ->label(__('resources.work_orders.actions.submit_qa'))
                    ->icon('heroicon-o-shield-check')
                    ->color('warning')
                    ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::InProgress
                        && auth()->user()?->can('work_orders.submit_qa'))
                    ->form([
                        Forms\Components\TextInput::make('produced_quantity')
                            ->label(__('resources.work_orders.fields.produced_quantity'))
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('waste_quantity')
                            ->label(__('resources.work_orders.fields.waste_quantity'))
                            ->numeric()
                            ->required()
                            ->default(0),
                    ])
                    ->action(function (WorkOrder $record, array $data) {
                        $fresh = $record->fresh();
                        if ($fresh && $fresh->status !== WorkOrderStatus::InProgress) {
                            Notification::make()->success()->title(__('resources.work_orders.notifications.submitted_qa'))->send();
                            return;
                        }
                        try {
                            app(WorkOrderService::class)->submitForQa(
                                $record,
                                (float) $data['produced_quantity'],
                                (float) $data['waste_quantity'],
                            );
                            Notification::make()->success()->title(__('resources.work_orders.notifications.submitted_qa'))->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                        }
                    }),

                // Approve QA — idempotent: a retry that lands after the
                // first approval finds qa_approved_by already set and
                // returns success without touching the row again.
                Tables\Actions\Action::make('approve_qa')
                    ->label(__('resources.work_orders.actions.approve_qa'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::QaReview
                        && ! $record->isQaApproved()
                        && auth()->user()?->can('work_orders.approve_qa'))
                    ->form([
                        Forms\Components\Textarea::make('qa_notes')
                            ->label(__('resources.work_orders.fields.qa_notes'))
                            ->rows(3),
                    ])
                    ->action(function (WorkOrder $record, array $data) {
                        $fresh = $record->fresh();
                        if ($fresh && $fresh->isQaApproved()) {
                            Notification::make()->success()->title(__('resources.work_orders.notifications.qa_approved'))->send();
                            return;
                        }
                        try {
                            app(WorkOrderService::class)->approveQa($record, $data['qa_notes'] ?? null);
                            Notification::make()->success()->title(__('resources.work_orders.notifications.qa_approved'))->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                        }
                    }),

                // Complete WO — idempotent on retry.
                Tables\Actions\Action::make('complete')
                    ->label(__('resources.work_orders.actions.complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::QaReview
                        && $record->isQaApproved()
                        && auth()->user()?->can('work_orders.complete'))
                    ->action(function (WorkOrder $record) {
                        $fresh = $record->fresh();
                        if ($fresh && $fresh->status === WorkOrderStatus::Completed) {
                            Notification::make()->success()->title(__('resources.work_orders.notifications.completed'))->send();
                            return;
                        }
                        try {
                            app(WorkOrderService::class)->complete($record);
                            Notification::make()->success()->title(__('resources.work_orders.notifications.completed'))->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title(__('resources.work_orders.notifications.failed'))->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkOrders::route('/'),
            'create' => Pages\CreateWorkOrder::route('/create'),
            'edit' => Pages\EditWorkOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'project:id,name',
                'assignedTo:id,name',
                'qaApprovedBy:id,name',
            ])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
