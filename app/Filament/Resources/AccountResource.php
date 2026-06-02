<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Filament\Resources\AccountResource\Pages;
use App\Filament\Resources\AccountResource\RelationManagers\LedgerEntriesRelationManager;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * شجرة الحسابات — the chart of accounts (سلايد 5). Each record is a
 * general-ledger account; drill into one to see its ledger (دفتر الأستاذ).
 */
class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 57;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getLabel(): string
    {
        return __('resources.accounts.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.accounts.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.accounts.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.accounts.sections.details'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label(__('resources.accounts.fields.code'))
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),

                        Forms\Components\TextInput::make('name')
                            ->label(__('resources.accounts.fields.name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('name_en')
                            ->label(__('resources.accounts.fields.name_en'))
                            ->maxLength(255),

                        Forms\Components\Select::make('type')
                            ->label(__('resources.accounts.fields.type'))
                            ->options(AccountType::class)
                            ->required()
                            ->live()
                            // Default the nature to the type's natural side, but
                            // leave it editable for contra accounts.
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state instanceof AccountType) {
                                    $set('nature', $state->naturalDirection()->value);
                                } elseif (is_string($state) && $type = AccountType::tryFrom($state)) {
                                    $set('nature', $type->naturalDirection()->value);
                                }
                            }),

                        Forms\Components\Select::make('nature')
                            ->label(__('resources.accounts.fields.nature'))
                            ->options(AccountDirection::class)
                            ->required(),

                        Forms\Components\Select::make('currency')
                            ->label(__('resources.accounts.fields.currency'))
                            ->options([
                                'EGP' => 'EGP',
                                'USD' => 'USD',
                                'EUR' => 'EUR',
                            ])
                            ->default('EGP')
                            ->required(),

                        Forms\Components\Select::make('parent_id')
                            ->label(__('resources.accounts.fields.parent'))
                            ->relationship('parent', 'name')
                            ->searchable()
                            ->preload(),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('resources.accounts.fields.is_active'))
                            ->default(true),
                    ]),

                Forms\Components\Section::make(__('resources.accounts.sections.opening'))
                    ->icon('heroicon-o-flag')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('opening_balance')
                            ->label(__('resources.accounts.fields.opening_balance'))
                            ->numeric()
                            ->default(0)
                            ->helperText(__('resources.accounts.fields.opening_balance_hint')),

                        Forms\Components\DatePicker::make('opening_balance_date')
                            ->label(__('resources.accounts.fields.opening_balance_date')),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.accounts.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('resources.accounts.columns.code'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.accounts.columns.name'))
                    ->description(fn (Account $record): ?string => $record->name_en)
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('resources.accounts.columns.type'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('nature')
                    ->label(__('resources.accounts.columns.nature'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('currency')
                    ->label(__('resources.accounts.columns.currency'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('opening_balance')
                    ->label(__('resources.accounts.columns.opening_balance'))
                    ->money(fn (Account $record): string => $record->currency)
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('resources.accounts.columns.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('resources.accounts.columns.type'))
                    ->options(AccountType::class),

                Tables\Filters\SelectFilter::make('nature')
                    ->label(__('resources.accounts.columns.nature'))
                    ->options(AccountDirection::class),

                Tables\Filters\SelectFilter::make('currency')
                    ->label(__('resources.accounts.columns.currency'))
                    ->options([
                        'EGP' => 'EGP',
                        'USD' => 'USD',
                        'EUR' => 'EUR',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('resources.accounts.columns.is_active')),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LedgerEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
