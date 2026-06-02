<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JournalStatus;
use App\Models\JournalEntry;
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
}
