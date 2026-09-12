<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sales;

use App\Models\ProjectOffer;
use Illuminate\Foundation\Http\FormRequest;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ProjectOffer::class) ?? false;
    }

    /**
     * Header fields only. `version` is assigned by the server, and every money
     * column is derived from the BOQ by OfferTotalsService — accepting a
     * `grand_total` here would let the quoted figure and the priced lines
     * disagree, with nothing to say which one is the offer.
     */
    public function rules(): array
    {
        return [
            'quotation_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'vat_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'show_vat' => ['boolean'],
            'installation_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'show_installation' => ['boolean'],
            'header_note' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'general_terms' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
