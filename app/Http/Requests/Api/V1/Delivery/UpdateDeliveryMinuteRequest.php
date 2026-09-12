<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a delivery minute's text.
 *
 * Only before distribution — the policy gates on `! isDistributed()`, so an
 * already-circulated minute answers 403. Every department is holding the copy
 * that went out; changing the original afterwards would leave the file and the
 * copies saying different things with nothing to mark the difference.
 */
class UpdateDeliveryMinuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('delivery_minute')) ?? false;
    }

    public function rules(): array
    {
        return [
            'content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'minute_date' => ['sometimes', 'date'],
        ];
    }
}
