<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sales;

use App\Enums\InteractionChannel;
use App\Enums\InteractionType;
use App\Models\CustomerInteraction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Log a touchpoint with the customer (متابعة العملاء). The customer is taken
 * from the operation, never sent.
 */
class StoreCustomerInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CustomerInteraction::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::enum(InteractionType::class)],
            'channel' => ['nullable', Rule::enum(InteractionChannel::class)],
            'interacted_at' => ['nullable', 'date'],
            'site_ready' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'summary' => ['nullable', 'string', 'max:255'],
            'next_follow_up_at' => ['nullable', 'date'],
        ];
    }
}
