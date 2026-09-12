<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sales;

use App\Enums\ConductorType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceBoqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('offer')) ?? false;
    }

    /**
     * `groups` is `present` rather than `required`: an empty array is a
     * meaningful instruction ("clear this BOQ"), and `required` rejects `[]`.
     *
     * There is no rule for `line_total`, `subtotal` or `grand_total`. The
     * server derives all three. A client that sent them would find them
     * ignored, which is the correct outcome but a confusing one, so they are
     * simply not part of the documented contract.
     */
    public function rules(): array
    {
        return [
            'groups' => ['present', 'array', 'max:20'],
            'groups.*.label' => ['required', 'string', 'max:255'],
            'groups.*.conductor_type' => ['nullable', Rule::enum(ConductorType::class)],

            // A cap on lines per table. A BOQ this large is a data-entry
            // accident, and without a bound one request could build a document
            // big enough to time out every later read of the offer.
            'groups.*.items' => ['present', 'array', 'max:500'],
            'groups.*.items.*.description' => ['required', 'string', 'max:1000'],
            'groups.*.items.*.unit' => ['nullable', 'string', 'max:50'],
            'groups.*.items.*.quantity' => ['required', 'numeric', 'min:0'],
            'groups.*.items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
