<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\TechnicalOffice;

use App\Enums\BomStatus;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Item;
use App\Models\Project;
use Tests\Feature\Api\V1\ApiTestCase;

class BomApiTest extends ApiTestCase
{
    // ------------------------------------------------------------ happy path

    public function test_it_lists_boms(): void
    {
        Bom::factory()->count(2)->create();

        $response = $this->actingAsApi($this->userWith(['boms.view']))
            ->apiGet(self::BASE.'/boms');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $response->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_it_distinguishes_project_boms_from_standard_recipes(): void
    {
        $project = Project::factory()->create();
        $product = Item::factory()->create();

        Bom::factory()->create(['project_id' => $project->id, 'output_item_id' => null]);
        Bom::factory()->create(['project_id' => null, 'output_item_id' => $product->id]);

        $user = $this->userWith(['boms.view']);

        // `scope` is published as a field and accepted as a filter, so the
        // client never has to know it is really a null check on output_item_id.
        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/boms?filter[scope]=standard')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.scope', 'standard');

        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/boms?filter[scope]=project')
            ->assertOk()
            ->assertJsonPath('data.0.scope', 'project');
    }

    public function test_it_creates_a_draft_bom(): void
    {
        $project = Project::factory()->create();

        $response = $this->actingAsApi($this->userWith(['boms.create']))
            ->apiPost(self::BASE.'/boms', [
                'project_id' => $project->id,
                'notes' => 'First pass',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status.value', 'draft');
        $response->assertJsonPath('data.scope', 'project');
        $response->assertJsonPath('data.version', 1);
    }

    public function test_it_replaces_the_material_lines_and_derives_the_waste_adjusted_quantity(): void
    {
        $bom = Bom::factory()->create(['status' => BomStatus::Draft]);
        $item = Item::factory()->create(['unit_cost' => 100]);

        $response = $this->actingAsApi($this->userWith(['boms.edit']))
            ->apiJson('PUT', self::BASE.'/boms/'.$bom->id.'/items', [
                'items' => [
                    ['item_id' => $item->id, 'quantity' => 120, 'waste_percentage' => 5],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.items.0.quantity', '120.0000');

        // The reservation and the work-order material plan both consume the
        // waste-adjusted figure, so the client must be shown it rather than
        // deriving it itself.
        $response->assertJsonPath('data.items.0.total_required_quantity', '126.0000');

        // Σ(quantity × the item's current unit cost) — the net quantity, which
        // is what BOM::getTotalCostAttribute() sums.
        $response->assertJsonPath('data.total_cost', '12000.00');
    }

    public function test_replacing_lines_discards_the_previous_ones(): void
    {
        $bom = Bom::factory()->create(['status' => BomStatus::Draft]);
        $old = Item::factory()->create();
        $new = Item::factory()->create();

        BomItem::create(['bom_id' => $bom->id, 'item_id' => $old->id, 'quantity' => 1, 'waste_percentage' => 0]);

        $this->actingAsApi($this->userWith(['boms.edit']))
            ->apiJson('PUT', self::BASE.'/boms/'.$bom->id.'/items', [
                'items' => [['item_id' => $new->id, 'quantity' => 2]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.item_id', $new->id);
    }

    // ------------------------------------------------------- state machine

    public function test_it_walks_a_bom_from_draft_to_approved(): void
    {
        $bom = Bom::factory()->create(['status' => BomStatus::Draft]);
        $item = Item::factory()->create();
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $item->id, 'quantity' => 1, 'waste_percentage' => 0]);

        $user = $this->userWith(['boms.edit', 'boms.approve', 'boms.view']);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/boms/'.$bom->id.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'pending_approval');

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/boms/'.$bom->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'approved');

        $this->assertNotNull($bom->fresh()->approved_at);
        $this->assertSame($user->id, $bom->fresh()->approved_by);
    }

    public function test_a_bom_with_no_lines_cannot_be_submitted(): void
    {
        $bom = Bom::factory()->create(['status' => BomStatus::Draft]);

        $response = $this->actingAsApi($this->userWith(['boms.edit']))
            ->apiPost(self::BASE.'/boms/'.$bom->id.'/submit');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertSame(BomStatus::Draft, $bom->fresh()->status);
    }

    public function test_a_draft_cannot_be_approved_without_being_submitted(): void
    {
        $bom = Bom::factory()->create(['status' => BomStatus::Draft]);
        $item = Item::factory()->create();
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $item->id, 'quantity' => 1, 'waste_percentage' => 0]);

        $response = $this->actingAsApi($this->userWith(['boms.approve']))
            ->apiPost(self::BASE.'/boms/'.$bom->id.'/approve');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_approving_supersedes_the_previously_approved_version(): void
    {
        $product = Item::factory()->create();
        $material = Item::factory()->create();

        $old = Bom::factory()->create([
            'project_id' => null,
            'output_item_id' => $product->id,
            'version' => 1,
            'status' => BomStatus::Approved,
        ]);

        $new = Bom::factory()->create([
            'project_id' => null,
            'output_item_id' => $product->id,
            'version' => 2,
            'status' => BomStatus::PendingApproval,
        ]);
        BomItem::create(['bom_id' => $new->id, 'item_id' => $material->id, 'quantity' => 1, 'waste_percentage' => 0]);

        $this->actingAsApi($this->userWith(['boms.approve']))
            ->apiPost(self::BASE.'/boms/'.$new->id.'/approve')
            ->assertOk();

        // Two approved recipes for one product would make which one a work
        // order used depend on an ordering tiebreak rather than a decision.
        $this->assertSame(BomStatus::Superseded, $old->fresh()->status);
        $this->assertSame(BomStatus::Approved, $new->fresh()->status);
    }

    public function test_a_project_bom_does_not_supersede_a_standard_one(): void
    {
        $project = Project::factory()->create();
        $product = Item::factory()->create();
        $material = Item::factory()->create();

        $standard = Bom::factory()->create([
            'project_id' => null,
            'output_item_id' => $product->id,
            'status' => BomStatus::Approved,
        ]);

        $projectBom = Bom::factory()->create([
            'project_id' => $project->id,
            'output_item_id' => null,
            'status' => BomStatus::PendingApproval,
        ]);
        BomItem::create(['bom_id' => $projectBom->id, 'item_id' => $material->id, 'quantity' => 1, 'waste_percentage' => 0]);

        $this->actingAsApi($this->userWith(['boms.approve']))
            ->apiPost(self::BASE.'/boms/'.$projectBom->id.'/approve')
            ->assertOk();

        // Different subjects entirely — retiring the product recipe here would
        // silently break every future work order for that product.
        $this->assertSame(BomStatus::Approved, $standard->fresh()->status);
    }

    public function test_an_approved_bom_is_immutable(): void
    {
        $bom = Bom::factory()->create(['status' => BomStatus::Approved]);
        $item = Item::factory()->create();
        $user = $this->userWith(['boms.edit', 'boms.delete']);

        foreach ([
            fn () => $this->actingAsApi($user)->apiPatch(self::BASE.'/boms/'.$bom->id, ['notes' => 'x']),
            fn () => $this->actingAsApi($user)->apiJson('PUT', self::BASE.'/boms/'.$bom->id.'/items', [
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ]),
            fn () => $this->actingAsApi($user)->apiDelete(self::BASE.'/boms/'.$bom->id),
        ] as $call) {
            $response = $call();
            $response->assertStatus(422);
            $this->assertErrorEnvelope($response, 'business_rule_violated');
        }
    }

    // ------------------------------------------------------ standard recipe

    public function test_it_returns_the_current_standard_recipe_for_a_product(): void
    {
        $product = Item::factory()->create();

        Bom::factory()->create([
            'project_id' => null, 'output_item_id' => $product->id,
            'version' => 1, 'status' => BomStatus::Approved,
        ]);
        $current = Bom::factory()->create([
            'project_id' => null, 'output_item_id' => $product->id,
            'version' => 3, 'status' => BomStatus::Approved,
        ]);
        // A newer draft must not win — only approved recipes count.
        Bom::factory()->create([
            'project_id' => null, 'output_item_id' => $product->id,
            'version' => 4, 'status' => BomStatus::Draft,
        ]);

        $this->actingAsApi($this->userWith(['boms.view']))
            ->apiGet(self::BASE.'/items/'.$product->id.'/standard-bom')
            ->assertOk()
            ->assertJsonPath('data.id', $current->id)
            ->assertJsonPath('data.version', 3);
    }

    public function test_a_product_with_no_approved_recipe_is_a_404(): void
    {
        $product = Item::factory()->create();

        $response = $this->actingAsApi($this->userWith(['boms.view']))
            ->apiGet(self::BASE.'/items/'.$product->id.'/standard-bom');

        // A meaningful answer, not an error: it is exactly the check a client
        // makes before offering "build this".
        $response->assertNotFound();
        $this->assertErrorEnvelope($response, 'not_found');
    }

    // ----------------------------------------------------------- validation

    public function test_a_bom_must_name_exactly_one_subject(): void
    {
        $project = Project::factory()->create();
        $product = Item::factory()->create();
        $user = $this->userWith(['boms.create']);

        // Neither.
        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/boms', [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['project_id']]]);

        // Both — it would be superseded against the wrong sibling on approval.
        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/boms', [
                'project_id' => $project->id,
                'output_item_id' => $product->id,
            ])
            ->assertStatus(422);
    }

    public function test_a_zero_quantity_line_is_rejected(): void
    {
        $bom = Bom::factory()->create(['status' => BomStatus::Draft]);
        $item = Item::factory()->create();

        $this->actingAsApi($this->userWith(['boms.edit']))
            ->apiJson('PUT', self::BASE.'/boms/'.$bom->id.'/items', [
                'items' => [['item_id' => $item->id, 'quantity' => 0]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['items.0.quantity']]]);
    }

    // ------------------------------------------------------------------ RBAC

    public function test_approving_needs_more_than_permission_to_edit(): void
    {
        $bom = Bom::factory()->create(['status' => BomStatus::PendingApproval]);

        // Whoever drafts a BOM is not thereby entitled to commit the company
        // to buying it.
        $response = $this->actingAsApi($this->userWith(['boms.view', 'boms.edit']))
            ->apiPost(self::BASE.'/boms/'.$bom->id.'/approve');

        $response->assertForbidden();
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_bom_endpoints_are_permission_gated(): void
    {
        $bom = Bom::factory()->create();
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/boms')->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/boms/'.$bom->id)->assertForbidden();
        $this->actingAsApi($user)->apiPost(self::BASE.'/boms', [])->assertForbidden();
        $this->actingAsApi($user)->apiPatch(self::BASE.'/boms/'.$bom->id, [])->assertForbidden();
        $this->actingAsApi($user)->apiJson('PUT', self::BASE.'/boms/'.$bom->id.'/items', ['items' => []])->assertForbidden();
        $this->actingAsApi($user)->apiDelete(self::BASE.'/boms/'.$bom->id)->assertForbidden();
    }

    public function test_a_token_without_the_technical_office_ability_is_refused(): void
    {
        $response = $this->actingAsApi($this->userWith(['boms.view']), ['sales'])
            ->apiGet(self::BASE.'/boms');

        $response->assertForbidden();
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');
    }

    public function test_it_requires_a_token(): void
    {
        $this->apiGet(self::BASE.'/boms')->assertUnauthorized();
    }
}
