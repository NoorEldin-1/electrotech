<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * QA sign-off on a manufacturing order.
 *
 * Gated on `work_orders.approve_qa`, which is deliberately not
 * `work_orders.edit`: whoever built the order is not thereby entitled to
 * certify that what came off the line is good.
 */
class ApproveWorkOrderQaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work_orders.approve_qa') ?? false;
    }

    public function rules(): array
    {
        return [
            'qa_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
