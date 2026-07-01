<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\QualitySheetStatus;
use App\Filament\Resources\QualitySheetResource\Pages;
use App\Models\QualitySheet;
use App\Services\QualitySheetService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class QualitySheetResource extends Resource
{
    protected static ?string $model = QualitySheet::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 55;

    protected static ?string $recordTitleAttribute = 'sheet_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.manufacturing');
    }

    public static function getLabel(): string
    {
        return __('resources.quality_sheets.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.quality_sheets.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.quality_sheets.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.quality_sheets.sections.details'))
                    ->icon('heroicon-o-clipboard-document-check')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('sheet_number')
                            ->label(__('resources.quality_sheets.fields.sheet_number'))
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('resources.common.auto_generated')),

                        Forms\Components\Select::make('work_order_id')
                            ->label(__('resources.quality_sheets.fields.work_order'))
                            ->relationship('workOrder', 'wo_number')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\DatePicker::make('test_date')
                            ->label(__('resources.quality_sheets.fields.test_date'))
                            ->default(now()),

                        Forms\Components\TextInput::make('operation_name')
                            ->label(__('resources.quality_sheets.fields.operation_name'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('conductor_type')
                            ->label(__('resources.quality_sheets.fields.conductor_type'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('connection_type')
                            ->label(__('resources.quality_sheets.fields.connection_type'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('cross_section')
                            ->label(__('resources.quality_sheets.fields.cross_section'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('poles_count')
                            ->label(__('resources.quality_sheets.fields.poles_count'))
                            ->numeric()
                            ->minValue(0),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.quality_sheets.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.quality_sheets.sections.lines'))
                    ->icon('heroicon-o-table-cells')
                    ->description(__('resources.quality_sheets.sections.lines_description'))
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->label(__('resources.quality_sheets.fields.lines'))
                            ->relationship()
                            ->columns(4)
                            ->itemLabel(fn (array $state): ?string => filled($state['line_no'] ?? null)
                                ? __('resources.quality_sheets.line_label', ['no' => $state['line_no']])
                                : null)
                            ->schema([
                                Forms\Components\Hidden::make('line_no'),

                                Forms\Components\TextInput::make('piece_number')
                                    ->label(__('resources.quality_sheets.fields.piece_number')),
                                Forms\Components\TextInput::make('assembly')
                                    ->label(__('resources.quality_sheets.fields.assembly')),
                                Forms\Components\TextInput::make('visual_quality')
                                    ->label(__('resources.quality_sheets.fields.visual_quality')),
                                Forms\Components\TextInput::make('required_size')
                                    ->label(__('resources.quality_sheets.fields.required_size')),

                                Forms\Components\TextInput::make('test_pe_l123n')
                                    ->label(__('resources.quality_sheets.fields.test_pe_l123n')),
                                Forms\Components\TextInput::make('test_fe_l123n')
                                    ->label(__('resources.quality_sheets.fields.test_fe_l123n')),
                                Forms\Components\TextInput::make('test_n_l12l3')
                                    ->label(__('resources.quality_sheets.fields.test_n_l12l3')),
                                Forms\Components\TextInput::make('test_l1_l2l3')
                                    ->label(__('resources.quality_sheets.fields.test_l1_l2l3')),
                                Forms\Components\TextInput::make('test_l2_l3')
                                    ->label(__('resources.quality_sheets.fields.test_l2_l3')),

                                Forms\Components\TextInput::make('notes')
                                    ->label(__('resources.quality_sheets.fields.line_notes'))
                                    ->columnSpan(2),
                            ])
                            ->disabled(fn (?QualitySheet $record) => $record?->isApproved() ?? false)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.quality_sheets.sections.approval'))
                    ->icon('heroicon-o-shield-check')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('qa_fill_status')
                            ->label(__('resources.quality_sheets.fields.qa_fill_status'))
                            ->content(fn (?QualitySheet $record) => $record && $record->qa_filled_at
                                ? __('resources.quality_sheets.status_lines.qa_filled', [
                                    'name' => $record->qaFilledBy?->name ?? '—',
                                    'date' => $record->qa_filled_at?->format('Y-m-d H:i'),
                                ])
                                : __('resources.quality_sheets.status_lines.qa_pending')),

                        Forms\Components\Placeholder::make('factory_approval_status')
                            ->label(__('resources.quality_sheets.fields.factory_approval_status'))
                            ->content(fn (?QualitySheet $record) => $record && $record->factory_approved_at
                                ? __('resources.quality_sheets.status_lines.approved', [
                                    'name' => $record->factoryApprovedBy?->name ?? '—',
                                    'date' => $record->factory_approved_at?->format('Y-m-d H:i'),
                                ])
                                : __('resources.quality_sheets.status_lines.approval_pending')),

                        Forms\Components\Textarea::make('qa_inspector_notes')
                            ->label(__('resources.quality_sheets.fields.qa_inspector_notes'))
                            ->rows(2)
                            ->disabled(fn (?QualitySheet $record) => $record?->isApproved() ?? false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sheet_number')
                    ->label(__('resources.quality_sheets.columns.sheet_number'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('workOrder.wo_number')
                    ->label(__('resources.quality_sheets.columns.work_order'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.quality_sheets.columns.status'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('qaFilledBy.name')
                    ->label(__('resources.quality_sheets.columns.qa_filled_by'))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('factoryApprovedBy.name')
                    ->label(__('resources.quality_sheets.columns.factory_approved_by'))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('test_date')
                    ->label(__('resources.quality_sheets.columns.test_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.quality_sheets.columns.status'))
                    ->options(QualitySheetStatus::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                // QA department signs off the filled sheet.
                Tables\Actions\Action::make('fill')
                    ->label(__('resources.quality_sheets.actions.fill'))
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('warning')
                    ->visible(fn (QualitySheet $record) => auth()->user()?->can('fill', $record))
                    ->form([
                        Forms\Components\Textarea::make('qa_inspector_notes')
                            ->label(__('resources.quality_sheets.fields.qa_inspector_notes'))
                            ->default(fn (QualitySheet $record) => $record->qa_inspector_notes)
                            ->rows(3),
                    ])
                    ->action(function (QualitySheet $record, array $data) {
                        try {
                            app(QualitySheetService::class)->fill($record, $data['qa_inspector_notes'] ?? null);
                            Notification::make()->success()->title(__('resources.quality_sheets.notifications.filled'))->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title(__('resources.common.action_failed'))->body($e->getMessage())->send();
                        }
                    }),

                // Factory manager final approval — idempotent on retry.
                Tables\Actions\Action::make('approve')
                    ->label(__('resources.quality_sheets.actions.approve'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('resources.quality_sheets.actions.approve_confirm'))
                    ->visible(fn (QualitySheet $record) => $record->status === QualitySheetStatus::QaFilled
                        && auth()->user()?->can('approve', $record))
                    ->action(function (QualitySheet $record) {
                        $fresh = $record->fresh();
                        if ($fresh && $fresh->isApproved()) {
                            Notification::make()->success()->title(__('resources.quality_sheets.notifications.approved'))->send();
                            return;
                        }
                        try {
                            app(QualitySheetService::class)->approve($record);
                            Notification::make()->success()->title(__('resources.quality_sheets.notifications.approved'))->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title(__('resources.common.action_failed'))->body($e->getMessage())->send();
                        }
                    }),

                // Print the sheet as the paper form (Arabic / English).
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('print_ar')
                        ->label(__('resources.quality_sheets.actions.print_ar'))
                        ->icon('heroicon-o-printer')
                        ->url(fn (QualitySheet $record): string => route('quality_sheets.pdf', ['qualitySheet' => $record, 'lang' => 'ar']))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('print_en')
                        ->label(__('resources.quality_sheets.actions.print_en'))
                        ->icon('heroicon-o-printer')
                        ->url(fn (QualitySheet $record): string => route('quality_sheets.pdf', ['qualitySheet' => $record, 'lang' => 'en']))
                        ->openUrlInNewTab(),
                ])
                    ->label(__('resources.quality_sheets.actions.print'))
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->button()
                    ->visible(fn (QualitySheet $record): bool => auth()->user()?->can('print', $record) ?? false),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (QualitySheet $record) => ! $record->isApproved()),
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
            'index' => Pages\ListQualitySheets::route('/'),
            'create' => Pages\CreateQualitySheet::route('/create'),
            'view' => Pages\ViewQualitySheet::route('/{record}'),
            'edit' => Pages\EditQualitySheet::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['workOrder:id,wo_number', 'qaFilledBy:id,name', 'factoryApprovedBy:id,name'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
