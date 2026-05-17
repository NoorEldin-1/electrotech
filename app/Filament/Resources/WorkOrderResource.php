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

    protected static ?string $navigationGroup = 'Manufacturing';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'wo_number';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Work Order Details')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('wo_number')
                            ->label('WO Number')
                            ->default(fn () => WorkOrder::generateWoNumber())
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->unique(ignoreRecord: true),

                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('project_id')
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('bom_id')
                            ->label('Linked BOM')
                            ->relationship('bom', 'version', fn (Builder $query) => $query->where('status', 'approved'))
                            ->getOptionLabelFromRecordUsing(fn ($record) => "v{$record->version} — {$record->project->name}")
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('status')
                            ->options(WorkOrderStatus::class)
                            ->default(WorkOrderStatus::Pending)
                            ->required(),

                        Forms\Components\Select::make('priority')
                            ->options([
                                'low' => 'Low',
                                'normal' => 'Normal',
                                'high' => 'High',
                                'urgent' => 'Urgent',
                            ])
                            ->default('normal')
                            ->required()
                            ->native(false),

                        Forms\Components\Select::make('assigned_to')
                            ->relationship('assignedTo', 'name')
                            ->searchable()
                            ->preload(),

                        Forms\Components\Hidden::make('created_by')
                            ->default(fn () => auth()->id()),
                    ]),

                Forms\Components\Section::make('Quantities & Schedule')
                    ->icon('heroicon-o-calculator')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('planned_quantity')
                            ->numeric()
                            ->default(0),

                        Forms\Components\TextInput::make('produced_quantity')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\TextInput::make('waste_quantity')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\DatePicker::make('planned_start_date'),
                        Forms\Components\DatePicker::make('planned_end_date')
                            ->after('planned_start_date'),
                    ]),

                Forms\Components\Section::make('QA Gate')
                    ->icon('heroicon-o-shield-check')
                    ->description('Quality Assurance approval is mandatory before completion.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('qa_status')
                            ->label('QA Status')
                            ->content(fn (?WorkOrder $record) => $record?->isQaApproved()
                                ? '✅ Approved by ' . $record->qaApprovedBy?->name . ' on ' . $record->qa_approved_at?->format('Y-m-d H:i')
                                : '⏳ Pending QA Review'),

                        Forms\Components\Textarea::make('qa_notes')
                            ->label('QA Notes')
                            ->rows(3)
                            ->disabled()
                            ->dehydrated()
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Description')
                    ->schema([
                        Forms\Components\Textarea::make('description')
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
                    ->label('WO #')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('project.name')
                    ->label('Project')
                    ->searchable()
                    ->limit(25),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'normal' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('planned_quantity')
                    ->label('Planned')
                    ->numeric(2),

                Tables\Columns\TextColumn::make('produced_quantity')
                    ->label('Produced')
                    ->numeric(2)
                    ->color('success'),

                Tables\Columns\TextColumn::make('assignedTo.name')
                    ->label('Assigned To')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('planned_start_date')
                    ->label('Start')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(WorkOrderStatus::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'normal' => 'Normal',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ]),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                // Start WO action
                Tables\Actions\Action::make('start')
                    ->icon('heroicon-o-play')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::Pending)
                    ->action(function (WorkOrder $record) {
                        try {
                            app(WorkOrderService::class)->start($record);
                            Notification::make()->success()->title('Work Order started')->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title('Failed')->body($e->getMessage())->send();
                        }
                    }),

                // Submit for QA
                Tables\Actions\Action::make('submit_qa')
                    ->label('Submit QA')
                    ->icon('heroicon-o-shield-check')
                    ->color('warning')
                    ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::InProgress)
                    ->form([
                        Forms\Components\TextInput::make('produced_quantity')
                            ->label('Produced Quantity')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('waste_quantity')
                            ->label('Waste Quantity')
                            ->numeric()
                            ->required()
                            ->default(0),
                    ])
                    ->action(function (WorkOrder $record, array $data) {
                        try {
                            app(WorkOrderService::class)->submitForQa(
                                $record,
                                (float) $data['produced_quantity'],
                                (float) $data['waste_quantity'],
                            );
                            Notification::make()->success()->title('Submitted for QA')->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title('Failed')->body($e->getMessage())->send();
                        }
                    }),

                // Approve QA
                Tables\Actions\Action::make('approve_qa')
                    ->label('Approve QA')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::QaReview && ! $record->isQaApproved())
                    ->form([
                        Forms\Components\Textarea::make('qa_notes')
                            ->label('QA Notes')
                            ->rows(3),
                    ])
                    ->action(function (WorkOrder $record, array $data) {
                        try {
                            app(WorkOrderService::class)->approveQa($record, $data['qa_notes'] ?? null);
                            Notification::make()->success()->title('QA Approved')->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title('Failed')->body($e->getMessage())->send();
                        }
                    }),

                // Complete WO
                Tables\Actions\Action::make('complete')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (WorkOrder $record) => $record->status === WorkOrderStatus::QaReview && $record->isQaApproved())
                    ->action(function (WorkOrder $record) {
                        try {
                            app(WorkOrderService::class)->complete($record);
                            Notification::make()->success()->title('Work Order completed')->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title('Failed')->body($e->getMessage())->send();
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
            ->with(['project', 'assignedTo', 'qaApprovedBy'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
