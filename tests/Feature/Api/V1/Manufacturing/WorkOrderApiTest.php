<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Manufacturing;

use App\Enums\ItemType;
use App\Enums\WarehouseType;
use App\Enums\WorkOrderStatus;
use App\Models\Bom;
use App\Models\Item;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * أمر التصنيع — the order itself: authoring it, planning it, and walking it
 * through the gate chain that ends in produced stock.
 */
class WorkOrderApiTest extends ApiTestCase
{
    // ------------------------------------------------------------ authoring

    public function test_it_creates_a_draft_order_with_a_generated_number(): void
    {
        $project = Project::factory()->create();
        $product = Item::factory()->create(['type' => ItemType::FinishedGood]);

        $response = $this->actingAsApi($this->userWith(['work_orders.create']))
            ->apiPost(self::BASE.'/work-orders', [
                'project_id' => $project->id,
                'title' => 'Main distribution panel',
                'priority' => 'high',
                'planned_start_date' => '2026-09-15',
                'planned_end_date' => '2026-09-30',
                'specs' => ['protection_degree' => 'IP54', 'poles_count' => 4],
                'outputs' => [
                    ['item_id' => $product->id, 'planned_quantity' => 10],
                ],
            ]);

        $response->assertCreated();
        $this->assertItemEnvelope($response);

        // Always a Draft, whatever the payload says — the order is released by
        // its own endpoint, never by a field.
        $response->assertJsonPath('data.status.value', 'draft');
        $response->assertJsonPath('data.specs.protection_degree', 'IP54');

        // The number is the server's to mint. Finding #16: this path was
        // untestable until generateWoNumber() stopped using SUBSTRING_INDEX.
        $this->assertMatchesRegularExpression('/^WO-\d{6}-\d{4}$/', $response->json('data.wo_number'));

        // planned_quantity is DERIVED from the product lines, so the two can
        // never disagree.
        $this->assertSame('10.0000', $response->json('data.quantities.planned'));
        $this->assertSame($product->id, $response->json('data.output_item_id'));
    }

    public function test_the_status_cannot_be_moved_by_patching_it(): void
    {
        $order = WorkOrder::factory()->draft()->create();

        $this->actingAsApi($this->userWith(['work_orders.edit']))
            ->apiPatch(self::BASE.'/work-orders/'.$order->id, [
                'status' => WorkOrderStatus::Completed->value,
                'produced_quantity' => 999,
                'title' => 'Renamed',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');

        $order->refresh();

        // The rename landed; the state machine and the declared output did not.
        $this->assertSame(WorkOrderStatus::Draft, $order->status);
        $this->assertSame(0.0, (float) $order->produced_quantity);
    }

    public function test_creating_requires_a_title(): void
    {
        $response = $this->actingAsApi($this->userWith(['work_orders.create']))
            ->apiPost(self::BASE.'/work-orders', ['priority' => 'high']);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['title']]]);
    }

    public function test_only_a_draft_or_cancelled_order_can_be_deleted(): void
    {
        $released = WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress]);

        $response = $this->actingAsApi($this->userWith(['work_orders.delete']))
            ->apiDelete(self::BASE.'/work-orders/'.$released->id);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');

        $draft = WorkOrder::factory()->draft()->create();

        $this->actingAsApi($this->userWith(['work_orders.delete']))
            ->apiDelete(self::BASE.'/work-orders/'.$draft->id)
            ->assertNoContent();
    }

    // ------------------------------------------------------------- planning

    public function test_it_replaces_the_material_plan_wholesale(): void
    {
        $order = WorkOrder::factory()->draft()->create();
        $first = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 250]);
        $second = Item::factory()->create(['type' => ItemType::RawMaterial]);

        WorkOrderMaterial::factory()->create(['work_order_id' => $order->id]);

        $response = $this->actingAsApi($this->userWith(['work_orders.edit']))
            ->apiJson('PUT', self::BASE.'/work-orders/'.$order->id.'/materials', [
                'materials' => [
                    ['item_id' => $first->id, 'quantity' => 40, 'is_manual' => true],
                    ['item_id' => $second->id, 'quantity' => 5, 'unit_cost' => 1000],
                ],
            ]);

        $response->assertOk();

        // Replaced, not merged: the pre-existing line is gone.
        $this->assertSame(2, $order->materials()->count());

        $lines = collect($response->json('data.materials'))->keyBy('item_id');

        // unit_cost falls back to the item card when the client omits it.
        $this->assertSame('250.00', $lines[$first->id]['unit_cost']);
        $this->assertTrue($lines[$first->id]['is_manual']);
        $this->assertSame('1000.00', $lines[$second->id]['unit_cost']);

        // Money and quantities leave as strings, at the precision of their
        // columns — decimal(*,2) for money, decimal(*,4) for quantity.
        $this->assertSame('40.0000', $lines[$first->id]['quantity']);
        $this->assertSame('10000.00', $lines[$first->id]['line_value']);
    }

    public function test_an_empty_material_list_is_a_legal_way_to_clear_the_plan(): void
    {
        $order = WorkOrder::factory()->draft()->create();
        WorkOrderMaterial::factory()->count(3)->create(['work_order_id' => $order->id]);

        $this->actingAsApi($this->userWith(['work_orders.edit']))
            ->apiJson('PUT', self::BASE.'/work-orders/'.$order->id.'/materials', ['materials' => []])
            ->assertOk();

        $this->assertSame(0, $order->materials()->count());
    }

    public function test_replacing_the_products_re_derives_the_order_plan(): void
    {
        $order = WorkOrder::factory()->draft()->create(['planned_quantity' => 1]);
        $a = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $b = Item::factory()->create(['type' => ItemType::FinishedGood]);

        $response = $this->actingAsApi($this->userWith(['work_orders.edit']))
            ->apiJson('PUT', self::BASE.'/work-orders/'.$order->id.'/outputs', [
                'outputs' => [
                    ['item_id' => $a->id, 'planned_quantity' => 6],
                    ['item_id' => $b->id, 'planned_quantity' => 4],
                ],
            ]);

        $response->assertOk();

        // The order-level quantity is a SUMMARY of the lines. A summary that
        // can disagree with what it summarizes is worse than none.
        $response->assertJsonPath('data.quantities.planned', '10.0000');
        $response->assertJsonPath('data.output_item_id', $a->id);
    }

    // --------------------------------------------------------- the gate chain

    public function test_approval_refuses_an_incomplete_plan_and_names_what_is_missing(): void
    {
        $order = WorkOrder::factory()->draft()->create([
            'planned_quantity' => 0,
            'planned_start_date' => null,
            'planned_end_date' => null,
        ]);

        $response = $this->actingAsApi($this->userWith(['work_orders.approve_order']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/approve-order');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');

        // The message names every missing field at once rather than making the
        // user discover them one refusal at a time.
        $this->assertStringContainsString($order->wo_number, $response->json('error.message'));

        $this->assertSame(WorkOrderStatus::Draft, $order->fresh()->status);
    }

    public function test_it_walks_the_full_chain_to_completion(): void
    {
        $product = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $order = WorkOrder::factory()->draft()->create([
            'planned_quantity' => 10,
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addWeek()->toDateString(),
        ]);
        $output = $order->outputs()->create(['item_id' => $product->id, 'planned_quantity' => 10]);

        $this->actingAsApi($this->userWith(['work_orders.approve_order']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/approve-order')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'pending')
            ->assertJsonPath('data.approvals.order_approved', true);

        $this->actingAsApi($this->userWith(['work_orders.start']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/start')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'in_progress');

        // submit-qa is the ONLY place produced/waste are written.
        $this->actingAsApi($this->userWith(['work_orders.submit_qa']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/submit-qa', [
                'results' => [
                    ['output_id' => $output->id, 'produced_quantity' => 9, 'waste_quantity' => 1],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'qa_review')
            ->assertJsonPath('data.quantities.produced', '9.0000')
            ->assertJsonPath('data.quantities.waste', '1.0000');

        $this->actingAsApi($this->userWith(['work_orders.approve_qa']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/approve-qa', ['qa_notes' => 'Passed'])
            ->assertOk()
            ->assertJsonPath('data.approvals.qa_approved', true);

        $this->actingAsApi($this->userWith(['work_orders.complete']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'completed');

        // Completing is the accounting moment: the product exists in finished
        // goods and the loss is on the record.
        $this->assertSame(9.0, $product->fresh()->availableIn(WarehouseType::FinishedGoods));
        $this->assertSame(1, $order->productionEntries()->count());
    }

    public function test_finishing_manufacturing_needs_both_approvals(): void
    {
        $order = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::InProgress,
            'actual_start_date' => now()->subHours(8),
            'order_approved_by' => $this->admin()->id,
            'order_approved_at' => now(),
            // QA sign-off deliberately absent.
        ]);

        $response = $this->actingAsApi($this->userWith(['work_orders.finish_manufacturing']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/finish-manufacturing');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertNull($order->fresh()->manufacturing_finished_at);
    }

    public function test_finishing_manufacturing_opens_exactly_one_quality_sheet(): void
    {
        $order = WorkOrder::factory()->approved()->create([
            'status' => WorkOrderStatus::InProgress,
            'actual_start_date' => now()->subHours(8),
        ]);

        $response = $this->actingAsApi($this->userWith(['work_orders.finish_manufacturing']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/finish-manufacturing');

        $response->assertOk();
        $response->assertJsonPath('data.approvals.manufacturing_finished', true);

        // The duration is measured, not typed.
        $this->assertGreaterThan(0, $response->json('data.schedule.manufacturing_duration_minutes'));

        $this->assertSame(1, $order->qualitySheets()->count());

        // Retrying is a silent success and never opens a second sheet.
        $this->actingAsApi($this->userWith(['work_orders.finish_manufacturing']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/finish-manufacturing')
            ->assertOk();

        $this->assertSame(1, $order->qualitySheets()->count());
    }

    public function test_completion_requires_the_qa_gate(): void
    {
        $order = WorkOrder::factory()->create(['status' => WorkOrderStatus::QaReview]);

        $response = $this->actingAsApi($this->userWith(['work_orders.complete']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/complete');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertSame(WorkOrderStatus::QaReview, $order->fresh()->status);
    }

    // ------------------------------------------------------------- listing

    public function test_it_filters_sorts_and_paginates(): void
    {
        WorkOrder::factory()->count(3)->create(['status' => WorkOrderStatus::InProgress]);
        WorkOrder::factory()->count(2)->draft()->create();

        $user = $this->userWith(['work_orders.view']);

        $response = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/work-orders?filter[status]=in_progress&per_page=2&sort=-wo_number');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $response->assertJsonPath('meta.pagination.total', 3);
        $response->assertJsonPath('meta.pagination.per_page', 2);

        // An unknown filter is a 422 that names the allowed keys, never a
        // silent no-op — that is how a client ships a filter that never worked.
        $unknown = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/work-orders?filter[nonsense]=1');

        $unknown->assertStatus(422);
        $this->assertErrorEnvelope($unknown, 'validation_failed');
    }

    public function test_the_index_does_not_grow_its_query_count_with_its_row_count(): void
    {
        $user = $this->userWith(['work_orders.view']);

        // Warm-up: the first request of a test also builds Spatie's permission
        // cache, which would otherwise make the SMALLER page look dearer.
        $this->actingAsApi($user)->apiGet(self::BASE.'/work-orders');

        $count = function (int $rows) use ($user): int {
            WorkOrder::query()->forceDelete();
            WorkOrder::factory()->count($rows)->create();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAsApi($user)->apiGet(self::BASE.'/work-orders')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // Three rows against nine. Comparing the two is what an N+1 actually
        // IS; a fixed ceiling is a magic number that drifts whenever the auth
        // path changes.
        $this->assertSame($count(3), $count(9));
    }

    // ---------------------------------------------------------- requirement

    public function test_the_material_requirement_nets_off_what_was_already_issued(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 1000]);
        $order = WorkOrder::factory()->create();
        $order->materials()->create(['item_id' => $item->id, 'quantity' => 40, 'unit_cost' => 1000]);

        app(InventoryService::class)->addStock($item, 100, warehouse: WarehouseType::RawMaterials);

        // Issue 25 of the 40 and post it.
        $voucher = $order->issueVouchers()->create([
            'voucher_number' => 'IV-TEST-0001',
            'voucher_date' => now(),
            'status' => \App\Enums\VoucherStatus::Draft,
        ]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => 25, 'unit_cost' => 1000]);
        app(\App\Services\IssueVoucherService::class)->post($voucher);

        $response = $this->actingAsApi($this->userWith(['work_orders.view']))
            ->apiGet(self::BASE.'/work-orders/'.$order->id.'/material-requirement');

        $response->assertOk();
        $response->assertJsonPath('data.0.required', '40.0000');
        $response->assertJsonPath('data.0.previously_issued', '25.0000');
        $response->assertJsonPath('data.0.remaining', '15.0000');
    }

    // ---------------------------------------------------------------- gates

    public function test_each_transition_carries_its_own_permission(): void
    {
        $order = WorkOrder::factory()->draft()->create();

        // work_orders.edit is deliberately NOT enough to release an order for
        // manufacturing.
        $response = $this->actingAsApi($this->userWith(['work_orders.edit', 'work_orders.view']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/approve-order');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_a_token_without_the_manufacturing_ability_is_refused(): void
    {
        $order = WorkOrder::factory()->create();

        $response = $this->actingAsApi($this->admin(), ['inventory'])
            ->apiGet(self::BASE.'/work-orders/'.$order->id);

        $response->assertStatus(403);

        // A narrow token and a missing permission are different failures: the
        // first is fixed by signing in again, the second needs an administrator.
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $response = $this->apiGet(self::BASE.'/work-orders');

        $response->assertStatus(401);
        $this->assertErrorEnvelope($response, 'unauthenticated');
    }

    public function test_creating_an_order_twice_with_one_idempotency_key_writes_once(): void
    {
        // One token for both calls: the idempotency cache is keyed on the
        // bearer token, so a second actingAsApi() would put the replay in a
        // different scope and the test would assert nothing (Finding #18).
        $request = $this->actingAsApi($this->userWith(['work_orders.create']));

        $payload = [
            'project_id' => Project::factory()->create()->id,
            'title' => 'Replayed order',
        ];
        $key = ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()];

        $first = $request->apiPost(self::BASE.'/work-orders', $payload, $key);
        $second = $request->apiPost(self::BASE.'/work-orders', $payload, $key);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, WorkOrder::where('title', 'Replayed order')->count());
    }

    public function test_standard_materials_refuse_a_product_with_no_approved_recipe(): void
    {
        $product = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $order = WorkOrder::factory()->draft()->create(['bom_id' => Bom::factory()]);
        $order->outputs()->create(['item_id' => $product->id, 'planned_quantity' => 5]);

        $response = $this->actingAsApi($this->userWith(['work_orders.edit']))
            ->apiPost(self::BASE.'/work-orders/'.$order->id.'/fetch-standard-materials');

        // Named, not silent: a short plan would under-issue the order.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertStringContainsString($product->name, $response->json('error.message'));
    }
}
