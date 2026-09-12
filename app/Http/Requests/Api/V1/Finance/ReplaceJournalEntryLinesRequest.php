<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Write both sides of a draft entry in one request.
 *
 * The payload is two columns — مدين and دائن — and the side a line is written
 * on IS its direction. That removes a whole class of mistake: a row cannot end
 * up on the wrong side of an entry because someone mistyped a direction field.
 *
 * Rows carrying an `id` are updated in place, new rows are created, and rows
 * the client left out are deleted — all in one transaction, so a half-written
 * entry can never reach the books. Rows with no account are ignored, which is
 * what lets a client send the empty row a form always has at the bottom.
 */
class ReplaceJournalEntryLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('journal_entry')) ?? false;
    }

    public function rules(): array
    {
        return array_merge(
            [
                'debit_lines' => ['present', 'array', 'max:200'],
                'credit_lines' => ['present', 'array', 'max:200'],
            ],
            StoreJournalEntryRequest::lineRules(),
        );
    }
}
