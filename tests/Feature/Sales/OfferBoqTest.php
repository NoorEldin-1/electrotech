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

    /**
     * Slides 1 & 8: installation is added as a percentage of the subtotal, on
     * top of VAT, exactly like a second tax line.
     */
    public function test_installation_is_added_as_a_percentage_like_vat(): void
    {
        $offer = $this->makeOffer([
            'vat_percentage' => 14, 'show_vat' => true,
            'installation_percentage' => 10, 'show_installation' => true,
        ]);
        $g = $offer->groups()->create(['label' => 'Bi-Metal']);
        $g->items()->create(['description' => 'x', 'quantity' => 1, 'unit_price' => 1000]); // 1000

        app(OfferTotalsService::class)->recalculate($offer);

        $fresh = $offer->fresh();
        $this->assertEquals(1000, (float) $fresh->subtotal);
        $this->assertEquals(140, (float) $fresh->tax_amount);          // 14%
        $this->assertEquals(100, (float) $fresh->installation_amount); // 10%
        $this->assertEquals(1240, (float) $fresh->grand_total);        // 1000 + 140 + 100
        $this->assertEquals(1240, (float) $fresh->financial_amount);   // mirrored
    }

    public function test_installation_is_excluded_when_toggle_is_off(): void
    {
        $offer = $this->makeOffer([
            'vat_percentage' => 14, 'show_vat' => true,
            'installation_percentage' => 10, 'show_installation' => false,
        ]);
        $g = $offer->groups()->create(['label' => 'A']);
        $g->items()->create(['description' => 'x', 'quantity' => 1, 'unit_price' => 1000]);

        app(OfferTotalsService::class)->recalculate($offer);

        $fresh = $offer->fresh();
        $this->assertEquals(0, (float) $fresh->installation_amount);
        $this->assertEquals(1140, (float) $fresh->grand_total); // 1000 + 140, no installation
    }

    /**
     * Slide 5: a follow-up offer (version 2) may carry a different number of
     * tables than the first — a single table is perfectly valid and must not
     * be rejected.
     */
    public function test_a_second_offer_can_carry_a_single_table(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->create());

        // First offer: two tables.
        $first = $project->offers()->create(['financial_amount' => 0]);
        $first->groups()->create(['label' => 'Bi-Metal'])->items()->create(['description' => 'a', 'quantity' => 1, 'unit_price' => 1000]);
        $first->groups()->create(['label' => 'Copper'])->items()->create(['description' => 'b', 'quantity' => 1, 'unit_price' => 2000]);

        // Second offer: a single table.
        $second = $project->offers()->create(['financial_amount' => 0]);
        $second->groups()->create(['label' => 'Bi-Metal'])->items()->create(['description' => 'c', 'quantity' => 1, 'unit_price' => 4000]);
        app(OfferTotalsService::class)->recalculate($second);

        $this->assertSame(2, $second->fresh()->version);
        $this->assertCount(1, $second->fresh()->groups);
        $this->assertEquals(4000, (float) $second->fresh()->subtotal);
    }
}
