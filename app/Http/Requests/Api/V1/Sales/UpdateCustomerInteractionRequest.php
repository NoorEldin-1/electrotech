<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sales;

use App\Enums\InteractionChannel;
use App\Enums\InteractionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('customer_interaction')) ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(InteractionType::class)],
            'channel' => ['sometimes', 'nullable', Rule::enum(InteractionChannel::class)],
            'interacted_at' => ['sometimes', 'date'],
            'site_ready' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
