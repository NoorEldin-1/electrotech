<?php

declare(strict_types=1);

namespace App\Filament\Resources\ItemResource\Pages;

use App\Enums\TransactionType;
use App\Enums\WarehouseType;
use App\Filament\Resources\ItemResource;
use App\Models\Item;
use App\Services\StockCardService;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * The item "card" (slide 10) — a read-only screen reachable from a purchase
 * order line so the buyer can inspect an item (unit cost, type, min stock).
 *
 * Below the item details it renders كرت الصنف (the moving weighted-average
 * stock card, مشتريات.pptx slide 7); the detailed flat ledger (حركات المخزون)
 * follows underneath as the InventoryTransactions relation manager.
 */
class ViewItem extends ViewRecord
{
    protected static string $resource = ItemResource::class;

    /**
     * Which warehouse's stock card is on screen. Bound to the live <select> in
     * the card view; null until mount() defaults it to the item's home warehouse.
     */
    public ?string $stockCardWarehouse = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Item $item */
        $item = $this->getRecord();
        $this->stockCardWarehouse ??= $item->homeWarehouse()->value;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => auth()->user()?->can('items.edit') ?? false),
        ];
    }

    /**
     * The warehouses this item has actually moved through (In/Out), so the
     * selector only offers cards that contain something. Falls back to the home
     * warehouse for an item with no movements yet.
     */
    private function stockCardWarehouseOptions(Item $item): array
    {
        $values = $item->inventoryTransactions()
            ->whereIn('type', [TransactionType::In->value, TransactionType::Out->value])
            ->whereNotNull('warehouse_type')
            ->distinct()
            ->pluck('warehouse_type');

        $warehouses = $values
            ->map(fn ($value) => $value instanceof WarehouseType ? $value : WarehouseType::from($value))
            ->all();

        if ($warehouses === []) {
            $warehouses = [$item->homeWarehouse()];
        }

        $options = [];
        foreach ($warehouses as $warehouse) {
            $options[$warehouse->value] = $warehouse->getLabel();
        }

        return $options;
    }

    private function selectedStockCardWarehouse(Item $item): WarehouseType
    {
        return WarehouseType::tryFrom((string) $this->stockCardWarehouse)
            ?? $item->homeWarehouse();
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make(__('resources.items.sections.item_details'))
                    ->icon('heroicon-o-cube')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('sku')
                            ->label(__('resources.items.fields.sku')),

                        TextEntry::make('name')
                            ->label(__('resources.items.fields.name')),

                        TextEntry::make('type')
                            ->label(__('resources.items.fields.type'))
                            ->badge(),

                        TextEntry::make('unit')
                            ->label(__('resources.items.fields.unit'))
                            ->badge()
                            ->color('gray'),

                        TextEntry::make('unit_cost')
                            ->label(__('resources.items.fields.unit_cost'))
                            ->money('EGP')
                            ->visible(fn () => auth()->user()?->can('inventory.view_pricing')),

                        TextEntry::make('minimum_stock')
                            ->label(__('resources.items.fields.minimum_stock'))
                            ->numeric(4),

                        TextEntry::make('description')
                            ->label(__('resources.items.fields.description'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('resources.stock_card.heading'))
                    ->icon('heroicon-o-table-cells')
                    ->description(__('resources.stock_card.subheading'))
                    ->visible(fn () => auth()->user()?->can('transactions.view') ?? false)
                    ->schema([
                        ViewEntry::make('stock_card')
                            ->hiddenLabel()
                            ->state(function (Item $record): array {
                                $card = app(StockCardService::class)
                                    ->build($record, $this->selectedStockCardWarehouse($record));
                                $card['warehouse_options'] = $this->stockCardWarehouseOptions($record);

                                return $card;
                            })
                            ->view('filament.items.stock-card'),
                    ]),
            ]);
    }
}
