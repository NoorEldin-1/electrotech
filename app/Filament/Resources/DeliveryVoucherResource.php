<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\DeliveryVoucherStatus;
use App\Enums\InvoicingStatus;
use App\Filament\Resources\DeliveryVoucherResource\Pages;
use App\Models\DeliveryVoucher;
use App\Services\DeliveryVoucherService;
use App\Services\SalesInvoicingService;
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
                    ->visible(fn () => static::canViewPricing())
                    // The file's reconciliation rule: total invoices must equal
                    // total delivery vouchers — both sums sit under the table.
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->label(__('resources.delivery_vouchers.columns.total_delivered'))
                        ->money('EGP')),

                Tables\Columns\TextColumn::make('invoiced_value')
                    ->label(__('resources.delivery_vouchers.columns.invoiced_value'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => static::canViewPricing())
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->label(__('resources.delivery_vouchers.columns.total_invoiced'))
                        ->money('EGP')),

                Tables\Columns\TextColumn::make('invoicing_status')
                    ->label(__('resources.delivery_vouchers.columns.invoicing_status'))
                    ->badge()
                    ->sortable()
                    ->description(fn (DeliveryVoucher $record) => $record->non_invoice_reason),

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
                Tables\Filters\SelectFilter::make('invoicing_status')
                    ->label(__('resources.delivery_vouchers.columns.invoicing_status'))
                    ->options(InvoicingStatus::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    static::approveTechnicalAction(),
                    static::approveFinancialAction(),
                    static::recordInvoiceAction(),
                    static::setNonInvoiceReasonAction(),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make()
                        ->visible(fn (DeliveryVoucher $record) => ! $record->isActive()),
                ])
                    ->tooltip(__('resources.common.actions')),
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
                static::runApproval($record, fn () => app(DeliveryVoucherService::class)->approveTechnical($record, auth()->user()));
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
                static::runApproval($record, fn () => app(DeliveryVoucherService::class)->approveFinancial($record, auth()->user()));
            });
    }

    /**
     * تسجيل فاتورة مبيعات على الإذن (سلايد 10) — quick capture of the tax
     * invoice issued for a delivered voucher, without leaving the list.
     */
    public static function recordInvoiceAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('record_invoice')
            ->label(__('resources.delivery_vouchers.actions.record_invoice'))
            ->icon('heroicon-o-document-currency-dollar')
            ->color('primary')
            ->modalHeading(__('resources.delivery_vouchers.actions.record_invoice'))
            ->visible(fn (DeliveryVoucher $record) => $record->isActive()
                && ! $record->isFullyInvoiced()
                && auth()->user()?->can('sales_invoices.create'))
            ->form(fn (DeliveryVoucher $record) => [
                Forms\Components\Placeholder::make('remaining')
                    ->label(__('resources.sales_invoices.fields.remaining'))
                    ->content(number_format(app(SalesInvoicingService::class)->remainingFor($record), 2) . ' EGP'),

                Forms\Components\TextInput::make('invoice_number')
                    ->label(__('resources.sales_invoices.fields.invoice_number'))
                    ->required()
                    ->maxLength(100)
                    ->unique(table: 'sales_invoices', column: 'invoice_number'),

                Forms\Components\DatePicker::make('invoice_date')
                    ->label(__('resources.sales_invoices.fields.invoice_date'))
                    ->default(now())
                    ->required(),

                Forms\Components\TextInput::make('amount')
                    ->label(__('resources.sales_invoices.fields.amount'))
                    ->numeric()
                    ->minValue(0.01)
                    ->prefix('EGP')
                    ->default(app(SalesInvoicingService::class)->remainingFor($record))
                    ->required(),

                Forms\Components\Textarea::make('notes')
                    ->label(__('resources.sales_invoices.fields.notes'))
                    ->rows(2),
            ])
            ->action(function (DeliveryVoucher $record, array $data) {
                try {
                    app(SalesInvoicingService::class)->record($record, $data, auth()->user());
                    Notification::make()
                        ->title(__('resources.delivery_vouchers.notifications.invoice_recorded'))
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

    /**
     * سبب عدم الفوترة — samples or a personal withdrawal are legitimately
     * un-invoiced, but the reason must be documented on the voucher.
     */
    public static function setNonInvoiceReasonAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('set_non_invoice_reason')
            ->label(__('resources.delivery_vouchers.actions.set_non_invoice_reason'))
            ->icon('heroicon-o-chat-bubble-bottom-center-text')
            ->color('warning')
            // Only a delivered voucher can be "un-invoiced" — nothing left the
            // warehouse before activation.
            ->visible(fn (DeliveryVoucher $record) => $record->isActive()
                && ! $record->isFullyInvoiced()
                && auth()->user()?->can('sales_invoices.create'))
            ->fillForm(fn (DeliveryVoucher $record) => ['non_invoice_reason' => $record->non_invoice_reason])
            ->form([
                Forms\Components\TextInput::make('non_invoice_reason')
                    ->label(__('resources.delivery_vouchers.fields.non_invoice_reason'))
                    ->helperText(__('resources.delivery_vouchers.fields.non_invoice_reason_hint'))
                    ->maxLength(255),
            ])
            ->action(function (DeliveryVoucher $record, array $data) {
                $record->update(['non_invoice_reason' => $data['non_invoice_reason'] ?: null]);

                Notification::make()
                    ->title(__('resources.delivery_vouchers.notifications.reason_saved'))
                    ->success()
                    ->send();
            });
    }

    private static function runApproval(DeliveryVoucher $record, callable $callback): void
    {
        try {
            $callback();

            // Report what actually happened: a lone signature leaves the
            // voucher pending, the second one activates it.
            Notification::make()
                ->title($record->refresh()->isActive()
                    ? __('resources.delivery_vouchers.notifications.activated')
                    : __('resources.delivery_vouchers.notifications.approved'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            // The service is transactional, so nothing was persisted — reload
            // the record so the table shows the unchanged, true state.
            $record->refresh();

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
