<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\DeliveryVoucherStatus;
use App\Filament\Resources\SalesInvoiceResource\Pages;
use App\Models\DeliveryVoucher;
use App\Models\SalesInvoice;
use App\Services\SalesInvoicingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * فواتير المبيعات — Sales Invoices (سلايد 10). Each invoice is matched to the
 * delivery voucher it covers so the two totals can be reconciled and every
 * voucher's invoicing status stays current. The invoice number is entered by
 * hand: it belongs to the tax invoice book, not to this system.
 */
class SalesInvoiceResource extends Resource
{
    protected static ?string $model = SalesInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?int $navigationSort = 56;

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getLabel(): string
    {
        return __('resources.sales_invoices.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.sales_invoices.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.sales_invoices.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('resources.sales_invoices.sections.details'))
                ->icon('heroicon-o-document-currency-dollar')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('delivery_voucher_id')
                        ->label(__('resources.sales_invoices.fields.delivery_voucher'))
                        // Only delivered (activated) vouchers can be invoiced:
                        // before both approvals the goods have not left and the
                        // value is not final.
                        ->options(fn (?SalesInvoice $record) => DeliveryVoucher::query()
                            ->with('customer')
                            ->where(fn (Builder $query) => $query
                                ->where('status', DeliveryVoucherStatus::Active)
                                ->when($record, fn (Builder $q) => $q->orWhere('id', $record->delivery_voucher_id)))
                            ->orderByDesc('voucher_date')
                            ->limit(200)
                            ->get()
                            ->mapWithKeys(fn (DeliveryVoucher $voucher) => [
                                $voucher->id => $voucher->voucher_number
                                    . ' — ' . ($voucher->customer?->name ?? '—')
                                    . ' (' . number_format((float) $voucher->total_value, 2) . ' EGP)',
                            ]))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),

                    Forms\Components\Placeholder::make('remaining')
                        ->label(__('resources.sales_invoices.fields.remaining'))
                        ->content(function (Get $get, ?SalesInvoice $record): string {
                            $voucher = DeliveryVoucher::find($get('delivery_voucher_id'));

                            if (! $voucher) {
                                return '—';
                            }

                            $remaining = app(SalesInvoicingService::class)->remainingFor($voucher);

                            // When editing, the record's own amount is still
                            // counted in the voucher's invoiced total.
                            if ($record && (int) $record->delivery_voucher_id === $voucher->id) {
                                $remaining += (float) $record->amount;
                            }

                            return number_format($remaining, 2) . ' EGP';
                        }),

                    Forms\Components\TextInput::make('invoice_number')
                        ->label(__('resources.sales_invoices.fields.invoice_number'))
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true),

                    Forms\Components\DatePicker::make('invoice_date')
                        ->label(__('resources.sales_invoices.fields.invoice_date'))
                        ->default(now())
                        ->required(),

                    Forms\Components\TextInput::make('amount')
                        ->label(__('resources.sales_invoices.fields.amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->prefix('EGP')
                        ->required()
                        // Enforce the file's rule in the UI too: invoices on a
                        // voucher may not exceed the delivered value.
                        ->rules([
                            fn (Get $get, ?SalesInvoice $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                                $voucher = DeliveryVoucher::find($get('delivery_voucher_id'));

                                if (! $voucher) {
                                    return;
                                }

                                $remaining = app(SalesInvoicingService::class)->remainingFor($voucher);

                                if ($record && (int) $record->delivery_voucher_id === $voucher->id) {
                                    $remaining += (float) $record->amount;
                                }

                                if ((float) $value > $remaining + 0.01) {
                                    $fail(__('errors.sales_invoice.exceeds_voucher_value', [
                                        'number' => $voucher->voucher_number,
                                        'remaining' => number_format($remaining, 2),
                                    ]));
                                }
                            },
                        ]),

                    Forms\Components\Textarea::make('notes')
                        ->label(__('resources.sales_invoices.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label(__('resources.sales_invoices.columns.invoice_number'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('invoice_date')
                    ->label(__('resources.sales_invoices.columns.invoice_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('deliveryVoucher.voucher_number')
                    ->label(__('resources.sales_invoices.columns.delivery_voucher'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label(__('resources.sales_invoices.columns.customer'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('deliveryVoucher.invoicing_status')
                    ->label(__('resources.sales_invoices.columns.voucher_invoicing_status'))
                    ->badge(),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('resources.sales_invoices.columns.amount'))
                    ->money('EGP')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->label(__('resources.sales_invoices.columns.total_invoiced'))
                        ->money('EGP')),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('resources.sales_invoices.filters.customer'))
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('invoice_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('resources.sales_invoices.filters.from')),
                        Forms\Components\DatePicker::make('until')
                            ->label(__('resources.sales_invoices.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('invoice_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('invoice_date', '<=', $date))),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesInvoices::route('/'),
            'create' => Pages\CreateSalesInvoice::route('/create'),
            'edit' => Pages\EditSalesInvoice::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['deliveryVoucher', 'customer'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
