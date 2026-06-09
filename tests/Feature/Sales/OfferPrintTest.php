<?php

namespace Tests\Feature\Sales;

use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
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
}
