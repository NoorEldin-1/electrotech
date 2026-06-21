<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\DeliveryVoucherStatus;
use App\Filament\Resources\DeliveryVoucherResource\Pages;
use App\Models\DeliveryVoucher;
use App\Services\DeliveryVoucherService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DeliveryVoucherResource extends Resource
{
    protected static ?string $model = DeliveryVoucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 43;

    protected static ?string $recordTitleAttribute = 'voucher_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.warehouse');
    }

    public static function getLabel(): string
    {
        return __('resources.delivery_vouchers.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.delivery_vouchers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.delivery_vouchers.navigation_label');
    }

    public static function canViewPricing(): bool
    {
        return (bool) auth()->user()?->can('inventory.view_pricing');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.delivery_vouchers.sections.details'))
                    ->icon('heroicon-o-truck')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('voucher_number')
                            ->label(__('resources.delivery_vouchers.fields.voucher_number'))
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('resources.common.auto_generated')),

                        Forms\Components\Select::make('customer_id')
                            ->label(__('resources.delivery_vouchers.fields.customer'))
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('project_id')
                            ->label(__('resources.delivery_vouchers.fields.project'))
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload(),

                        Forms\Components\TextInput::make('supply_order_number')
                            ->label(__('resources.delivery_vouchers.fields.supply_order_number'))
                            ->maxLength(100),

                        Forms\Components\DatePicker::make('voucher_date')
                            ->label(__('resources.delivery_vouchers.fields.voucher_date'))
                            ->default(now())
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.delivery_vouchers.fields.notes'))
                            ->rows(1)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.delivery_vouchers.sections.specs'))
                    ->icon('heroicon-o-bolt')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('plates_count')
                            ->label(__('resources.delivery_vouchers.fields.plates_count'))
                            ->numeric()
                            ->minValue(0),

                        Forms\Components\TextInput::make('protection_degree')
                            ->label(__('resources.delivery_vouchers.fields.protection_degree'))
                            ->maxLength(50),

                        Forms\Components\TextInput::make('insulation_voltage')
                            ->label(__('resources.delivery_vouchers.fields.insulation_voltage'))
                            ->maxLength(50),
                    ]),

                Forms\Components\Section::make(__('resources.delivery_vouchers.sections.lines'))
                    ->icon('heroicon-o-list-bullet')
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->label(__('resources.delivery_vouchers.fields.lines'))
                            ->relationship()
                            ->columns(3)
                            ->defaultItems(1)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->label(__('resources.delivery_vouchers.fields.item'))
                                    ->relationship('item', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    // ->live() so the quick-view suffix action
                                    // re-renders (becomes visible) once an item
                                    // is picked.
                                    ->live()
                                    ->suffixAction(ItemResource::quickViewAction())
                                    ->columnSpan(static::canViewPricing() ? 1 : 2),

                                Forms\Components\TextInput::make('quantity')
                                    ->label(__('resources.delivery_vouchers.fields.quantity'))
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required(),

                                Forms\Components\TextInput::make('unit_cost')
                                    ->label(__('resources.delivery_vouchers.fields.unit_cost'))
                                    ->numeric()
                                    ->prefix('EGP')
                                    ->default(0)
                                    ->visible(fn () => static::canViewPricing()),
                            ])
                            ->disabled(fn (?DeliveryVoucher $record) => $record?->isActive() ?? false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('voucher_number')
                    ->label(__('resources.delivery_vouchers.columns.voucher_number'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label(__('resources.delivery_vouchers.columns.customer'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('voucher_date')
                    ->label(__('resources.delivery_vouchers.columns.voucher_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\IconColumn::make('technical_approved_by')
                    ->label(__('resources.delivery_vouchers.columns.technical'))
                    ->boolean(),

                Tables\Columns\IconColumn::make('financial_approved_by')
                    ->label(__('resources.delivery_vouchers.columns.financial'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('total_value')
                    ->label(__('resources.delivery_vouchers.columns.total_value'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => static::canViewPricing()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.delivery_vouchers.columns.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.delivery_vouchers.columns.status'))
                    ->options(DeliveryVoucherStatus::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                static::approveTechnicalAction(),
                static::approveFinancialAction(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (DeliveryVoucher $record) => ! $record->isActive()),
            ]);
    }

    public static function approveTechnicalAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve_technical')
            ->label(__('resources.delivery_vouchers.actions.approve_technical'))
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (DeliveryVoucher $record) => auth()->user()?->can('approveTechnical', $record))
            ->action(function (DeliveryVoucher $record) {
                static::runApproval(fn () => app(DeliveryVoucherService::class)->approveTechnical($record, auth()->user()));
            });
    }

    public static function approveFinancialAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve_financial')
            ->label(__('resources.delivery_vouchers.actions.approve_financial'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (DeliveryVoucher $record) => auth()->user()?->can('approveFinancial', $record))
            ->action(function (DeliveryVoucher $record) {
                static::runApproval(fn () => app(DeliveryVoucherService::class)->approveFinancial($record, auth()->user()));
            });
    }

    private static function runApproval(callable $callback): void
    {
        try {
            $callback();
            Notification::make()
                ->title(__('resources.delivery_vouchers.notifications.approved'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('resources.common.action_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryVouchers::route('/'),
            'create' => Pages\CreateDeliveryVoucher::route('/create'),
            'edit' => Pages\EditDeliveryVoucher::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'lines'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
