<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AttachmentCategory;
use App\Filament\Resources\SupplierResource\Pages;
use App\Filament\Support\EntityAttachments;
use App\Filament\Support\PhoneInput;
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

                        PhoneInput::make('phone')
                            ->label(__('resources.suppliers.fields.phone')),

                        Forms\Components\TextInput::make('email')
                            ->label(__('resources.suppliers.fields.email'))
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('tax_number')
                            ->label(__('resources.suppliers.fields.tax_number'))
                            ->maxLength(100),

                        Forms\Components\Toggle::make('profit_tax_exempt')
                            ->label(__('resources.suppliers.fields.profit_tax_exempt'))
                            ->helperText(__('resources.suppliers.fields.profit_tax_exempt_helper'))
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('address')
                            ->label(__('resources.suppliers.fields.address'))
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.suppliers.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                // Slide 3: supplier-file documents (commercial registry, tax
                // card, 1%-exemption proof). View-only when the user can't edit.
                Forms\Components\Section::make(__('resources.suppliers.sections.documents'))
                    ->icon('heroicon-o-paper-clip')
                    ->columns(1)
                    ->disabled(fn () => ! (auth()->user()?->can('suppliers.edit') ?? false))
                    ->schema(EntityAttachments::fileUploads(
                        AttachmentCategory::supplierCategories(),
                        'supplier-attachments',
                    )),
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

                Tables\Columns\IconColumn::make('profit_tax_exempt')
                    ->label(__('resources.suppliers.columns.profit_tax_exempt'))
                    ->boolean()
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
        // Always return the manager so it registers as a Livewire component at
        // boot; the statement permission is enforced per-request via the
        // manager's canViewForRecord(). Gating here on auth() would run before
        // the auth guard is resolved and leave the component unregistered.
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
