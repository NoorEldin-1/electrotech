<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Sales;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\OfferGroup;
use App\Models\OfferItem;
use App\Models\ProjectOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One quotation for an operation, with its BOQ tables when they are loaded.
 *
 * Every money field is derived by OfferTotalsService from the line items and
 * stored, so the client renders these numbers and never re-computes them: a
 * total summed in Dart from a page of lines would disagree with the one the
 * ledger and the printed offer use.
 *
 * @mixin ProjectOffer
 */
class OfferResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'offer',
            'project_id' => $this->project_id,

            // Per-project monotonic; the highest version is the current offer.
            'version' => (int) $this->version,
            'quotation_number' => $this->quotation_number,
            'currency' => $this->currency,

            // The headline figure, mirrored from grand_total by
            // OfferTotalsService so the pipeline lists read one column.
            'financial_amount' => $this->money($this->financial_amount),

            'subtotal' => $this->money($this->subtotal),

            // VAT and installation are each a percentage of the subtotal,
            // added on top, and each only when the offer opts in. The `show_*`
            // flags drive whether the printed offer shows the line at all, so
            // the client needs them to reproduce the same document.
            'vat_percentage' => $this->money($this->vat_percentage),
            'show_vat' => (bool) $this->show_vat,
            'tax_amount' => $this->money($this->tax_amount),

            'installation_percentage' => $this->money($this->installation_percentage),
            'show_installation' => (bool) $this->show_installation,
            'installation_amount' => $this->money($this->installation_amount),

            'grand_total' => $this->money($this->grand_total),

            // Set when the operation moves to Active: the price that won.
            'is_winning' => (bool) $this->is_winning,

            'header_note' => $this->header_note,
            'terms' => $this->terms,
            'general_terms' => $this->general_terms,
            'notes' => $this->notes,

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'submitted_by' => $this->whenLoaded(
                'submittedBy',
                fn () => $this->submittedBy === null ? null : [
                    'id' => $this->submittedBy->id,
                    'name' => $this->submittedBy->name,
                ],
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // The BOQ itself: an offer may price several conductor options
            // side by side, so it is a list of tables rather than one.
            'groups' => $this->whenLoaded('groups', fn () => $this->groups
                ->map(fn (OfferGroup $group) => [
                    'id' => $group->id,
                    'label' => $group->label,
                    'conductor_type' => EnumPresenter::present($group->conductor_type),
                    'subtotal' => $this->money($group->subtotal),
                    'sort_order' => (int) $group->sort_order,
                    'items' => $group->relationLoaded('items')
                        ? $group->items->map(fn (OfferItem $item) => [
                            'id' => $item->id,
                            'description' => $item->description,
                            'unit' => $item->unit,
                            // offer_items.quantity is decimal(15,3), not the
                            // 4-place quantity the stock tables use. Emitting
                            // the column's real precision keeps the string
                            // from implying a digit the database never held.
                            'quantity' => $this->decimalString($item->quantity, 3),
                            'unit_price' => $this->money($item->unit_price),
                            // Derived on save (qty x price); sent so the client
                            // never has to multiply two decimals itself.
                            'line_total' => $this->money($item->line_total),
                            'sort_order' => (int) $item->sort_order,
                        ])->values()->all()
                        : [],
                ])
                ->values()
                ->all()),
        ];
    }
}
