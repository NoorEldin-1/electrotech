<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\FacilityStatus;
use App\Filament\Resources\CreditFacilityResource\Pages;
use App\Filament\Resources\CreditFacilityResource\RelationManagers\AllocationsRelationManager;
use App\Models\Account;
use App\Models\CreditFacility;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * التسهيلات الائتمانية — Credit Facilities (سلايد 1). Monitors a credit line's
 * limit, usage and available headroom, with per-operation allocations.
 */
class CreditFacilityResource extends Resource
{
    protected static ?string $model = CreditFacility::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.general_management');
    }

    public static function getLabel(): string
    {
        return __('resources.credit_facilities.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.credit_facilities.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.credit_facilities.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('resources.credit_facilities.sections.details'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('resources.credit_facilities.fields.name'))
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('status')
                        ->label(__('resources.credit_facilities.fields.status'))
                        ->options(FacilityStatus::class)
                        ->default(FacilityStatus::Active->value)
                        ->required(),

                    Forms\Components\Select::make('account_id')
                        ->label(__('resources.credit_facilities.fields.account'))
                        ->options(fn () => Account::query()
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->display_name])
                            ->all())
                        ->searchable()
                        ->nullable(),

                    Forms\Components\Select::make('customer_id')
                        ->label(__('resources.credit_facilities.fields.customer'))
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\TextInput::make('limit_amount')
                        ->label(__('resources.credit_facilities.fields.limit_amount'))
                        ->numeric()
                        ->minValue(0)
                        ->required(),

                    Forms\Components\Select::make('currency')
                        ->label(__('resources.credit_facilities.fields.currency'))
                        ->options(['EGP' => 'EGP', 'USD' => 'USD', 'EUR' => 'EUR'])
                        ->default('EGP')
                        ->required(),

                    Forms\Components\DatePicker::make('start_date')
                        ->label(__('resources.credit_facilities.fields.start_date')),

                    Forms\Components\DatePicker::make('end_date')
                        ->label(__('resources.credit_facilities.fields.end_date')),

                    Forms\Components\Textarea::make('notes')
                        ->label(__('resources.credit_facilities.fields.notes'))
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
                    ->label(__('resources.credit_facilities.columns.name'))
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('account.name')
                    ->label(__('resources.credit_facilities.columns.account'))
                    ->placeholder(__('resources.common.no_data'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('limit_amount')
                    ->label(__('resources.credit_facilities.columns.limit_amount'))
                    ->money(fn (CreditFacility $record): string => $record->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('used_amount')
                    ->label(__('resources.credit_facilities.columns.used_amount'))
                    ->money(fn (CreditFacility $record): string => $record->currency)
                    ->state(fn (CreditFacility $record): float => $record->used_amount),
                Tables\Columns\TextColumn::make('available_amount')
                    ->label(__('resources.credit_facilities.columns.available_amount'))
                    ->money(fn (CreditFacility $record): string => $record->currency)
                    ->state(fn (CreditFacility $record): float => $record->available_amount)
                    ->color(fn (CreditFacility $record): string => $record->available_amount <= 0 ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.credit_facilities.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.credit_facilities.columns.status'))
                    ->options(FacilityStatus::class),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            AllocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCreditFacilities::route('/'),
            'create' => Pages\CreateCreditFacility::route('/create'),
            'edit' => Pages\EditCreditFacility::route('/{record}/edit'),
        ];
    }
}
