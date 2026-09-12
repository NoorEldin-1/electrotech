<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sales;

use App\Enums\LostReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelToLostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cancelToLost', $this->route('project')) ?? false;
    }

    public function rules(): array
    {
        return [
            // Required, and from the enum: "why did we lose it" is the entire
            // reason a lost operation is kept rather than deleted, and free
            // text would not aggregate into the year-end analysis.
            'lost_reason' => ['required', Rule::enum(LostReason::class)],
            'lost_reason_note' => ['nullable', 'string', 'max:1000'],
            'winning_competitor' => ['nullable', 'string', 'max:255'],
        ];
    }
}
