<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\TransactionType;
use App\Filament\Resources\InventoryTransactionResource\Pages;
use App\Models\InventoryTransaction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryTransactionResource extends Resource
{
    protected static ?string $model = InventoryTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.warehouse');
    }

    public static function getLabel(): string
    {
        return __('resources.inventory_transactions.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.inventory_transactions.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.inventory_transactions.navigation_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.inventory_transactions.columns.date'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('item.name')
                    ->label(__('resources.inventory_transactions.columns.item'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('item.sku')
                    ->label(__('resources.inventory_transactions.columns.sku'))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('resources.inventory_transactions.columns.type'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('resources.inventory_transactions.columns.quantity'))
                    ->numeric(4)
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference_type')
                    ->label(__('resources.inventory_transactions.columns.source'))
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : __('resources.common.no_data'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label(__('resources.inventory_transactions.columns.notes'))
                    ->limit(50)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('performedBy.name')
                    ->label(__('resources.inventory_transactions.columns.performed_by'))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('resources.inventory_transactions.columns.type'))
                    ->options(TransactionType::class)
                    ->multiple(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryTransactions::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'item:id,name,sku',
                'performedBy:id,name',
            ]);
    }
}
