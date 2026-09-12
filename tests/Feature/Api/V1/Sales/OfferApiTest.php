<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Models\OfferGroup;
use App\Models\OfferItem;
use App\Models\Project;
use App\Models\ProjectOffer;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

class OfferApiTest extends ApiTestCase
{
    // ------------------------------------------------------------ happy path

    public function test_it_lists_an_operations_offers_newest_version_first(): void
    {
        $project = Project::factory()->create();
        ProjectOffer::factory()->create(['project_id' => $project->id, 'version' => 1]);
        ProjectOffer::factory()->create(['project_id' => $project->id, 'version' => 2]);

        $response = $this->actingAsApi($this->userWith(['project_offers.view', 'projects.view']))
            ->apiGet(self::BASE.'/projects/'.$project->id.'/offers');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        // The current price is data[0].
        $response->assertJsonPath('data.0.version', 2);
    }

    public function test_it_records_an_offer_with_a_server_assigned_version(): void
    {
        $project = Project::factory()->create();
        ProjectOffer::factory()->create(['project_id' => $project->id, 'version' => 1]);

        $response = $this->actingAsApi($this->userWith(['project_offers.create', 'projects.view']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/offers', [
                'quotation_number' => 'Q-2026-0147',
                'vat_percentage' => 14,
                'show_vat' => true,
            ]);

        $response->assertCreated();
        // Two people quoting the same job in the same minute must not collide
        // on the (project_id, version) unique index.
        $response->assertJsonPath('data.version', 2);
        $response->assertJsonPath('data.quotation_number', 'Q-2026-0147');

        // Created empty: the money arrives with the BOQ.
        $response->assertJsonPath('data.grand_total', '0.00');
    }

    // ------------------------------------------------------------------- BOQ

    public function test_it_replaces_the_boq_and_derives_every_total(): void
    {
        $project = Project::factory()->create();
        $offer = ProjectOffer::factory()->create([
            'project_id' => $project->id,
            'vat_percentage' => 10,
            'show_vat' => true,
            'installation_percentage' => 5,
            'show_installation' => true,
        ]);

        $response = $this->actingAsApi($this->userWith(['project_offers.edit']))
            ->apiJson('PUT', self::BASE.'/offers/'.$offer->id.'/boq', [
                'groups' => [
                    [
                        'label' => 'Copper Offer',
                        'conductor_type' => 'copper',
                        'items' => [
                            ['description' => 'Busbar 2500A', 'unit' => 'm', 'quantity' => 10, 'unit_price' => 100],
                            ['description' => 'Elbow', 'unit' => 'pcs', 'quantity' => 4, 'unit_price' => 50],
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        // The client sent quantities and prices only. Everything below was
        // computed on the server, which is the whole contract of this endpoint.
        $response->assertJsonPath('data.groups.0.items.0.line_total', '1000.00');
        $response->assertJsonPath('data.groups.0.items.1.line_total', '200.00');
        $response->assertJsonPath('data.groups.0.subtotal', '1200.00');
        $response->assertJsonPath('data.subtotal', '1200.00');
        $response->assertJsonPath('data.tax_amount', '120.00');
        $response->assertJsonPath('data.installation_amount', '60.00');
        $response->assertJsonPath('data.grand_total', '1380.00');

        // The headline figure the pipeline lists read is mirrored from the
        // grand total, so the two can never disagree.
        $response->assertJsonPath('data.financial_amount', '1380.00');
    }

    public function test_replacing_the_boq_discards_what_was_there_before(): void
    {
        $offer = ProjectOffer::factory()->create();

        $group = OfferGroup::create([
            'project_offer_id' => $offer->id,
            'label' => 'Old table',
            'subtotal' => 0,
            'sort_order' => 0,
        ]);
        OfferItem::create([
            'offer_group_id' => $group->id,
            'description' => 'Old line',
            'quantity' => 1,
            'unit_price' => 1,
            'sort_order' => 0,
        ]);

        $this->actingAsApi($this->userWith(['project_offers.edit']))
            ->apiJson('PUT', self::BASE.'/offers/'.$offer->id.'/boq', [
                'groups' => [
                    ['label' => 'New table', 'items' => [
                        ['description' => 'New line', 'quantity' => 2, 'unit_price' => 3],
                    ]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.groups.0.label', 'New table')
            ->assertJsonCount(1, 'data.groups');

        // Replace means replace — no orphaned group or line survives.
        $this->assertSame(1, OfferGroup::where('project_offer_id', $offer->id)->count());
        $this->assertSame(0, OfferItem::where('description', 'Old line')->count());
    }

    public function test_an_empty_groups_array_clears_the_boq(): void
    {
        $offer = ProjectOffer::factory()->create();
        OfferGroup::create([
            'project_offer_id' => $offer->id,
            'label' => 'Table',
            'subtotal' => 100,
            'sort_order' => 0,
        ]);

        // `[]` is a meaningful instruction, which is why the rule is `present`
        // and not `required` — `required` rejects an empty array.
        $this->actingAsApi($this->userWith(['project_offers.edit']))
            ->apiJson('PUT', self::BASE.'/offers/'.$offer->id.'/boq', ['groups' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.groups')
            ->assertJsonPath('data.grand_total', '0.00');
    }

    public function test_the_boq_keeps_the_order_it_was_sent_in(): void
    {
        $offer = ProjectOffer::factory()->create();

        $this->actingAsApi($this->userWith(['project_offers.edit']))
            ->apiJson('PUT', self::BASE.'/offers/'.$offer->id.'/boq', [
                'groups' => [
                    ['label' => 'First', 'items' => []],
                    ['label' => 'Second', 'items' => []],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.groups.0.label', 'First')
            ->assertJsonPath('data.groups.0.sort_order', 0)
            ->assertJsonPath('data.groups.1.label', 'Second')
            ->assertJsonPath('data.groups.1.sort_order', 1);
    }

    public function test_changing_the_vat_rate_re_derives_the_totals_in_the_same_response(): void
    {
        $offer = ProjectOffer::factory()->create(['vat_percentage' => 0, 'show_vat' => false]);

        $user = $this->userWith(['project_offers.edit']);

        $this->actingAsApi($user)
            ->apiJson('PUT', self::BASE.'/offers/'.$offer->id.'/boq', [
                'groups' => [['label' => 'T', 'items' => [
                    ['description' => 'L', 'quantity' => 1, 'unit_price' => 1000],
                ]]],
            ])
            ->assertOk()
            ->assertJsonPath('data.grand_total', '1000.00');

        // Without recalculating here the response would carry the old total
        // next to the new rate, which the client would render as-is.
        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/offers/'.$offer->id, [
                'vat_percentage' => 14,
                'show_vat' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.tax_amount', '140.00')
            ->assertJsonPath('data.grand_total', '1140.00');
    }

    // --------------------------------------------------------- business rule

    public function test_a_winning_offer_can_no_longer_be_changed(): void
    {
        $offer = ProjectOffer::factory()->create(['is_winning' => true]);
        $user = $this->userWith(['project_offers.edit', 'project_offers.delete']);

        // It is the price the operation was sold at and what the cost centre
        // is measured against; editing it would rewrite history.
        foreach ([
            fn () => $this->actingAsApi($user)->apiPatch(self::BASE.'/offers/'.$offer->id, ['notes' => 'x']),
            fn () => $this->actingAsApi($user)->apiJson('PUT', self::BASE.'/offers/'.$offer->id.'/boq', ['groups' => []]),
            fn () => $this->actingAsApi($user)->apiDelete(self::BASE.'/offers/'.$offer->id),
        ] as $call) {
            $response = $call();
            $response->assertStatus(422);
            $this->assertErrorEnvelope($response, 'business_rule_violated');
        }

        $this->assertDatabaseHas('project_offers', ['id' => $offer->id, 'deleted_at' => null]);
    }

    // ----------------------------------------------------------- validation

    public function test_a_boq_line_needs_a_description_a_quantity_and_a_price(): void
    {
        $offer = ProjectOffer::factory()->create();

        $response = $this->actingAsApi($this->userWith(['project_offers.edit']))
            ->apiJson('PUT', self::BASE.'/offers/'.$offer->id.'/boq', [
                'groups' => [['label' => 'T', 'items' => [['unit' => 'm']]]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'error' => ['details' => [
                'groups.0.items.0.description',
                'groups.0.items.0.quantity',
                'groups.0.items.0.unit_price',
            ]],
        ]);
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $offer = ProjectOffer::factory()->create();

        $this->actingAsApi($this->userWith(['project_offers.edit']))
            ->apiJson('PUT', self::BASE.'/offers/'.$offer->id.'/boq', [
                'groups' => [['label' => 'T', 'items' => [
                    ['description' => 'L', 'quantity' => 1, 'unit_price' => -5],
                ]]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['groups.0.items.0.unit_price']]]);
    }

    // ------------------------------------------------------------------ RBAC

    public function test_offer_endpoints_are_permission_gated(): void
    {
        $project = Project::factory()->create();
        $offer = ProjectOffer::factory()->create(['project_id' => $project->id]);
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/offers/'.$offer->id)->assertForbidden();
        $this->actingAsApi($user)->apiPost(self::BASE.'/projects/'.$project->id.'/offers', [])->assertForbidden();
        $this->actingAsApi($user)->apiPatch(self::BASE.'/offers/'.$offer->id, [])->assertForbidden();
        $this->actingAsApi($user)->apiJson('PUT', self::BASE.'/offers/'.$offer->id.'/boq', ['groups' => []])->assertForbidden();
        $this->actingAsApi($user)->apiDelete(self::BASE.'/offers/'.$offer->id)->assertForbidden();
    }

    // ---------------------------------------------------------- idempotency

    public function test_a_replayed_offer_create_does_not_write_twice(): void
    {
        $project = Project::factory()->create();
        $key = (string) Str::uuid();

        $this->actingAsApi($this->userWith(['project_offers.create', 'projects.view']));

        $first = $this->apiPost(
            self::BASE.'/projects/'.$project->id.'/offers',
            ['quotation_number' => 'Q-1'],
            ['Idempotency-Key' => $key],
        );
        $first->assertCreated();

        $second = $this->apiPost(
            self::BASE.'/projects/'.$project->id.'/offers',
            ['quotation_number' => 'Q-1'],
            ['Idempotency-Key' => $key],
        );
        $second->assertCreated();

        // Without replay protection a retried submit on a weak link would
        // leave the operation carrying two identical quotations, and the
        // second would silently become the "current" version.
        $this->assertSame(1, ProjectOffer::where('project_id', $project->id)->count());
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_it_requires_a_token(): void
    {
        $offer = ProjectOffer::factory()->create();

        $this->apiGet(self::BASE.'/offers/'.$offer->id)->assertUnauthorized();
    }
}
