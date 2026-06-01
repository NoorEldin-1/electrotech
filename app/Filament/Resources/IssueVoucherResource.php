<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\VoucherStatus;
use App\Filament\Resources\IssueVoucherResource\Pages;
use App\Models\IssueVoucher;
use App\Services\IssueVoucherService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class IssueVoucherResource extends Resource
{
    protected static ?string $model = IssueVoucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static ?int $navigationSort = 42;

    protected static ?string $recordTitleAttribute = 'voucher_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.warehouse');
    }

    public static function getLabel(): string
    {
        return __('resources.issue_vouchers.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.issue_vouchers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.issue_vouchers.navigation_label');
    }

    public static function canViewPricing(): bool
    {
        return (bool) auth()->user()?->can('inventory.view_pricing');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.issue_vouchers.sections.details'))
                    ->icon('heroicon-o-arrow-up-on-square-stack')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('voucher_number')
                            ->label(__('resources.issue_vouchers.fields.voucher_number'))
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('resources.common.auto_generated')),

                        Forms\Components\Select::make('work_order_id')
                            ->label(__('resources.issue_vouchers.fields.work_order'))
                            ->relationship('workOrder', 'wo_number')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\DatePicker::make('voucher_date')
                            ->label(__('resources.issue_vouchers.fields.voucher_date'))
                            ->default(now())
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.issue_vouchers.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.issue_vouchers.sections.lines'))
                    ->icon('heroicon-o-list-bullet')
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->label(__('resources.issue_vouchers.fields.lines'))
                            ->relationship()
                            ->columns(3)
                            ->defaultItems(1)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->label(__('resources.issue_vouchers.fields.item'))
                                    ->relationship('item', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->columnSpan(static::canViewPricing() ? 1 : 2),

                                Forms\Components\TextInput::make('quantity')
                                    ->label(__('resources.issue_vouchers.fields.quantity'))
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required(),

                                Forms\Components\TextInput::make('unit_cost')
                                    ->label(__('resources.issue_vouchers.fields.unit_cost'))
                                    ->numeric()
                                    ->prefix('EGP')
                                    ->default(0)
                                    ->visible(fn () => static::canViewPricing()),
                            ])
                            ->disabled(fn (?IssueVoucher $record) => $record?->isPosted() ?? false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('voucher_number')
                    ->label(__('resources.issue_vouchers.columns.voucher_number'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('workOrder.wo_number')
                    ->label(__('resources.issue_vouchers.columns.work_order'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('voucher_date')
                    ->label(__('resources.issue_vouchers.columns.voucher_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_value')
                    ->label(__('resources.issue_vouchers.columns.total_value'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => static::canViewPricing()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.issue_vouchers.columns.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.issue_vouchers.columns.status'))
                    ->options(VoucherStatus::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                static::postAction(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (IssueVoucher $record) => ! $record->isPosted()),
            ]);
    }

    public static function postAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('post')
            ->label(__('resources.issue_vouchers.actions.post'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('resources.issue_vouchers.actions.post'))
            ->modalDescription(__('resources.issue_vouchers.actions.post_confirm'))
            ->visible(fn (IssueVoucher $record) => auth()->user()?->can('post', $record))
            ->action(function (IssueVoucher $record) {
                try {
                    app(IssueVoucherService::class)->post($record);
                    Notification::make()
                        ->title(__('resources.issue_vouchers.notifications.posted'))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('resources.common.action_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIssueVouchers::route('/'),
            'create' => Pages\CreateIssueVoucher::route('/create'),
            'edit' => Pages\EditIssueVoucher::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['workOrder', 'lines'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
