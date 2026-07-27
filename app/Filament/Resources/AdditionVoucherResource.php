<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PurchaseInvoicingStatus;
use App\Enums\VoucherStatus;
use App\Filament\Resources\AdditionVoucherResource\Pages;
use App\Models\AdditionVoucher;
use App\Services\AdditionVoucherService;
use App\Services\PurchaseInvoicingService;
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

                        // Slide 9: a registered supplier is optional — some
                        // receipts have no invoice or purchase order. Fall back
                        // to a free-text name when no supplier is linked.
                        Forms\Components\Select::make('supplier_id')
                            ->label(__('resources.addition_vouchers.fields.supplier'))
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->live(),

                        Forms\Components\TextInput::make('supplier_name')
                            ->label(__('resources.addition_vouchers.fields.supplier_name'))
                            ->maxLength(255)
                            ->visible(fn (Forms\Get $get): bool => blank($get('supplier_id'))),

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
                            ->helperText(__('resources.addition_vouchers.fields.invoice_number_hint'))
                            ->maxLength(100),

                        // Slide 11: the invoice's own date — the receipt may
                        // land in one tax period and its invoice in another.
                        Forms\Components\DatePicker::make('invoice_date')
                            ->label(__('resources.addition_vouchers.fields.invoice_date')),

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
                                    // ->live() so the quick-view suffix action
                                    // re-renders (becomes visible) once an item
                                    // is picked.
                                    ->live()
                                    ->suffixAction(ItemResource::quickViewAction())
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
                    ->placeholder('—')
                    ->description(fn (AdditionVoucher $record) => $record->invoice_date?->format('Y-m-d'))
                    ->toggleable(),

                // The file's reconciliation rule: total purchase invoices must
                // equal total addition vouchers — both sums sit under the table.
                Tables\Columns\TextColumn::make('received_value')
                    ->label(__('resources.addition_vouchers.columns.received_value'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => static::canViewPricing())
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->label(__('resources.addition_vouchers.columns.total_received'))
                        ->money('EGP')),

                Tables\Columns\TextColumn::make('invoice_value')
                    ->label(__('resources.addition_vouchers.columns.invoice_value'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => static::canViewPricing())
                    // An invoice that disagrees with the goods received is the
                    // exception finance is hunting for — flag it on the row.
                    ->color(fn (AdditionVoucher $record) => $record->invoiceValueMismatch() !== null ? 'danger' : null)
                    ->description(fn (AdditionVoucher $record) => $record->invoiceValueMismatch() !== null
                        ? __('resources.addition_vouchers.columns.value_mismatch', [
                            'difference' => number_format($record->invoiceValueMismatch(), 2),
                        ])
                        : null)
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->label(__('resources.addition_vouchers.columns.total_invoiced'))
                        ->money('EGP')),

                Tables\Columns\TextColumn::make('invoicing_status')
                    ->label(__('resources.addition_vouchers.columns.invoicing_status'))
                    ->badge()
                    ->sortable()
                    ->description(fn (AdditionVoucher $record) => $record->closure_reason),

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

                Tables\Filters\SelectFilter::make('invoicing_status')
                    ->label(__('resources.addition_vouchers.columns.invoicing_status'))
                    ->options(PurchaseInvoicingStatus::class),

                // Slide 11's rule made checkable: invoiced receipts whose
                // invoice value does not match the goods that came in.
                Tables\Filters\Filter::make('value_mismatch')
                    ->label(__('resources.addition_vouchers.filters.value_mismatch'))
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->where('invoicing_status', PurchaseInvoicingStatus::Invoiced)
                        ->where('invoice_value', '>', 0)
                        ->whereRaw('ABS(invoice_value - received_value) > 0.01'))
                    ->visible(fn () => static::canViewPricing()),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    static::postAction(),
                    static::recordInvoiceAction(),
                    static::closeWithoutInvoiceAction(),
                    static::reopenInvoicingAction(),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make()
                        ->visible(fn (AdditionVoucher $record) => ! $record->isPosted()),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    /**
     * تسجيل فاتورة المورّد على الإذن (سلايد 11) — capture the invoice number,
     * date and value without leaving the list. Allowed before or after
     * posting: the invoice may arrive either side of the goods.
     */
    public static function recordInvoiceAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('record_invoice')
            ->label(__('resources.addition_vouchers.actions.record_invoice'))
            ->icon('heroicon-o-document-currency-dollar')
            ->color('primary')
            ->modalHeading(__('resources.addition_vouchers.actions.record_invoice'))
            ->visible(fn (AdditionVoucher $record) => auth()->user()?->can('invoice', $record))
            ->fillForm(fn (AdditionVoucher $record) => [
                'invoice_number' => $record->invoice_number,
                'invoice_date' => $record->invoice_date,
                'invoice_value' => $record->invoice_value,
            ])
            ->form(fn (AdditionVoucher $record) => [
                Forms\Components\Placeholder::make('received_value')
                    ->label(__('resources.addition_vouchers.columns.received_value'))
                    ->content(number_format((float) $record->received_value, 2) . ' EGP')
                    ->visible(fn () => static::canViewPricing()),

                Forms\Components\TextInput::make('invoice_number')
                    ->label(__('resources.addition_vouchers.fields.invoice_number'))
                    ->required()
                    ->maxLength(100),

                Forms\Components\DatePicker::make('invoice_date')
                    ->label(__('resources.addition_vouchers.fields.invoice_date'))
                    ->default(now())
                    ->required(),

                Forms\Components\TextInput::make('invoice_value')
                    ->label(__('resources.addition_vouchers.fields.invoice_value'))
                    ->helperText($record->isPosted()
                        ? __('resources.addition_vouchers.fields.invoice_value_posted_hint')
                        : null)
                    ->numeric()
                    ->minValue(0)
                    ->prefix('EGP')
                    ->default((float) $record->received_value)
                    ->required()
                    ->visible(fn () => static::canViewPricing()),
            ])
            ->action(function (AdditionVoucher $record, array $data) {
                try {
                    app(PurchaseInvoicingService::class)->recordInvoice($record, $data);

                    Notification::make()
                        ->title(__('resources.addition_vouchers.notifications.invoice_recorded'))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    $record->refresh();

                    Notification::make()
                        ->title(__('resources.common.action_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * إقفال الإذن بدون فاتورة مع كتابة السبب (سلايد 11). Posted receipts only —
     * a draft is still editable and deletable, so closing it means nothing.
     */
    public static function closeWithoutInvoiceAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('close_without_invoice')
            ->label(__('resources.addition_vouchers.actions.close_without_invoice'))
            ->icon('heroicon-o-lock-closed')
            ->color('warning')
            ->modalHeading(__('resources.addition_vouchers.actions.close_without_invoice'))
            ->visible(fn (AdditionVoucher $record) => $record->isPosted()
                && ! $record->isInvoiced()
                && ! $record->isClosedUninvoiced()
                && auth()->user()?->can('invoice', $record))
            ->form([
                Forms\Components\Textarea::make('closure_reason')
                    ->label(__('resources.addition_vouchers.fields.closure_reason'))
                    ->helperText(__('resources.addition_vouchers.fields.closure_reason_hint'))
                    ->rows(2)
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (AdditionVoucher $record, array $data) {
                try {
                    app(PurchaseInvoicingService::class)
                        ->closeWithoutInvoice($record, $data['closure_reason'], auth()->user());

                    Notification::make()
                        ->title(__('resources.addition_vouchers.notifications.closed_uninvoiced'))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    $record->refresh();

                    Notification::make()
                        ->title(__('resources.common.action_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Undo a closure — the receipt goes back to awaiting its invoice.
     */
    public static function reopenInvoicingAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reopen_invoicing')
            ->label(__('resources.addition_vouchers.actions.reopen_invoicing'))
            ->icon('heroicon-o-lock-open')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('resources.addition_vouchers.actions.reopen_invoicing_confirm'))
            ->visible(fn (AdditionVoucher $record) => $record->isClosedUninvoiced()
                && auth()->user()?->can('invoice', $record))
            ->action(function (AdditionVoucher $record) {
                app(PurchaseInvoicingService::class)->reopen($record);

                Notification::make()
                    ->title(__('resources.addition_vouchers.notifications.invoicing_reopened'))
                    ->success()
                    ->send();
            });
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
