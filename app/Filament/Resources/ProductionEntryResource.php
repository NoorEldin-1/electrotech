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

    /**
     * Whether the current user may see the material cost values (المخطط/المنتج/
     * الفاقد قيمياً). Same rule as the operating-order cost columns.
     */
    public static function canViewCost(): bool
    {
        return (bool) auth()->user()?->can('operations.view_cost');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('workOrder.wo_number')
                    ->label(__('resources.production_entries.columns.work_order'))
                    ->searchable()
                    ->sortable(),

                // اسم العملية (سلايد 9) — snapshot of the work order title.
                Tables\Columns\TextColumn::make('operation_name')
                    ->label(__('resources.production_entries.columns.operation_name'))
                    ->searchable()
                    ->placeholder('—')
                    ->limit(30),

                Tables\Columns\TextColumn::make('outputItem.name')
                    ->label(__('resources.production_entries.columns.output_item'))
                    ->default(__('resources.common.no_data'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('entry_date')
                    ->label(__('resources.production_entries.columns.entry_date'))
                    ->date()
                    ->sortable(),

                // المقارنة القيمية (سلايد 9): المخطط = قيمة طلب التصنيع،
                // المنتج = قيمة أمر الصرف، الفاقد = الفرق بينهما.
                Tables\Columns\TextColumn::make('planned_material_cost')
                    ->label(__('resources.production_entries.columns.planned_cost'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => static::canViewCost()),

                Tables\Columns\TextColumn::make('actual_material_cost')
                    ->label(__('resources.production_entries.columns.actual_cost'))
                    ->money('EGP')
                    ->color('success')
                    ->sortable()
                    ->visible(fn () => static::canViewCost()),

                Tables\Columns\TextColumn::make('loss_value')
                    ->label(__('resources.production_entries.columns.loss_value'))
                    ->state(fn (ProductionEntry $record): float => $record->loss_value)
                    ->money('EGP')
                    ->color(fn (ProductionEntry $record) => $record->loss_value > 0 ? 'danger' : 'success')
                    ->visible(fn () => static::canViewCost()),

                Tables\Columns\TextColumn::make('loss_value_percentage')
                    ->label(__('resources.production_entries.columns.loss_percentage'))
                    ->state(fn (ProductionEntry $record): string => number_format($record->loss_value_percentage, 2) . '%')
                    ->badge()
                    ->color(fn (ProductionEntry $record) => $record->loss_value_percentage > 5 ? 'danger' : 'success')
                    ->visible(fn () => static::canViewCost()),

                // أعمدة الكمية — متاحة عند الطلب (مخفية افتراضياً) حتى لا تضيع أي
                // معلومة بعد التحوّل إلى العرض القيمي.
                Tables\Columns\TextColumn::make('planned_quantity')
                    ->label(__('resources.production_entries.columns.planned_quantity'))
                    ->numeric(4)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('produced_quantity')
                    ->label(__('resources.production_entries.columns.produced_quantity'))
                    ->numeric(4)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('scrap_quantity')
                    ->label(__('resources.production_entries.columns.scrap_quantity'))
                    ->numeric(4)
                    ->color('danger')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('scrap_percentage')
                    ->label(__('resources.production_entries.columns.scrap_percentage'))
                    ->state(fn (ProductionEntry $record): string => number_format($record->scrap_percentage, 2) . '%')
                    ->badge()
                    ->color(fn (ProductionEntry $record) => $record->scrap_percentage > 5 ? 'danger' : 'success')
                    ->toggleable(isToggledHiddenByDefault: true),
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
