<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\VoucherStatus;
use App\Filament\Resources\ReturnVoucherResource\Pages;
use App\Models\ReturnVoucher;
use App\Services\ReturnVoucherService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ReturnVoucherResource extends Resource
{
    protected static ?string $model = ReturnVoucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 43;

    protected static ?string $recordTitleAttribute = 'voucher_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.warehouse');
    }

    public static function getLabel(): string
    {
        return __('resources.return_vouchers.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.return_vouchers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.return_vouchers.navigation_label');
    }

    public static function canViewPricing(): bool
    {
        return (bool) auth()->user()?->can('inventory.view_pricing');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.return_vouchers.sections.details'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('voucher_number')
                            ->label(__('resources.return_vouchers.fields.voucher_number'))
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('resources.common.auto_generated')),

                        Forms\Components\Select::make('work_order_id')
                            ->label(__('resources.return_vouchers.fields.work_order'))
                            ->relationship('workOrder', 'wo_number')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\DatePicker::make('voucher_date')
                            ->label(__('resources.return_vouchers.fields.voucher_date'))
                            ->default(now())
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.return_vouchers.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.return_vouchers.sections.lines'))
                    ->icon('heroicon-o-list-bullet')
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->label(__('resources.return_vouchers.fields.lines'))
                            ->relationship()
                            ->columns(3)
                            ->defaultItems(1)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->label(__('resources.return_vouchers.fields.item'))
                                    // Only original items can be returned; scrap
                                    // variants are excluded from the picker.
                                    ->relationship('item', 'name', fn (Builder $query) => $query->where('is_scrap', false))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->suffixAction(ItemResource::quickViewAction())
                                    ->columnSpan(static::canViewPricing() ? 1 : 2),

                                Forms\Components\TextInput::make('quantity')
                                    ->label(__('resources.return_vouchers.fields.quantity'))
                                    ->numeric()
                                    // 0 is allowed so pre-filled candidate lines
                                    // can be saved as draft; post() skips them.
                                    ->minValue(0)
                                    ->default(0)
                                    ->required(),

                                Forms\Components\TextInput::make('unit_cost')
                                    ->label(__('resources.return_vouchers.fields.unit_cost'))
                                    ->numeric()
                                    ->prefix('EGP')
                                    ->default(0)
                                    ->visible(fn () => static::canViewPricing()),
                            ])
                            ->disabled(fn (?ReturnVoucher $record) => $record?->isPosted() ?? false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('voucher_number')
                    ->label(__('resources.return_vouchers.columns.voucher_number'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('workOrder.wo_number')
                    ->label(__('resources.return_vouchers.columns.work_order'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('voucher_date')
                    ->label(__('resources.return_vouchers.columns.voucher_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_value')
                    ->label(__('resources.return_vouchers.columns.total_value'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => static::canViewPricing()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.return_vouchers.columns.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.return_vouchers.columns.status'))
                    ->options(VoucherStatus::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    static::postAction(),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make()
                        ->visible(fn (ReturnVoucher $record) => ! $record->isPosted()),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    public static function postAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('post')
            ->label(__('resources.return_vouchers.actions.post'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('resources.return_vouchers.actions.post'))
            ->modalDescription(__('resources.return_vouchers.actions.post_confirm'))
            ->visible(fn (ReturnVoucher $record) => auth()->user()?->can('post', $record))
            ->action(function (ReturnVoucher $record) {
                try {
                    app(ReturnVoucherService::class)->post($record);
                    Notification::make()
                        ->title(__('resources.return_vouchers.notifications.posted'))
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
            'index' => Pages\ListReturnVouchers::route('/'),
            'create' => Pages\CreateReturnVoucher::route('/create'),
            'edit' => Pages\EditReturnVoucher::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['workOrder', 'lines'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
