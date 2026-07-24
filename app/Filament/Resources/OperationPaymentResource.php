<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Filament\Resources\OperationPaymentResource\Pages;
use App\Models\Account;
use App\Models\OperationPayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * الدفعات النقدية والمقبوضات — Operation Payments (سلايد 1). Records cash
 * received/paid for an operation; creation runs through OperationPaymentService
 * so the optional GL bridge and claim settlement fire.
 */
class OperationPaymentResource extends Resource
{
    protected static ?string $model = OperationPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'payment_number';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.general_management');
    }

    public static function getLabel(): string
    {
        return __('resources.operation_payments.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.operation_payments.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.operation_payments.navigation_label');
    }

    public static function form(Form $form): Form
    {
        $accountOptions = fn () => Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $a) => [$a->id => $a->display_name])
            ->all();

        return $form->schema([
            Forms\Components\Section::make(__('resources.operation_payments.sections.details'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('payment_number')
                        ->label(__('resources.operation_payments.fields.payment_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('resources.common.auto_generated')),

                    Forms\Components\DatePicker::make('payment_date')
                        ->label(__('resources.operation_payments.fields.payment_date'))
                        ->default(now())
                        ->required(),

                    Forms\Components\Select::make('project_id')
                        ->label(__('resources.operation_payments.fields.operation'))
                        ->relationship('project', 'name')
                        ->searchable(['name', 'code'])
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('customer_id')
                        ->label(__('resources.operation_payments.fields.customer'))
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\Select::make('direction')
                        ->label(__('resources.operation_payments.fields.direction'))
                        ->options(PaymentDirection::class)
                        ->default(PaymentDirection::Incoming->value)
                        ->required(),

                    Forms\Components\Select::make('method')
                        ->label(__('resources.operation_payments.fields.method'))
                        ->options(PaymentMethod::class)
                        ->default(PaymentMethod::Cash->value)
                        ->required(),

                    Forms\Components\TextInput::make('amount')
                        ->label(__('resources.operation_payments.fields.amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),

                    Forms\Components\Select::make('currency')
                        ->label(__('resources.operation_payments.fields.currency'))
                        ->options(['EGP' => 'EGP', 'USD' => 'USD', 'EUR' => 'EUR'])
                        ->default('EGP')
                        ->required(),

                    Forms\Components\Select::make('account_id')
                        ->label(__('resources.operation_payments.fields.account'))
                        ->options($accountOptions)
                        ->searchable()
                        ->nullable()
                        ->helperText(__('resources.operation_payments.fields.account_hint')),

                    Forms\Components\Select::make('counter_account_id')
                        ->label(__('resources.operation_payments.fields.counter_account'))
                        ->options($accountOptions)
                        ->searchable()
                        ->nullable(),

                    Forms\Components\Select::make('financial_claim_id')
                        ->label(__('resources.operation_payments.fields.financial_claim'))
                        ->relationship('financialClaim', 'claim_number')
                        ->searchable()
                        ->nullable(),

                    Forms\Components\TextInput::make('reference')
                        ->label(__('resources.operation_payments.fields.reference'))
                        ->maxLength(255),

                    Forms\Components\Textarea::make('notes')
                        ->label(__('resources.operation_payments.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('payment_number')
                    ->label(__('resources.operation_payments.columns.payment_number'))
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('project.name')
                    ->label(__('resources.operation_payments.columns.operation'))
                    ->searchable()
                    ->limit(28),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label(__('resources.operation_payments.columns.customer'))
                    ->placeholder(__('resources.common.no_data')),
                Tables\Columns\TextColumn::make('direction')
                    ->label(__('resources.operation_payments.columns.direction'))
                    ->badge(),
                Tables\Columns\TextColumn::make('method')
                    ->label(__('resources.operation_payments.columns.method'))
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('resources.operation_payments.columns.amount'))
                    ->money(fn (OperationPayment $record): string => $record->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_date')
                    ->label(__('resources.operation_payments.columns.payment_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\IconColumn::make('journal_entry_id')
                    ->label(__('resources.operation_payments.columns.posted'))
                    ->boolean()
                    ->state(fn (OperationPayment $record): bool => $record->journal_entry_id !== null)
                    ->toggleable(),
            ])
            ->defaultSort('payment_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->label(__('resources.operation_payments.columns.direction'))
                    ->options(PaymentDirection::class),

                Tables\Filters\Filter::make('payment_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('resources.operation_payments.filters.from')),
                        Forms\Components\DatePicker::make('until')->label(__('resources.operation_payments.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('payment_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('payment_date', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make()
                        ->visible(fn (OperationPayment $record) => $record->journal_entry_id === null),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationPayments::route('/'),
            'create' => Pages\CreateOperationPayment::route('/create'),
            'edit' => Pages\EditOperationPayment::route('/{record}/edit'),
        ];
    }
}
