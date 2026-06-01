<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 31;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.procurement');
    }

    public static function getLabel(): string
    {
        return __('resources.suppliers.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.suppliers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.suppliers.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.suppliers.sections.details'))
                    ->icon('heroicon-o-truck')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('resources.suppliers.fields.name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('contact_person')
                            ->label(__('resources.suppliers.fields.contact_person'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label(__('resources.suppliers.fields.phone'))
                            ->tel()
                            ->maxLength(50),

                        Forms\Components\TextInput::make('email')
                            ->label(__('resources.suppliers.fields.email'))
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('tax_number')
                            ->label(__('resources.suppliers.fields.tax_number'))
                            ->maxLength(100),

                        Forms\Components\Textarea::make('address')
                            ->label(__('resources.suppliers.fields.address'))
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.suppliers.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.suppliers.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('contact_person')
                    ->label(__('resources.suppliers.columns.contact_person'))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label(__('resources.suppliers.columns.phone'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('resources.suppliers.columns.email'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('balance')
                    ->label(__('resources.suppliers.columns.balance'))
                    ->money('EGP')
                    ->state(fn (Supplier $record): float => $record->balance)
                    ->visible(fn () => auth()->user()?->can('supplier_statements.view')),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.suppliers.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
        if (! auth()->user()?->can('supplier_statements.view')) {
            return [];
        }

        return [
            \App\Filament\RelationManagers\AccountEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
