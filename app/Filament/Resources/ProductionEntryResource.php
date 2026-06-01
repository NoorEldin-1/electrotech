<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProductionEntryResource\Pages;
use App\Models\ProductionEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only loss/scrap report (استخراج الفاقد): planned-vs-produced per
 * completed work order with the extracted loss.
 */
class ProductionEntryResource extends Resource
{
    protected static ?string $model = ProductionEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 51;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.manufacturing');
    }

    public static function getLabel(): string
    {
        return __('resources.production_entries.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.production_entries.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.production_entries.navigation_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('workOrder.wo_number')
                    ->label(__('resources.production_entries.columns.work_order'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('outputItem.name')
                    ->label(__('resources.production_entries.columns.output_item'))
                    ->default(__('resources.common.no_data'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('entry_date')
                    ->label(__('resources.production_entries.columns.entry_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('planned_quantity')
                    ->label(__('resources.production_entries.columns.planned_quantity'))
                    ->numeric(4)
                    ->sortable(),

                Tables\Columns\TextColumn::make('produced_quantity')
                    ->label(__('resources.production_entries.columns.produced_quantity'))
                    ->numeric(4)
                    ->sortable(),

                Tables\Columns\TextColumn::make('scrap_quantity')
                    ->label(__('resources.production_entries.columns.scrap_quantity'))
                    ->numeric(4)
                    ->color('danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('scrap_percentage')
                    ->label(__('resources.production_entries.columns.scrap_percentage'))
                    ->state(fn (ProductionEntry $record): string => number_format($record->scrap_percentage, 2) . '%')
                    ->badge()
                    ->color(fn (ProductionEntry $record) => $record->scrap_percentage > 5 ? 'danger' : 'success'),
            ])
            ->defaultSort('entry_date', 'desc')
            ->filters([
                Tables\Filters\Filter::make('entry_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label(__('resources.production_entries.filters.from')),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label(__('resources.production_entries.filters.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '>=', $d))
                            ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '<=', $d));
                    }),
            ])
            ->actions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionEntries::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['workOrder:id,wo_number', 'outputItem:id,name']);
    }
}
