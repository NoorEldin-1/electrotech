<?php

namespace Tests\Feature\Sales;

use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use App\Services\OfferTotalsService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slide 7: the offer can be printed from the system. The PDF is streamed
 * through a permission-checked route (project_offers.print), not the raw
 * /storage path.
 */
class OfferPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function makeOffer(): ProjectOffer
    {
        $offer = ProjectOffer::factory()
            ->for(Project::factory()->create())
            ->create(['quotation_number' => 'Q-1/2026']);

        $group = $offer->groups()->create(['label' => 'Bi-Metal Offer']);
        $group->items()->create(['description' => 'Busway 4000A', 'unit' => 'MT', 'quantity' => 5, 'unit_price' => 1000]);

        return $offer;
    }

    public function test_user_with_print_permission_gets_a_pdf(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');

        $response = $this->actingAs($user)->get(route('offers.pdf', $this->makeOffer()));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_user_without_print_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Warehouse_Manager');

        $this->actingAs($user)
            ->get(route('offers.pdf', $this->makeOffer()))
            ->assertForbidden();
    }

    /**
     * Slide 7: the printed offer is addressed to the company with an explicit
     * "Attn. Eng." line. Slide 3: the terms keep the line-by-line layout the
     * author typed. Asserted on the rendered HTML (the PDF binary can't be
     * grepped reliably).
     */
    public function test_pdf_html_addresses_company_and_engineer_and_keeps_term_lines(): void
    {
        app()->setLocale('ar');

        $project = Project::factory()->create([
            'client_name' => 'الشركة المصرية للاستثمار',
            'engineer_name' => 'محمد السيد',
        ]);
        $offer = ProjectOffer::factory()->for($project)->create([
            'terms' => "البند الأول\nالبند الثاني",
        ]);
        $offer->groups()->create(['label' => 'Bi-Metal Offer']);
        $offer->load(['project.customer', 'groups.items', 'submittedBy']);

        $html = view('pdf.offer', ['offer' => $offer, 'logo' => null])->render();

        $this->assertStringContainsString('الشركة المصرية للاستثمار', $html);
        $this->assertStringContainsString('عناية السيد المهندس', $html);
        $this->assertStringContainsString('محمد السيد', $html);
        $this->assertStringContainsString('تحية طيبة وبعد', $html);
        // Slide 3: an explicit <br> between the two typed lines.
        $this->assertStringContainsString('البند الأول<br />', $html);
    }

    /**
     * Slides 5 & 11: the header note (intro + DKC licence statement) is printed
     * before the first table.
     */
    public function test_pdf_html_prints_header_note_before_the_tables(): void
    {
        $offer = $this->makeOffer();
        $offer->update(['header_note' => 'The busway system is manufactured under license from DKC – Italy.']);
        $offer->load(['project.customer', 'groups.items', 'submittedBy']);

        $html = view('pdf.offer', ['offer' => $offer, 'logo' => null])->render();

        $notePos = strpos($html, 'manufactured under license from DKC');
        $tablePos = strpos($html, 'Bi-Metal Offer');

        $this->assertNotFalse($notePos);
        $this->assertNotFalse($tablePos);
        $this->assertLessThan($tablePos, $notePos, 'Header note must appear before the table.');
    }

    /**
     * Slides 4 & 10: a multi-table offer prints exactly one authoritative
     * "Offer Total" block, and that grand total equals the stored figure (and
     * therefore the form preview and the Tender column). No conflicting totals.
     */
    public function test_pdf_prints_one_combined_total_matching_the_stored_grand_total(): void
    {
        $this->actingAs(User::factory()->create());

        $offer = ProjectOffer::factory()->for(Project::factory()->create())->create([
            'vat_percentage' => 14, 'show_vat' => true, 'show_installation' => false,
        ]);
        $offer->groups()->create(['label' => 'Plot 14'])->items()->create(['description' => 'a', 'quantity' => 1, 'unit_price' => 2000]);
        $offer->groups()->create(['label' => 'Plot 22'])->items()->create(['description' => 'b', 'quantity' => 1, 'unit_price' => 3000]);
        app(OfferTotalsService::class)->recalculate($offer);

        $offer->load(['project.customer', 'groups.items', 'submittedBy']);
        $html = view('pdf.offer', ['offer' => $offer, 'logo' => null])->render();

        // (2000 + 3000) + 14% = 5700.00, computed once at offer level.
        $this->assertEquals(5700, (float) $offer->fresh()->grand_total);
        $this->assertStringContainsString(__('resources.project_offers.pdf.offer_total_title'), $html);
        $this->assertStringContainsString(number_format((float) $offer->grand_total, 2), $html);
        // The combined block is the only place a grand total is printed.
        $this->assertSame(1, substr_count($html, 'class="grand"'));
    }

    /**
     * Slides 3, 8 & 9: special terms print directly behind the tables, then the
     * general terms print after them as a numbered list.
     */
    public function test_pdf_html_splits_special_terms_then_numbered_general_terms(): void
    {
        app()->setLocale('en');

        $offer = $this->makeOffer();
        $offer->update([
            'terms' => 'If installation is requested, prices rise by 15%.',
            'general_terms' => "Offer validity: one week.\nPrices exclude VAT.",
        ]);
        $offer->load(['project.customer', 'groups.items', 'submittedBy']);

        $html = view('pdf.offer', ['offer' => $offer, 'logo' => null])->render();

        $specialPos = strpos($html, '<h4>'.__('resources.project_offers.pdf.special_terms_title').'</h4>');
        $generalPos = strpos($html, '<h4>'.__('resources.project_offers.pdf.terms_title').'</h4>');

        $this->assertNotFalse($specialPos);
        $this->assertNotFalse($generalPos);
        $this->assertLessThan($generalPos, $specialPos, 'Special terms must print before general terms.');
        // Two non-empty general-term lines render as two numbered items.
        $this->assertSame(2, substr_count($html, '<li>'));
    }

    /**
     * Slide 9: the same offer can be printed in English or Arabic via ?lang,
     * independent of the UI language.
     */
    public function test_offer_can_be_printed_in_both_languages(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');
        $offer = $this->makeOffer();

        foreach (['en', 'ar'] as $lang) {
            $response = $this->actingAs($user)->get(route('offers.pdf', ['offer' => $offer, 'lang' => $lang]));

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'));
        }
    }
}
