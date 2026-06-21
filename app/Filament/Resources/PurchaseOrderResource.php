<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Filament\Support\PhoneInput;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'po_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.procurement');
    }

    public static function getLabel(): string
    {
        return __('resources.purchase_orders.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.purchase_orders.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.purchase_orders.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.purchase_orders.sections.po_details'))
                    ->icon('heroicon-o-shopping-cart')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('po_number')
                            ->label(__('resources.purchase_orders.fields.po_number'))
                            ->default(fn () => PurchaseOrder::generatePoNumber())
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->unique(ignoreRecord: true),

                        // Slide 4: only active projects (in-hand / in-progress)
                        // can carry a purchase order.
                        // Procurement feedback: the project is OPTIONAL — leaving
                        // it empty marks the order as a warehouse/stock purchase
                        // (مربوط بالمخازن) not tied to any operation.
                        Forms\Components\Select::make('project_id')
                            ->label(__('resources.purchase_orders.fields.project'))
                            ->relationship(
                                'project',
                                'name',
                                fn (Builder $query) => $query->whereIn('status', [
                                    ProjectStatus::InProgress,
                                    ProjectStatus::InHand,
                                ]),
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder(__('resources.purchase_orders.fields.project_placeholder'))
                            ->helperText(__('resources.purchase_orders.fields.project_help')),

                        // Slide 3: a PO may only target a registered supplier.
                        // The free-text supplier name/contact were dropped — the
                        // contact lives in the supplier file. supplier_name is
                        // kept as a snapshot, written from the relation on save.
                        Forms\Components\Select::make('supplier_id')
                            ->label(__('resources.purchase_orders.fields.supplier'))
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('resources.suppliers.fields.name'))
                                    ->required()
                                    ->maxLength(255),
                                PhoneInput::make('phone')
                                    ->label(__('resources.suppliers.fields.phone')),
                                Forms\Components\TextInput::make('tax_number')
                                    ->label(__('resources.suppliers.fields.tax_number'))
                                    ->maxLength(100),
                                Forms\Components\Toggle::make('profit_tax_exempt')
                                    ->label(__('resources.suppliers.fields.profit_tax_exempt')),
                            ]),

                        // Status is driven by the workflow actions (approve /
                        // receive), never edited by hand — shown read-only.
                        Forms\Components\Select::make('status')
                            ->label(__('resources.purchase_orders.fields.status'))
                            ->options(PurchaseOrderStatus::class)
                            ->default(PurchaseOrderStatus::Draft)
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\DatePicker::make('expected_delivery_date')
                            ->label(__('resources.purchase_orders.fields.expected_delivery_date')),

                        Forms\Components\Hidden::make('created_by')
                            ->default(fn () => auth()->id()),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.purchase_orders.fields.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('resources.purchase_orders.sections.line_items'))
                    ->icon('heroicon-o-list-bullet')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship()
                            ->columns(4)
                            ->schema([
                                Forms\Components\Select::make('item_id')
                                    ->label(__('resources.purchase_orders.fields.item'))
                                    ->relationship('item', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    // Slide 10: review the item "card" in a modal
                                    // without leaving the purchase order.
                                    ->suffixAction(ItemResource::quickViewAction())
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('quantity')
                                    ->label(__('resources.purchase_orders.fields.quantity'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.0001)
                                    ->live(onBlur: true)
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('unit_price')
                                    ->label(__('resources.purchase_orders.fields.unit_price'))
                                    ->numeric()
                                    ->required()
                                    ->live(onBlur: true)
                                    ->visible(fn () => auth()->user()?->can('inventory.view_pricing'))
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('received_quantity')
                                    ->label(__('resources.purchase_orders.fields.received_quantity'))
                                    ->numeric()
                                    ->default(0)
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(1),
                            ])
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->addActionLabel(__('resources.purchase_orders.actions.add_item')),

                        // Slide 3 / 6: live money breakdown so the buyer sees the
                        // 14% VAT added and the 1% withholding deducted before save.
                        Forms\Components\Placeholder::make('totals_preview')
                            ->label(__('resources.purchase_orders.sections.totals'))
                            ->visible(fn () => auth()->user()?->can('inventory.view_pricing'))
                            ->content(fn (Forms\Get $get): HtmlString => static::totalsPreview($get)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('po_number')
                    ->label(__('resources.purchase_orders.columns.po_number'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                // A PO with no operation is a warehouse/stock purchase — show
                // the "warehouse" label in place of the empty project name.
                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.purchase_orders.columns.project'))
                    ->searchable()
                    ->sortable()
                    ->limit(30)
                    ->placeholder(__('resources.purchase_orders.columns.warehouse_po')),

                Tables\Columns\TextColumn::make('project.status')
                    ->label(__('resources.purchase_orders.columns.sales_stage'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('supplier_name')
                    ->label(__('resources.purchase_orders.columns.supplier_name'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.purchase_orders.columns.status'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label(__('resources.purchase_orders.columns.total_amount'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => auth()->user()?->can('inventory.view_pricing')),

                Tables\Columns\TextColumn::make('expected_delivery_date')
                    ->label(__('resources.purchase_orders.columns.expected_delivery'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.purchase_orders.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.purchase_orders.columns.status'))
                    ->options(PurchaseOrderStatus::class)
                    ->multiple(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                // Slide 1 & 5: the technical-office manager approves the draft;
                // it then becomes "sent" (مرسل).
                Tables\Actions\Action::make('approve')
                    ->label(__('resources.purchase_orders.actions.approve'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('resources.purchase_orders.actions.approve'))
                    ->modalDescription(__('resources.purchase_orders.actions.approve_confirm'))
                    ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Draft
                        && (auth()->user()?->can('approve', $record) ?? false))
                    ->action(function (PurchaseOrder $record) {
                        $record->update([
                            'status' => PurchaseOrderStatus::Submitted,
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title(__('resources.purchase_orders.notifications.approved'))
                            ->send();
                    }),
                Tables\Actions\Action::make('receive')
                    ->label(__('resources.purchase_orders.actions.receive'))
                    ->icon('heroicon-o-truck')
                    ->color('success')
                    ->visible(fn (PurchaseOrder $record) => in_array($record->status, [
                        PurchaseOrderStatus::Submitted,
                        PurchaseOrderStatus::PartiallyReceived,
                    ]) && auth()->user()?->can('purchase_orders.receive'))
                    ->modalDescription(__('resources.purchase_orders.actions.receive_hint'))
                    ->form(fn (PurchaseOrder $record) => array_merge(
                        $record->items()
                            ->with('item:id,name')
                            ->get()
                            ->map(
                                fn (PurchaseOrderItem $poItem) => Forms\Components\TextInput::make("items.{$poItem->id}")
                                    ->label("{$poItem->item->name} (Ordered: {$poItem->quantity}, Received: {$poItem->received_quantity})")
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue($poItem->remaining_quantity)
                            )->toArray(),
                        [
                            Forms\Components\TextInput::make('invoice_number')
                                ->label(__('resources.addition_vouchers.fields.invoice_number'))
                                ->maxLength(100),
                        ],
                    ))
                    ->action(function (PurchaseOrder $record, array $data) {
                        $receivedQuantities = [];
                        foreach ($data['items'] ?? [] as $poItemId => $qty) {
                            if ((float) $qty > 0) {
                                $receivedQuantities[(int) $poItemId] = (float) $qty;
                            }
                        }

                        if (empty($receivedQuantities)) {
                            Notification::make()
                                ->warning()
                                ->title(__('resources.purchase_orders.notifications.no_quantities'))
                                ->send();

                            return;
                        }

                        try {
                            $voucher = app(PurchaseOrderService::class)
                                ->receiveItems($record, $receivedQuantities, $data['invoice_number'] ?? null);
                            Notification::make()
                                ->success()
                                ->title(__('resources.purchase_orders.notifications.received'))
                                ->body(__('resources.purchase_orders.notifications.voucher_created', ['number' => $voucher->voucher_number]))
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('resources.purchase_orders.notifications.receive_failed'))
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
                // Slide 8: print the PO as an internal document (Arabic / English).
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('print_ar')
                        ->label(__('resources.purchase_orders.actions.print_ar'))
                        ->icon('heroicon-o-printer')
                        ->url(fn (PurchaseOrder $record): string => route('purchase_orders.pdf', ['purchaseOrder' => $record, 'lang' => 'ar']))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('print_en')
                        ->label(__('resources.purchase_orders.actions.print_en'))
                        ->icon('heroicon-o-printer')
                        ->url(fn (PurchaseOrder $record): string => route('purchase_orders.pdf', ['purchaseOrder' => $record, 'lang' => 'en']))
                        ->openUrlInNewTab(),
                ])
                    ->label(__('resources.purchase_orders.actions.print'))
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->button()
                    ->visible(fn (PurchaseOrder $record): bool => auth()->user()?->can('print', $record) ?? false),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Build the live money-breakdown shown under the line items: subtotal,
     * +VAT, −profit-withholding (skipped when the chosen supplier is exempt),
     * and the resulting total. Mirrors PurchaseOrder::recalculateTotal().
     */
    protected static function totalsPreview(Forms\Get $get): HtmlString
    {
        $subtotal = 0.0;
        foreach ((array) $get('items') as $row) {
            $subtotal += (float) ($row['quantity'] ?? 0) * (float) ($row['unit_price'] ?? 0);
        }

        $applyProfitTax = true;
        if ($supplierId = $get('supplier_id')) {
            $applyProfitTax = ! (bool) (Supplier::find($supplierId)?->profit_tax_exempt ?? false);
        }

        $vatRate = (float) config('procurement.vat_percentage', 14);
        $profitRate = (float) config('procurement.profit_tax_percentage', 1);
        $vat = round($subtotal * $vatRate / 100, 2);
        $profitTax = $applyProfitTax ? round($subtotal * $profitRate / 100, 2) : 0.0;
        $total = round($subtotal + $vat - $profitTax, 2);

        $fmt = fn (float $n): string => number_format($n, 2).' '.__('resources.common.currency');

        $rows = [
            __('resources.purchase_orders.fields.subtotal') => $fmt($subtotal),
            __('resources.purchase_orders.fields.vat_amount', ['rate' => (int) $vatRate]) => $fmt($vat),
            __('resources.purchase_orders.fields.profit_tax_amount', ['rate' => (int) $profitRate]) => '− '.$fmt($profitTax),
            __('resources.purchase_orders.fields.total_amount') => $fmt($total),
        ];

        $html = '<div style="display:flex;flex-direction:column;gap:.25rem">';
        foreach ($rows as $label => $value) {
            $html .= '<div style="display:flex;justify-content:space-between;gap:2rem"><span>'
                .e($label).'</span><span style="font-variant-numeric:tabular-nums">'.e($value).'</span></div>';
        }
        $html .= '</div>';

        return new HtmlString($html);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['project:id,name'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
