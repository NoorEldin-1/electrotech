<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Finance;

use App\Enums\AccountDirection;
use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * قيد يومية — a journal entry.
 *
 * The lines are published **twice**, and both are the same rows:
 *
 *  - `lines` is the flat list, each carrying its own `direction`. This is what
 *    the database holds and what a ledger reads.
 *  - `debit_lines` / `credit_lines` is the same set split by side, which is
 *    how an accountant writes an entry: مدين in one column, دائن in the other,
 *    rather than picking a direction on every row. The write endpoint takes
 *    that shape too.
 *
 * `balanced` is the rule the whole system rests on: total debits must equal
 * total credits, and an unbalanced entry cannot be posted. It is published so
 * a client can disable its post button rather than discover the refusal.
 *
 * A **posted** entry is immutable. There is no edit and no delete; the
 * correction for a mistake is a reversing entry, because the numbers have
 * already reached the trial balance and every statement built on it.
 *
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'journal_entry',
            'entry_number' => $this->entry_number,

            // The continuous sequence across every entry, whatever its type —
            // the "رقم القيد" an auditor follows. `document_number` is the
            // per-type sequence (أمر صرف / إيصال توريد / قيد تسوية).
            'entry_serial' => $this->entry_serial,
            'document_number' => $this->document_number,
            'document_type' => EnumPresenter::present($this->document_type),

            'status' => EnumPresenter::present($this->status),
            'entry_date' => $this->entry_date?->toDateString(),
            'description' => $this->description,
            'currency' => $this->currency,

            'totals' => [
                // Stored at posting; recomputed from the lines whenever a draft
                // is edited, so the two cannot drift.
                'debit' => $this->money($this->total_debit),
                'credit' => $this->money($this->total_credit),
                'lines_debit' => $this->money($this->sideTotal('debit')),
                'lines_credit' => $this->money($this->sideTotal('credit')),
                'balanced' => abs($this->sideTotal('debit') - $this->sideTotal('credit')) < 0.01,
            ],

            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->map(fn (JournalEntryLine $line) => $this->line($line))
                ->values()
                ->all()),

            // The same rows split by side — the shape the write endpoint takes.
            'debit_lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->where('direction', AccountDirection::Debit)
                ->map(fn (JournalEntryLine $line) => $this->line($line))
                ->values()
                ->all()),

            'credit_lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->where('direction', AccountDirection::Credit)
                ->map(fn (JournalEntryLine $line) => $this->line($line))
                ->values()
                ->all()),

            'lines_count' => $this->whenCounted('lines'),
        ];
    }

    /**
     * One side's line total.
     *
     * The index attaches `lines_debit_sum` / `lines_credit_sum` as subqueries.
     * The presence of the ATTRIBUTE decides which source to read, not its
     * value: an entry with no lines on that side sums to SQL NULL, and a
     * null-coalesce would fall back to the model on exactly those rows,
     * loading the relation per row and reintroducing the N+1 (Finding #15).
     */
    private function sideTotal(string $side): float
    {
        $column = "lines_{$side}_sum";

        if (array_key_exists($column, $this->resource->getAttributes())) {
            return (float) $this->resource->getAttribute($column);
        }

        return $side === 'debit'
            ? $this->linesDebitTotal()
            : $this->linesCreditTotal();
    }

    /**
     * @return array<string, mixed>
     */
    private function line(JournalEntryLine $line): array
    {
        return [
            'id' => $line->id,
            'account_id' => $line->account_id,
            'account' => $line->relationLoaded('account') && $line->account !== null
                ? [
                    'id' => $line->account->id,
                    'code' => $line->account->code,
                    'name' => $line->account->name,
                ]
                : null,

            // The operation this line is charged to. This is what makes the
            // general ledger answer "what did this operation cost", so it is
            // worth filling in on every expense line.
            'project_id' => $line->project_id,

            'direction' => EnumPresenter::present($line->direction),
            'amount' => $this->money($line->amount),
            'line_notes' => $line->line_notes,
        ];
    }
}
