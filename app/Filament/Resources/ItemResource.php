<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ItemEdge;
use App\Enums\ItemMaterial;
use App\Enums\ItemOrigin;
use App\Enums\ItemTreatment;
use App\Enums\ItemType;
use App\Enums\ProductLine;
use App\Enums\UnitOfMeasure;
use App\Filament\Resources\ItemResource\Pages;
use App\Models\Item;
use App\Models\ItemCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 32;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['sku', 'part_number', 'name', 'size', 'size_code'];
    }

    public static function getGlobalSearchResultTitle(\Illuminate\Database\Eloquent\Model $record): string
    {
        return $record->display_label;
    }

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

                        Forms\Components\Select::make('item_category_id')
                            ->label(__('resources.items.specs.fields.category'))
                            ->relationship('category', 'name', fn (Builder $query) => $query->orderBy('sort')->orderBy('name'))
                            ->searchable()
                            ->preload()
                            ->createOptionForm(ItemCategoryResource::formSchema())
                            ->createOptionAction(fn (Forms\Components\Actions\Action $action) => $action
                                ->visible(fn (): bool => auth()->user()?->can('create', ItemCategory::class) ?? false)),

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

                Forms\Components\Section::make(__('resources.items.specs.section'))
                    ->description(__('resources.items.specs.section_description'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->collapsible()
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('part_number')
                            ->label(__('resources.items.specs.fields.part_number'))
                            ->helperText(__('resources.items.specs.helpers.part_number'))
                            ->maxLength(100),
                        Forms\Components\Select::make('product_line')
                            ->label(__('resources.items.specs.fields.product_line'))
                            ->options(ProductLine::class)
                            ->native(false),
                        Forms\Components\Select::make('material')
                            ->label(__('resources.items.specs.fields.material'))
                            ->options(ItemMaterial::class)
                            ->native(false),
                        Forms\Components\TextInput::make('thickness_mm')
                            ->label(__('resources.items.specs.fields.thickness_mm'))
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('width_mm')
                            ->label(__('resources.items.specs.fields.width_mm'))
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('length_m')
                            ->label(__('resources.items.specs.fields.length_m'))
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('size_code')
                            ->label(__('resources.items.specs.fields.size_code'))
                            ->helperText(__('resources.items.specs.helpers.size_code'))
                            ->maxLength(20),
                        Forms\Components\TextInput::make('size')
                            ->label(__('resources.items.specs.fields.size'))
                            ->maxLength(50),
                        Forms\Components\TextInput::make('color')
                            ->label(__('resources.items.specs.fields.color'))
                            ->maxLength(30),
                        Forms\Components\Select::make('edge')
                            ->label(__('resources.items.specs.fields.edge'))
                            ->options(ItemEdge::class)
                            ->native(false),
                        Forms\Components\Select::make('treatment')
                            ->label(__('resources.items.specs.fields.treatment'))
                            ->options(ItemTreatment::class)
                            ->native(false),
                        Forms\Components\Select::make('origin')
                            ->label(__('resources.items.specs.fields.origin'))
                            ->options(ItemOrigin::class)
                            ->native(false),
                        Forms\Components\TextInput::make('brand')
                            ->label(__('resources.items.specs.fields.brand'))
                            ->maxLength(100),
                        Forms\Components\TextInput::make('usage')
                            ->label(__('resources.items.specs.fields.usage'))
                            ->maxLength(100),
                        Forms\Components\TextInput::make('ampere_rating')
                            ->label(__('resources.items.specs.fields.ampere_rating'))
                            ->maxLength(30),
                        Forms\Components\TextInput::make('poles')
                            ->label(__('resources.items.specs.fields.poles'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10),
                    ]),

                Forms\Components\Section::make(__('resources.items.specs.image_section'))
                    ->icon('heroicon-o-photo')
                    ->collapsible()
                    ->schema([
                        Forms\Components\FileUpload::make('image_path')
                            ->label(__('resources.items.specs.fields.image'))
                            ->image()
                            ->disk('public')
                            ->directory('items')
                            ->visibility('public')
                            // Phone photos are several MB; shrink them in the
                            // browser before upload so the catalog stays light.
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('1000')
                            ->imageResizeTargetHeight('1000')
                            ->imageResizeUpscale(false)
                            ->maxSize(5120)
                            ->imagePreviewHeight('200'),
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
            ->modalHeading(fn (Forms\Get $get): string => Item::find($get('item_id'))?->display_label
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
                Tables\Columns\ImageColumn::make('image_path')
                    ->label(__('resources.items.specs.fields.image'))
                    ->disk('public')
                    ->circular()
                    ->size(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sku')
                    ->label(__('resources.items.columns.sku'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                // The name alone is ambiguous («شريط» × 80); the spec line
                // underneath is what tells rows apart. Searching here runs
                // the multi-word item search across codes, name and sizes.
                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.items.columns.name'))
                    // Items without specs fall back to their note (e.g. «مقاس 4مم طول 8مم»).
                    ->description(fn (Item $record): ?string => $record->spec_summary ?: Str::limit((string) $record->description, 60) ?: null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->search($search))
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('part_number')
                    ->label(__('resources.items.specs.fields.part_number'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('resources.items.specs.fields.category'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('product_line')
                    ->label(__('resources.items.specs.fields.product_line'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('origin')
                    ->label(__('resources.items.specs.fields.origin'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('brand')
                    ->label(__('resources.items.specs.fields.brand'))
                    ->toggleable(isToggledHiddenByDefault: true),

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
                Tables\Filters\SelectFilter::make('item_category_id')
                    ->label(__('resources.items.specs.fields.category'))
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),
                Tables\Filters\SelectFilter::make('product_line')
                    ->label(__('resources.items.specs.fields.product_line'))
                    ->options(ProductLine::class),
                Tables\Filters\SelectFilter::make('material')
                    ->label(__('resources.items.specs.fields.material'))
                    ->options(ItemMaterial::class),
                Tables\Filters\SelectFilter::make('origin')
                    ->label(__('resources.items.specs.fields.origin'))
                    ->options(ItemOrigin::class),
                Tables\Filters\TernaryFilter::make('has_image')
                    ->label(__('resources.items.filters.has_image'))
                    ->nullable()
                    ->attribute('image_path'),
                Tables\Filters\Filter::make('zero_stock')
                    ->label(__('resources.items.filters.zero_stock'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave(
                        'inventories',
                        fn (Builder $inventory) => $inventory->where('on_hand_quantity', '>', 0),
                    )),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
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
                    ->tooltip(__('resources.common.actions')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemResource\RelationManagers\InventoryTransactionsRelationManager::class,
        ];
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
            ->with(['inventory', 'inventories', 'category'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
