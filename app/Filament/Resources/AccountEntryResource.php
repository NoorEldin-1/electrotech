<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AccountDirection;
use App\Filament\Resources\AccountEntryResource\Pages;
use App\Models\AccountEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only financial ledger overview (الإدارة المالية): every voucher-driven
 * entry posted to a supplier or customer account. Filter to one party for
 * that party's statement (كشف حساب).
 */
class AccountEntryResource extends Resource
{
    protected static ?string $model = AccountEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getLabel(): string
    {
        return __('resources.account_entries.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.account_entries.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.account_entries.navigation_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('entry_date')
                    ->label(__('resources.account_entries.columns.date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('party')
                    ->label(__('resources.account_entries.columns.party'))
                    ->state(fn (AccountEntry $record): string => $record->party?->name ?? __('resources.common.no_data'))
                    ->description(fn (AccountEntry $record): ?string => $record->party
                        ? class_basename($record->party_type)
                        : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('resources.account_entries.columns.reference'))
                    ->state(fn (AccountEntry $record): ?string => $record->reference?->voucher_number)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('operation_name')
                    ->label(__('resources.account_entries.columns.operation'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('direction')
                    ->label(__('resources.account_entries.columns.direction'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('resources.account_entries.columns.amount'))
                    ->money('EGP')
                    ->sortable(),
            ])
            ->defaultSort('entry_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('party_type')
                    ->label(__('resources.account_entries.columns.party'))
                    ->options([
                        \App\Models\Supplier::class => __('resources.suppliers.label'),
                        \App\Models\Customer::class => __('resources.customers.label'),
                    ]),

                Tables\Filters\SelectFilter::make('direction')
                    ->label(__('resources.account_entries.columns.direction'))
                    ->options(AccountDirection::class),

                Tables\Filters\Filter::make('entry_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label(__('resources.account_entries.filters.from')),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label(__('resources.account_entries.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '<=', $d))),
            ])
            ->actions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccountEntries::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['party', 'reference']);
    }
}
