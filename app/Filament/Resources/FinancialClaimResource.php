<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ClaimStatus;
use App\Filament\Resources\FinancialClaimResource\Pages;
use App\Models\FinancialClaim;
use App\Services\FinancialClaimService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * المطالبة المالية — Financial Claims (سلايد 2). Raised against the customer
 * after supply/installation; moves draft → submitted → collected.
 */
class FinancialClaimResource extends Resource
{
    protected static ?string $model = FinancialClaim::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'claim_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.general_management');
    }

    public static function getLabel(): string
    {
        return __('resources.financial_claims.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.financial_claims.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.financial_claims.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('resources.financial_claims.sections.details'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('claim_number')
                        ->label(__('resources.financial_claims.fields.claim_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('resources.common.auto_generated')),

                    Forms\Components\DatePicker::make('claim_date')
                        ->label(__('resources.financial_claims.fields.claim_date'))
                        ->default(now())
                        ->required(),

                    Forms\Components\Select::make('project_id')
                        ->label(__('resources.financial_claims.fields.operation'))
                        ->relationship('project', 'name')
                        ->searchable(['name', 'code'])
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('customer_id')
                        ->label(__('resources.financial_claims.fields.customer'))
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\TextInput::make('amount')
                        ->label(__('resources.financial_claims.fields.amount'))
                        ->numeric()
                        ->minValue(0)
                        ->required(),

                    Forms\Components\Textarea::make('description')
                        ->label(__('resources.financial_claims.fields.description'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('claim_number')
                    ->label(__('resources.financial_claims.columns.claim_number'))
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.financial_claims.columns.operation'))
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label(__('resources.financial_claims.columns.customer'))
                    ->placeholder(__('resources.common.no_data')),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('resources.financial_claims.columns.amount'))
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('resources.financial_claims.columns.status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('claim_date')
                    ->label(__('resources.financial_claims.columns.claim_date'))
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('claim_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('resources.financial_claims.columns.status'))
                    ->options(ClaimStatus::class),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('submit')
                        ->label(__('resources.financial_claims.actions.submit'))
                        ->icon('heroicon-o-paper-airplane')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (FinancialClaim $record) => auth()->user()?->can('submit', $record) ?? false)
                        ->action(fn (FinancialClaim $record) => static::runClaimAction(
                            fn () => app(FinancialClaimService::class)->submit($record),
                            __('resources.financial_claims.notifications.submitted'),
                        )),

                    Tables\Actions\Action::make('collect')
                        ->label(__('resources.financial_claims.actions.collect'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (FinancialClaim $record) => auth()->user()?->can('collect', $record) ?? false)
                        ->action(fn (FinancialClaim $record) => static::runClaimAction(
                            fn () => app(FinancialClaimService::class)->collect($record),
                            __('resources.financial_claims.notifications.collected'),
                        )),

                    Tables\Actions\EditAction::make()
                        ->visible(fn (FinancialClaim $record) => $record->isDraft()),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    protected static function runClaimAction(callable $transition, string $successMessage): void
    {
        try {
            $transition();
            Notification::make()->title($successMessage)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('resources.common.action_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancialClaims::route('/'),
            'create' => Pages\CreateFinancialClaim::route('/create'),
            'edit' => Pages\EditFinancialClaim::route('/{record}/edit'),
        ];
    }
}
