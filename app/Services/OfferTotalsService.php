<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProjectOffer;

/**
 * Rolls a BOQ offer's numbers up after its groups/items have been saved
 * (Slides 7 & 8): line items → group subtotal → offer subtotal → VAT →
 * grand total. The grand total is mirrored onto financial_amount so the
 * Tender / Active "last offer" columns keep reading a single figure.
 *
 * Called explicitly from the Offers relation manager after a create/edit
 * (never from a model event) so there is no recursive save loop.
 */
class OfferTotalsService
{
    public function recalculate(ProjectOffer $offer): ProjectOffer
    {
        $offer->load('groups.items');

        $subtotal = 0.0;
        foreach ($offer->groups as $group) {
            $groupSubtotal = round((float) $group->items->sum(fn ($item) => (float) $item->line_total), 2);
            $group->subtotal = $groupSubtotal;
            $group->save();
            $subtotal += $groupSubtotal;
        }

        $subtotal = round($subtotal, 2);
        $tax = $offer->show_vat ? round($subtotal * (float) $offer->vat_percentage / 100, 2) : 0.0;
        $grandTotal = round($subtotal + $tax, 2);

        $offer->subtotal = $subtotal;
        $offer->tax_amount = $tax;
        $offer->grand_total = $grandTotal;
        // BOQ is the source of truth for the headline figure.
        $offer->financial_amount = $grandTotal;
        $offer->save();

        return $offer;
    }
}
