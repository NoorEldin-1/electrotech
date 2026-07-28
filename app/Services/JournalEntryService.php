<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    /**
     * Post a journal entry (ترحيل القيد): lock it and reflect it in the
     * ledgers / trial balance. Enforces double-entry: it must have at least
     * two lines and total debits must equal total credits. Idempotent by
     * status — a posted entry cannot be posted again.
     *
     * @throws \RuntimeException if already posted, has too few lines, or is unbalanced
     */
    public function post(JournalEntry $entry): void
    {
        if ($entry->isPosted()) {
            throw new \RuntimeException(__('errors.journal.already_posted', ['number' => $entry->entry_number]));
        }

        $entry->loadMissing('lines');

        if ($entry->lines->count() < 2) {
            throw new \RuntimeException(__('errors.journal.no_lines', ['number' => $entry->entry_number]));
        }

        if (! $entry->isBalanced()) {
            throw new \RuntimeException(__('errors.journal.unbalanced', [
                'number' => $entry->entry_number,
                'debit' => number_format($entry->linesDebitTotal(), 2),
                'credit' => number_format($entry->linesCreditTotal(), 2),
            ]));
        }

        DB::transaction(function () use ($entry) {
            $entry->update([
                'status' => JournalStatus::Posted,
                'total_debit' => $entry->linesDebitTotal(),
                'total_credit' => $entry->linesCreditTotal(),
                'posted_by' => Auth::id(),
                'posted_at' => now(),
            ]);
        });
    }

    /**
     * Split an entry's lines into the two sides the form edits separately —
     * مدين on one side, دائن on the other. The direction lives in the column
     * as it always has; the split exists only so the accountant writes each
     * side in its own column instead of picking a direction on every line.
     *
     * @return array{debit_lines: array<int, array<string, mixed>>, credit_lines: array<int, array<string, mixed>>}
     */
    public static function splitLines(JournalEntry $entry): array
    {
        $entry->loadMissing('lines');

        $side = fn (AccountDirection $direction): array => $entry->lines
            ->where('direction', $direction)
            ->map(fn (JournalEntryLine $line): array => [
                'id' => $line->id,
                'account_id' => $line->account_id,
                'project_id' => $line->project_id,
                'amount' => $line->amount,
                'line_notes' => $line->line_notes,
            ])
            ->values()
            ->all();

        return [
            'debit_lines' => $side(AccountDirection::Debit),
            'credit_lines' => $side(AccountDirection::Credit),
        ];
    }

    /**
     * Write both sides back to `journal_entry_lines`: rows carrying an `id`
     * are updated, new rows are created with the direction of the side they
     * were written on, and rows the user removed are deleted — all in one
     * transaction so a half-written entry can never reach the books. Rows
     * without an account are ignored (an empty row the user never filled in).
     *
     * @param  array<int, array<string, mixed>>  $debitLines
     * @param  array<int, array<string, mixed>>  $creditLines
     *
     * @throws \RuntimeException if the entry is already posted
     */
    public function syncLines(JournalEntry $entry, array $debitLines, array $creditLines): void
    {
        if ($entry->isPosted()) {
            throw new \RuntimeException(__('errors.journal.already_posted', ['number' => $entry->entry_number]));
        }

        DB::transaction(function () use ($entry, $debitLines, $creditLines): void {
            $keptIds = [];

            foreach ([
                [AccountDirection::Debit, $debitLines],
                [AccountDirection::Credit, $creditLines],
            ] as [$direction, $lines]) {
                foreach ($lines as $line) {
                    if (blank($line['account_id'] ?? null)) {
                        continue;
                    }

                    $payload = [
                        'account_id' => $line['account_id'],
                        'project_id' => $line['project_id'] ?? null,
                        'direction' => $direction,
                        'amount' => (float) ($line['amount'] ?? 0),
                        'line_notes' => $line['line_notes'] ?? null,
                    ];

                    // A cloned row carries the id it was copied from, so an id
                    // already claimed by an earlier row means this one is new.
                    $existing = filled($line['id'] ?? null) && ! in_array((int) $line['id'], $keptIds, true)
                        ? $entry->lines()->whereKey($line['id'])->first()
                        : null;

                    if ($existing !== null) {
                        $existing->update($payload);
                        $keptIds[] = $existing->getKey();

                        continue;
                    }

                    $keptIds[] = $entry->lines()->create($payload)->getKey();
                }
            }

            $entry->lines()->whereKeyNot($keptIds)->delete();
        });

        $entry->load('lines');
        $entry->recalculateTotals();
    }

    /**
     * The treasury side of a cash document: an أمر صرف takes money out of the
     * treasury (credit), an إيصال توريد puts money in (debit). A قيد تسوية has
     * no cash side. Returns null — leaving the form untouched — when the type
     * has no treasury side or the chart of accounts does not carry the
     * configured treasury code.
     *
     * @return array{direction: AccountDirection, side: string, account_id: int}|null
     */
    public static function treasuryAccountFor(DocumentType|string|null $type, ?string $currency = null): ?array
    {
        $type = $type instanceof DocumentType ? $type : DocumentType::tryFrom((string) $type);

        $direction = match ($type) {
            DocumentType::PaymentOrder => AccountDirection::Credit,
            DocumentType::SupplyReceipt => AccountDirection::Debit,
            default => null,
        };

        if ($direction === null) {
            return null;
        }

        $code = config('finance.treasury_account_codes_by_currency.'.strtoupper((string) $currency))
            ?? config('finance.treasury_account_code');

        $account = Account::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            return null;
        }

        return [
            'direction' => $direction,
            'side' => $direction === AccountDirection::Debit ? 'debit_lines' : 'credit_lines',
            'account_id' => $account->getKey(),
        ];
    }
}
