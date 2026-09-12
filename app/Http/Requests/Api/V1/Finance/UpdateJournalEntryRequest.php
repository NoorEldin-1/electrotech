<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a draft journal entry's header.
 *
 * Drafts only — `JournalEntryPolicy::update` gates on `isDraft()`, so a posted
 * entry answers **403**. A posted entry is immutable by design: its numbers
 * have already reached the trial balance and every statement built on it, and
 * the correction is a reversing entry.
 *
 * Neither `status` nor the totals are accepted. The totals are recomputed from
 * the lines whenever they change.
 */
class UpdateJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('journal_entry')) ?? false;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['sometimes', Rule::enum(DocumentType::class)],
            'entry_date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
