<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Filament\Resources\ItemResource\Pages;
use App\Models\Item;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 32;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.procurement');
    }

    public static function getLabel(): string
    {
        return __('resources.items.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.items.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.items.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.items.sections.item_details'))
                    ->icon('heroicon-o-cube')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('sku')
                            ->label(__('resources.items.fields.sku'))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100),

                        Forms\Components\TextInput::make('name')
                            ->label(__('resources.items.fields.name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('type')
                            ->label(__('resources.items.fields.type'))
                            ->options(ItemType::class)
                            ->required()
                            ->native(false),

                        Forms\Components\Select::make('unit')
                            ->label(__('resources.items.fields.unit'))
                            ->options(UnitOfMeasure::class)
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('unit_cost')
                            ->label(__('resources.items.fields.unit_cost'))
                            ->numeric()
                            ->prefix('EGP')
                            ->default(0)
                            ->visible(fn () => auth()->user()?->can('inventory.view_pricing')),

                        Forms\Components\TextInput::make('minimum_stock')
                            ->label(__('resources.items.fields.minimum_stock'))
                            ->numeric()
                            ->default(0),

                        Forms\Components\Textarea::make('description')
                            ->label(__('resources.items.fields.description'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * A reusable "quick view" action for item Select fields across the app
     * (purchase orders, addition vouchers, BOMs, …). Instead of navigating to
     * the item page, it opens the item card in a modal so the user can review
     * the item without leaving the form they are filling. Attach it with
     * `->suffixAction(ItemResource::quickViewAction())`.
     */
    public static function quickViewAction(): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('quickViewItem')
            ->label(__('resources.items.actions.quick_view'))
            ->icon('heroicon-m-eye')
            ->color('gray')
            ->modalHeading(fn (Forms\Get $get): string => Item::find($get('item_id'))?->name
                ?? __('resources.items.label'))
            ->modalContent(fn (Forms\Get $get) => view('filament.items.quick-view', [
                'item' => filled($get('item_id'))
                    ? Item::with('inventories')->find($get('item_id'))
                    : null,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('resources.items.actions.close'))
            ->modalWidth(MaxWidth::TwoExtraLarge)
            ->visible(fn (Forms\Get $get): bool => filled($get('item_id'))
                && (auth()->user()?->can('items.view') ?? false));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sku')
                    ->label(__('resources.items.columns.sku'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.items.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('resources.items.columns.type'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit')
                    ->label(__('resources.items.columns.unit'))
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label(__('resources.items.columns.unit_cost'))
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn () => auth()->user()?->can('inventory.view_pricing')),

                Tables\Columns\TextColumn::make('inventory.on_hand_quantity')
                    ->label(__('resources.items.columns.on_hand'))
                    ->numeric(4)
                    ->sortable()
                    ->color(fn ($record) => $record->isBelowMinimumStock() ? 'danger' : null),

                Tables\Columns\TextColumn::make('inventory.on_hold_quantity')
                    ->label(__('resources.items.columns.on_hold'))
                    ->numeric(4)
                    ->color('warning')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('minimum_stock')
                    ->label(__('resources.items.columns.minimum_stock'))
                    ->numeric(4)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            // Clicking a row opens the quick-view modal (the 'view' action)
            // instead of navigating to a standalone page.
            ->recordUrl(null)
            ->recordAction('view')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('resources.items.columns.type'))
                    ->options(ItemType::class)
                    ->multiple(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                // Open the item card in a modal (same quick view used elsewhere)
                // instead of navigating to a standalone page.
                Tables\Actions\ViewAction::make()
                    ->modalHeading(fn (Item $record): string => $record->name)
                    ->modalContent(fn (Item $record) => view('filament.items.quick-view', [
                        'item' => $record->loadMissing('inventories'),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('resources.items.actions.close'))
                    ->modalWidth(MaxWidth::TwoExtraLarge),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItems::route('/'),
            'create' => Pages\CreateItem::route('/create'),
            'view' => Pages\ViewItem::route('/{record}'),
            'edit' => Pages\EditItem::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['inventory', 'inventories'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
