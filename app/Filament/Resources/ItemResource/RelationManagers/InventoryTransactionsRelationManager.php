<?php

declare(strict_types=1);

namespace App\Filament\Resources\ItemResource\RelationManagers;

use App\Enums\TransactionType;
use App\Models\Item;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * كرت الصنف — the item's movement ledger. Read-only; lists every stock
 * transaction for the item AND its linked scrap variant, so returned scrap
 * (فضلات) shows on the same card alongside the original material.
 */
class InventoryTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryTransactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('resources.inventory_transactions.plural_label');
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('transactions.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                // Fold in the scrap variant's movements (same card).
                /** @var Item $owner */
                $owner = $this->getOwnerRecord();
                if ($scrapId = $owner->scrapVariant?->id) {
                    $query->orWhere('item_id', $scrapId);
                }

                return $query->with(['item:id,name,sku', 'performedBy:id,name']);
            })
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.inventory_transactions.columns.date'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('item.sku')
                    ->label(__('resources.inventory_transactions.columns.sku'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('resources.inventory_transactions.columns.type'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('warehouse_type')
                    ->label(__('resources.inventory_transactions.columns.warehouse'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('resources.inventory_transactions.columns.quantity'))
                    ->numeric(4)
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label(__('resources.inventory_transactions.columns.unit_cost'))
                    ->money('EGP')
                    ->toggleable()
                    ->visible(fn () => auth()->user()?->can('inventory.view_pricing')),

                Tables\Columns\TextColumn::make('notes')
                    ->label(__('resources.inventory_transactions.columns.notes'))
                    ->limit(50)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('performedBy.name')
                    ->label(__('resources.inventory_transactions.columns.performed_by'))
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('resources.inventory_transactions.columns.type'))
                    ->options(TransactionType::class)
                    ->multiple(),
            ]);
    }
}
