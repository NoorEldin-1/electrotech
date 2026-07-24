<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountResource\RelationManagers;

use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Services\GeneralLedgerService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * دفتر الأستاذ — the posted ledger of one account (سلايد 3): each posted
 * journal line ordered by date with a running balance, the opening balance and
 * period totals shown in the table heading.
 */
class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'journalLines';

    protected static ?string $icon = 'heroicon-o-document-text';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('resources.general_ledger.title');
    }

    public static function getModelLabel(): ?string
    {
        return __('resources.general_ledger.line_label');
    }

    public static function getPluralModelLabel(): ?string
    {
        return __('resources.general_ledger.lines_label');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Account $account */
        $account = $this->getOwnerRecord();
        $sign = $account->naturalSign();
        $opening = (float) $account->opening_balance;
        $currency = $account->currency;

        $ledger = app(GeneralLedgerService::class);
        $totals = $ledger->totals($account);

        $heading = __('resources.general_ledger.opening_balance') . ': ' . number_format($opening, 2)
            . '  |  ' . __('resources.general_ledger.columns.debit') . ': ' . number_format($totals['debit'], 2)
            . '  |  ' . __('resources.general_ledger.columns.credit') . ': ' . number_format($totals['credit'], 2)
            . '  |  ' . __('resources.general_ledger.columns.balance') . ': ' . number_format($ledger->closingBalance($account), 2);

        return $table
            ->heading($heading)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('journal_entry_lines.*')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entries.status', JournalStatus::Posted->value)
                ->selectRaw('journal_entries.entry_date as je_entry_date')
                ->selectRaw('journal_entries.entry_serial as je_entry_serial')
                ->selectRaw('journal_entries.entry_number as je_entry_number')
                ->selectRaw('journal_entries.document_number as je_document_number')
                ->selectRaw('journal_entries.document_type as je_document_type')
                ->selectRaw('journal_entries.description as je_description')
                ->selectRaw("SUM(CASE WHEN journal_entry_lines.direction = 'debit' THEN journal_entry_lines.amount ELSE -journal_entry_lines.amount END) OVER (ORDER BY journal_entries.entry_date, journal_entry_lines.id) AS cum_movement")
                ->orderBy('journal_entries.entry_date')
                ->orderBy('journal_entry_lines.id'))
            ->paginated(false)
            ->emptyStateHeading(__('resources.general_ledger.empty'))
            ->emptyStateIcon('heroicon-o-document-text')
            ->columns([
                Tables\Columns\TextColumn::make('je_entry_date')
                    ->label(__('resources.general_ledger.columns.date'))
                    ->date(),

                Tables\Columns\TextColumn::make('je_entry_serial')
                    ->label(__('resources.general_ledger_report.columns.entry_serial'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('je_document_number')
                    ->label(__('resources.general_ledger.columns.document_number'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('je_document_type')
                    ->label(__('resources.general_ledger.columns.document_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DocumentType::from($state)->getLabel())
                    ->color(fn (string $state) => DocumentType::from($state)->getColor()),

                Tables\Columns\TextColumn::make('je_description')
                    ->label(__('resources.general_ledger.columns.description'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('debit')
                    ->label(__('resources.general_ledger.columns.debit'))
                    ->state(fn (Model $record): ?float => $record->direction->value === 'debit' ? (float) $record->amount : null)
                    ->money($currency),

                Tables\Columns\TextColumn::make('credit')
                    ->label(__('resources.general_ledger.columns.credit'))
                    ->state(fn (Model $record): ?float => $record->direction->value === 'credit' ? (float) $record->amount : null)
                    ->money($currency),

                Tables\Columns\TextColumn::make('cum_movement')
                    ->label(__('resources.general_ledger.columns.balance'))
                    ->state(fn (Model $record): float => round($opening + $sign * (float) $record->cum_movement, 2))
                    ->money($currency)
                    ->weight('bold'),
            ]);
    }
}
