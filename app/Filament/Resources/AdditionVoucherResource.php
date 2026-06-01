<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\VoucherStatus;
use App\Filament\Resources\AdditionVoucherResource\Pages;
use App\Models\AdditionVoucher;
use App\Services\AdditionVoucherService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AdditionVoucherResource extends Resource
{
    protected static ?string $model = AdditionVoucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square-stack';

    protected static ?int $navigationSort = 41;

    protected static ?string $recordTitleAttribute = 'voucher_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.warehouse');
    }

    public static function getLabel(): string
    {
        return __('resources.addition_vouchers.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.addition_vouchers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.addition_vouchers.navigation_label');
    }

    public static function canViewPricing(): bool
    {
        return (bool) auth()->user()?->can('inventory.view_pricing');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.addition_vouchers.sections.details'))
                    ->icon('heroicon-o-arrow-down-on-square-stack')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('voucher_number')
                            ->label(__('resources.addition_vouchers.fields.voucher_number'))
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('resources.common.auto_generated')),

                        Forms\Components\Select::make('supplier_id')
                            ->label(__('resources.addition_vouchers.fields.supplier'))
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('purchase_order_id')
                            ->label(__('resources.addition_vouchers.fields.purchase_order'))
                            ->relationship('purchaseOrder', 'po_number')
                            ->searchable()
                            ->preload(),

                        Forms\Components\DatePicker::make('voucher_date')
                            ->label(__('resources.addition_vouchers.fields.voucher_date'))
                            ->default(now())
                            ->required(),

                        Forms\Components\TextInput::make('invoice_number')
                            ->label(__('resources.addition_vouchers.fields.invoice_number'))
                            ->maxLength(100),

                        Forms\Components\TextInput::make('invoice_value')
                            ->label(__('resources.addition_vouchers.fields.invoice_value'))
                            ->numeric()
                            ->prefix('EGP')
                            ->visible(fn () => static::canViewPricing()),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.addition_vouchers.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.addition_vouchers.sections.lines'))
                    ->icon('heroicon-o-list-bullet')
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->label(__('resources.addition_vouchers.fields.lines'))
                            ->relationship()
                            ->columns(3)
                            ->defaultItems(1)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->label(__('resources.addition_vouchers.fields.item'))
                                    ->relationship('item', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->columnSpan(static::canViewPricing() ? 1 : 2),

                                Forms\Components\TextInput::make('quantity')
                                    ->label(__('resources.addition_vouchers.fields.quantity'))
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required(),

                                Forms\Components\TextInput::make('unit_cost')
                                    ->label(__('resources.addition_vouchers.fields.unit_cost'))
                                    ->numeric()
                                    ->prefix('EGP')
                                    ->default(0)
                                    ->visible(fn () => static::canViewPricing()),
                            ])
                            ->disabled(fn (?AdditionVoucher $record) => $record?->isPosted() ?? false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('voucher_number')
                    ->label(__('resources.addition_vouchers.columns.voucher_number'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label(__('resources.addition_vouchers.columns.supplier'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('voucher_date')
                    ->label(__('resources.addition_vouchers.columns.voucher_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label(__('resources.addition_vouchers.columns.invoice_number'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('invoice_value')
                    ->label(__('resources.addition_vouchers.columns.invoice_value'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => static::canViewPricing()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.addition_vouchers.columns.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.addition_vouchers.columns.status'))
                    ->options(VoucherStatus::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                static::postAction(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (AdditionVoucher $record) => ! $record->isPosted()),
            ]);
    }

    /**
     * Reusable "Post" action — commits stock + supplier ledger entry.
     */
    public static function postAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('post')
            ->label(__('resources.addition_vouchers.actions.post'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('resources.addition_vouchers.actions.post'))
            ->modalDescription(__('resources.addition_vouchers.actions.post_confirm'))
            ->visible(fn (AdditionVoucher $record) => auth()->user()?->can('post', $record))
            ->action(function (AdditionVoucher $record) {
                try {
                    app(AdditionVoucherService::class)->post($record);
                    Notification::make()
                        ->title(__('resources.addition_vouchers.notifications.posted'))
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
            'index' => Pages\ListAdditionVouchers::route('/'),
            'create' => Pages\CreateAdditionVoucher::route('/create'),
            'edit' => Pages\EditAdditionVoucher::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['supplier', 'lines'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
