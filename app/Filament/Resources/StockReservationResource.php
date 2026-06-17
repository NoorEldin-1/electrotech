<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ProjectStatus;
use App\Enums\ReservationStatus;
use App\Enums\WarehouseType;
use App\Filament\Resources\StockReservationResource\Pages;
use App\Models\Item;
use App\Models\Project;
use App\Models\StockReservation;
use App\Services\ReservationService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * حجز الكمية للعملية — Stock reservations (سلايد 1). Reserve and release runs
 * through ReservationService so the underlying inventory hold/release stays
 * consistent; the resource itself never writes stock directly.
 */
class StockReservationResource extends Resource
{
    protected static ?string $model = StockReservation::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?int $navigationSort = 33;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.procurement');
    }

    public static function getLabel(): string
    {
        return __('resources.stock_reservations.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.stock_reservations.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.stock_reservations.navigation_label');
    }

    // Reservations are created through the service-backed "reserve" action
    // (which holds the stock), never via a plain record-create form.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.stock_reservations.columns.operation'))
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('item.name')
                    ->label(__('resources.stock_reservations.columns.item'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('warehouse_type')
                    ->label(__('resources.stock_reservations.columns.warehouse'))
                    ->badge(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('resources.stock_reservations.columns.quantity'))
                    ->numeric(4)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.stock_reservations.columns.status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.common.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('released_at')
                    ->label(__('resources.stock_reservations.columns.released_at'))
                    ->dateTime()
                    ->placeholder(__('resources.common.no_data'))
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.stock_reservations.columns.status'))
                    ->options(ReservationStatus::class),
            ])
            ->headerActions([
                Tables\Actions\Action::make('reserve')
                    ->label(__('resources.stock_reservations.actions.reserve'))
                    ->icon('heroicon-o-lock-closed')
                    ->visible(fn () => auth()->user()?->can('operations.reserve') ?? false)
                    ->form([
                        Forms\Components\Select::make('project_id')
                            ->label(__('resources.stock_reservations.fields.operation'))
                            ->options(fn () => Project::query()
                                ->whereIn('status', [ProjectStatus::InProgress->value, ProjectStatus::OnHold->value])
                                ->orderByDesc('id')
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('item_id')
                            ->label(__('resources.stock_reservations.fields.item'))
                            ->options(fn () => Item::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('warehouse_type')
                            ->label(__('resources.stock_reservations.fields.warehouse'))
                            ->options(WarehouseType::class)
                            ->default(WarehouseType::RawMaterials->value)
                            ->required(),
                        Forms\Components\TextInput::make('quantity')
                            ->label(__('resources.stock_reservations.fields.quantity'))
                            ->numeric()
                            ->minValue(0.0001)
                            ->required(),
                        Forms\Components\TextInput::make('notes')
                            ->label(__('resources.stock_reservations.fields.notes'))
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(ReservationService::class)->reserveForProject(
                                project: Project::findOrFail($data['project_id']),
                                item: Item::findOrFail($data['item_id']),
                                quantity: (float) $data['quantity'],
                                warehouse: WarehouseType::from($data['warehouse_type']),
                                notes: $data['notes'] ?? null,
                            );
                            Notification::make()
                                ->title(__('resources.stock_reservations.notifications.reserved'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('resources.common.action_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('release')
                    ->label(__('resources.stock_reservations.actions.release'))
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (StockReservation $record) => $record->isActive()
                        && (auth()->user()?->can('operations.reserve') ?? false))
                    ->action(function (StockReservation $record): void {
                        try {
                            app(ReservationService::class)->release($record);
                            Notification::make()
                                ->title(__('resources.stock_reservations.notifications.released'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('resources.common.action_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockReservations::route('/'),
        ];
    }
}
