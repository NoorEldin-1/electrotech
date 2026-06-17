<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Models\Supplier;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * كشف حساب — the party's ledger entries ordered by date with a running
 * balance. Reused by both SupplierResource and CustomerResource (both expose
 * an `accountEntries` morphMany relation).
 */
class AccountEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'accountEntries';

    protected static ?string $icon = 'heroicon-o-document-text';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('resources.account_entries.statement');
    }

    /**
     * Visibility gate for the statement tab. This MUST be evaluated here (per
     * request, with the auth guard resolved) rather than in the resource's
     * getRelations(): Filament registers relation managers as Livewire
     * components at panel-register time — during framework bootstrap, before
     * the session/auth middleware runs — so an auth() check inside
     * getRelations() returns null and the component never gets registered,
     * causing a "Unable to find component" error when the tab later renders.
     *
     * The same statement tab is shared by the Supplier and Customer resources,
     * so the matching permission is chosen from the owner record type.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $permission = $ownerRecord instanceof Supplier
            ? 'supplier_statements.view'
            : 'customer_statements.view';

        return auth()->user()?->can($permission) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('reference')
                ->select('account_entries.*')
                ->selectRaw('SUM(amount) OVER (ORDER BY entry_date, id) AS running_balance')
                ->orderBy('entry_date')
                ->orderBy('id'))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('entry_date')
                    ->label(__('resources.account_entries.columns.date'))
                    ->date(),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('resources.account_entries.columns.reference'))
                    ->state(fn ($record): ?string => $record->reference?->voucher_number),

                Tables\Columns\TextColumn::make('operation_name')
                    ->label(__('resources.account_entries.columns.operation')),

                Tables\Columns\TextColumn::make('direction')
                    ->label(__('resources.account_entries.columns.direction'))
                    ->badge(),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('resources.account_entries.columns.amount'))
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('running_balance')
                    ->label(__('resources.account_entries.columns.running_balance'))
                    ->money('EGP')
                    ->weight('bold'),
            ]);
    }
}
