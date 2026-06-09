<?php

namespace Tests\Feature\Sales;

use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use App\Services\OfferTotalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slides 7 & 8: a BOQ offer's totals roll up items → group subtotal → offer
 * subtotal → VAT → grand total, and the grand total is mirrored onto
 * financial_amount so the Tender "last offer" columns keep working.
 */
class OfferBoqTest extends TestCase
{
    use RefreshDatabase;

    private function makeOffer(array $attributes = []): ProjectOffer
    {
        $this->actingAs(User::factory()->create());

        return ProjectOffer::factory()->for(Project::factory()->create())->create($attributes);
    }

    public function test_line_total_is_computed_on_save(): void
    {
        $offer = $this->makeOffer();
        $group = $offer->groups()->create(['label' => 'A']);
        $item = $group->items()->create(['description' => 'X', 'quantity' => 3, 'unit_price' => 250]);

        $this->assertEquals(750, (float) $item->fresh()->line_total);
    }

    public function test_totals_roll_up_from_items_with_vat(): void
    {
        $offer = $this->makeOffer(['vat_percentage' => 14, 'show_vat' => true]);

        $bi = $offer->groups()->create(['label' => 'Bi-Metal Offer', 'sort_order' => 0]);
        $bi->items()->create(['description' => 'Busway 4000A', 'unit' => 'MT', 'quantity' => 18, 'unit_price' => 1000]);
        $bi->items()->create(['description' => 'Flexible Unit', 'unit' => 'NO', 'quantity' => 1, 'unit_price' => 500]);

        app(OfferTotalsService::class)->recalculate($offer);

        $fresh = $offer->fresh();
        $this->assertEquals(18500, (float) $fresh->subtotal);
        $this->assertEquals(2590, (float) $fresh->tax_amount);   // 18500 * 14%
        $this->assertEquals(21090, (float) $fresh->grand_total);
        $this->assertEquals(21090, (float) $fresh->financial_amount); // mirrored for Tender column
        $this->assertEquals(18500, (float) $bi->fresh()->subtotal);
    }

    public function test_vat_is_excluded_when_show_vat_is_false(): void
    {
        $offer = $this->makeOffer(['vat_percentage' => 14, 'show_vat' => false]);
        $group = $offer->groups()->create(['label' => 'A']);
        $group->items()->create(['description' => 'X', 'quantity' => 10, 'unit_price' => 100]);

        app(OfferTotalsService::class)->recalculate($offer);

        $fresh = $offer->fresh();
        $this->assertEquals(1000, (float) $fresh->subtotal);
        $this->assertEquals(0, (float) $fresh->tax_amount);
        $this->assertEquals(1000, (float) $fresh->grand_total);
    }

    public function test_multiple_tables_sum_into_one_grand_total(): void
    {
        $offer = $this->makeOffer(['vat_percentage' => 10, 'show_vat' => true]);

        $g1 = $offer->groups()->create(['label' => 'Bi-Metal', 'sort_order' => 0]);
        $g1->items()->create(['description' => 'a', 'quantity' => 2, 'unit_price' => 1000]); // 2000

        $g2 = $offer->groups()->create(['label' => 'Copper', 'sort_order' => 1]);
        $g2->items()->create(['description' => 'b', 'quantity' => 1, 'unit_price' => 3000]); // 3000

        app(OfferTotalsService::class)->recalculate($offer);

        $fresh = $offer->fresh();
        $this->assertEquals(5000, (float) $fresh->subtotal);
        $this->assertEquals(500, (float) $fresh->tax_amount);
        $this->assertEquals(5500, (float) $fresh->grand_total);
        $this->assertEquals(5500, (float) $fresh->financial_amount);
    }
}
