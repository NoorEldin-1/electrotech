<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AttachmentCategory;
use App\Filament\Concerns\SplitsPartyBalances;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Support\EntityAttachments;
use App\Filament\Support\PhoneInput;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rules\Unique;

class CustomerResource extends Resource
{
    use SplitsPartyBalances;

    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.sales_crm');
    }

    public static function getLabel(): string
    {
        return __('resources.customers.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.customers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.customers.navigation_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.customers.sections.details'))
                    ->icon('heroicon-o-user-group')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('resources.customers.fields.name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('contact_person')
                            ->label(__('resources.customers.fields.contact_person'))
                            ->maxLength(255),

                        // E2E report §8 — the customer list had several records
                        // sharing one email and one phone number, because neither
                        // was ever checked. Both identify a party in an ERP: two
                        // "different" customers on the same contact details are a
                        // duplicate, and duplicates split a client's project and
                        // invoice history across two files.
                        // Both rules ignore soft-deleted rows: an archived
                        // customer must not hold their phone/email hostage.
                        PhoneInput::unique(Customer::class)
                            ->label(__('resources.customers.fields.phone')),

                        Forms\Components\TextInput::make('email')
                            ->label(__('resources.customers.fields.email'))
                            ->email()
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->whereNull('deleted_at'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('tax_number')
                            ->label(__('resources.customers.fields.tax_number'))
                            ->maxLength(100),

                        Forms\Components\Textarea::make('address')
                            ->label(__('resources.customers.fields.address'))
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('resources.customers.fields.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                // Customer file documents — upload multiple files attached to
                // the customer record. View-only when the user can't edit.
                Forms\Components\Section::make(__('resources.customers.sections.attachments'))
                    ->icon('heroicon-o-paper-clip')
                    ->columns(1)
                    ->disabled(fn () => ! (auth()->user()?->can('customers.edit') ?? false))
                    ->schema(EntityAttachments::fileUploads(
                        AttachmentCategory::customerCategories(),
                        'customer-attachments',
                    )),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.customers.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('contact_person')
                    ->label(__('resources.customers.columns.contact_person'))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label(__('resources.customers.columns.phone'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('resources.customers.columns.email'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('balance')
                    ->label(__('resources.customers.columns.balance'))
                    ->money('EGP')
                    ->state(fn (Customer $record): float => $record->balance)
                    ->visible(fn () => auth()->user()?->can('customer_statements.view')),

                // ماليات.pptx سلايد 7 — "يجب تقسيمهم الى نوعين: عملاء مدينة
                // وعملاء دائنة (دفعات مقدمة)". The split is decided by the sign
                // of the balance, and the balance sheet puts the two halves on
                // opposite sides, so it is worth seeing here too.
                Tables\Columns\TextColumn::make('balance_nature')
                    ->label(__('resources.customers.columns.balance_nature'))
                    ->badge()
                    ->state(fn (Customer $record): string => __(
                        'resources.parties.nature.' . self::balanceNature($record->balance)
                    ))
                    ->color(fn (Customer $record): string => match (self::balanceNature($record->balance)) {
                        'debit' => 'info',
                        'credit' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(__('resources.parties.nature_hint_customer'))
                    ->visible(fn () => auth()->user()?->can('customer_statements.view')),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.customers.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                // Computed from the sub-ledger, so the filter has to reach into
                // it rather than a column: sum(amount) per party decides the side.
                Tables\Filters\SelectFilter::make('balance_nature')
                    ->label(__('resources.customers.columns.balance_nature'))
                    ->options([
                        'debit' => __('resources.parties.nature.debit'),
                        'credit' => __('resources.parties.nature.credit'),
                        'settled' => __('resources.parties.nature.settled'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::filterByBalanceNature($query, $data['value'] ?? null)),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
