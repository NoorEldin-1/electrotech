<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ItemCategoryResource\Pages;
use App\Models\ItemCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * فئات الأصناف — the catalog groups (شرائط نحاس، جوانات، …). Managed from a
 * single list page with modal create/edit; permissions ride on `items.*`.
 */
class ItemCategoryResource extends Resource
{
    protected static ?string $model = ItemCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 33;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.procurement');
    }

    public static function getLabel(): string
    {
        return __('resources.item_categories.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.item_categories.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.item_categories.navigation_label');
    }

    /**
     * Shared with the item form's inline "create category" action.
     *
     * @return array<Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label(__('resources.item_categories.fields.name'))
                ->required()
                ->unique(ItemCategory::class, 'name', ignoreRecord: true)
                ->maxLength(255),
            Forms\Components\TextInput::make('sort')
                ->label(__('resources.item_categories.fields.sort'))
                ->integer()
                ->minValue(0)
                ->default(0),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema())->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.item_categories.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('items_count')
                    ->label(__('resources.item_categories.columns.items_count'))
                    ->counts('items')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort')
                    ->label(__('resources.item_categories.columns.sort'))
                    ->sortable(),
            ])
            ->defaultSort('sort')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageItemCategories::route('/'),
        ];
    }
}
