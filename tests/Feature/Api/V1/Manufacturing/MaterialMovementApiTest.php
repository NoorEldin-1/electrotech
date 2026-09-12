<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Manufacturing;

use App\Enums\ItemType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\IssueVoucher;
use App\Models\Item;
use App\Models\WorkOrder;
use App\Services\InventoryService;
use App\Services\IssueVoucherService;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * إذن صرف and إذن ارتداد — material leaving the raw store for a work order,
 * and coming back.
 *
 * The centre of gravity here is the EXCESS gate: a voucher may not take an
 * order past its material plan unless somebody with the right permission
 * approves the overage and writes down why.
 */
class MaterialMovementApiTest extends ApiTestCase
{
    // ------------------------------------------------------ issue vouchers

    public function test_a_draft_is_prefilled_with_what_the_order_still_needs(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);

        // 25 already issued and posted.
        $this->postIssue($order, $item, 25);

        $response = $this->actingAsApi($this->userWith(['issue_vouchers.create']))
            ->apiPost(self::BASE.'/issue-vouchers', ['work_order_id' => $order->id]);

        $response->assertCreated();
        $response->assertJsonPath('data.status.value', 'draft');

        // The REMAINING requirement, not the whole recipe again — an order
        // issued in two batches must not re-propose everything.
        $response->assertJsonPath('data.lines.0.quantity', '15.0000');

        // A draft is a document, not a movement.
        $this->assertSame(75.0, $item->fresh()->availableIn(WarehouseType::RawMaterials));
    }

    public function test_opening_a_voucher_is_refused_when_nothing_is_left_to_issue(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $this->postIssue($order, $item, 40);

        $response = $this->actingAsApi($this->userWith(['issue_vouchers.create']))
            ->apiPost(self::BASE.'/issue-vouchers', ['work_order_id' => $order->id]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_posting_moves_stock_into_work_in_progress_and_loads_the_operation(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $voucher = $this->draftIssue($order, $item, 40);

        // The operation may already carry cost from other documents, so the
        // claim is about the DELTA this posting adds, not an absolute.
        $costBefore = (float) $order->project->actual_cost;

        $response = $this->actingAsApi($this->userWith(['issue_vouchers.post']))
            ->apiPost(self::BASE.'/issue-vouchers/'.$voucher->id.'/post');

        $response->assertOk();
        $response->assertJsonPath('data.status.value', 'posted');

        // The total is DERIVED from the lines — no client sends it.
        $response->assertJsonPath('data.total_value', '40000.00');

        $item->refresh();
        $this->assertSame(60.0, $item->availableIn(WarehouseType::RawMaterials));
        $this->assertSame(40.0, $item->availableIn(WarehouseType::WorkInProgress));

        // The value is loaded onto the operation (the cost centre) and accrued
        // onto the order for the estimate-vs-actual comparison.
        $this->assertSame(40000.0, (float) $order->fresh()->actual_material_cost);
        $this->assertSame(40000.0, round((float) $order->project->fresh()->actual_cost - $costBefore, 2));
    }

    public function test_going_over_the_plan_is_refused_with_the_offending_rows(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $voucher = $this->draftIssue($order, $item, 45);

        $response = $this->actingAsApi($this->userWith(['issue_vouchers.post']))
            ->apiPost(self::BASE.'/issue-vouchers/'.$voucher->id.'/post');

        $response->assertStatus(422);

        // Its OWN code, not the generic business_rule_violated: this is the
        // one refusal a client can resolve, and it cannot offer the
        // approve-excess flow if it cannot tell this apart from "already
        // posted".
        $this->assertErrorEnvelope($response, 'issue_excess_requires_approval');

        $response->assertJsonPath('error.details.excess.0.item_id', $item->id);
        $response->assertJsonPath('error.details.excess.0.remaining', 40);
        $response->assertJsonPath('error.details.excess.0.excess', 5);

        // Nothing moved. A refusal that had already shifted stock would be
        // worse than no gate at all.
        $this->assertSame(100.0, $item->fresh()->availableIn(WarehouseType::RawMaterials));
        $this->assertSame(VoucherStatus::Draft, $voucher->fresh()->status);
    }

    public function test_the_excess_preview_answers_before_the_user_commits(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $voucher = $this->draftIssue($order, $item, 45);

        $this->actingAsApi($this->userWith(['issue_vouchers.view']))
            ->apiGet(self::BASE.'/issue-vouchers/'.$voucher->id.'/excess')
            ->assertOk()
            ->assertJsonPath('data.0.this_voucher', '45.0000')
            ->assertJsonPath('data.0.excess', '5.0000');
    }

    public function test_an_approved_excess_posts_and_is_stamped_with_its_reason(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $voucher = $this->draftIssue($order, $item, 45);

        $response = $this->actingAsApi($this->userWith([
            'issue_vouchers.post',
            'issue_vouchers.approve_excess',
        ]))->apiPost(self::BASE.'/issue-vouchers/'.$voucher->id.'/post', [
            'allow_excess' => true,
            'excess_reason' => 'Two busbars damaged during assembly',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status.value', 'posted');
        $response->assertJsonPath('data.excess.has_excess', true);
        $response->assertJsonPath('data.excess.reason', 'Two busbars damaged during assembly');
        $this->assertNotNull($response->json('data.excess.approved_by'));

        $this->assertSame(45.0, $item->fresh()->availableIn(WarehouseType::WorkInProgress));
    }

    public function test_approving_an_excess_needs_its_own_permission(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $voucher = $this->draftIssue($order, $item, 45);

        // Allowed to post, NOT allowed to wave an overage through.
        $response = $this->actingAsApi($this->userWith(['issue_vouchers.post']))
            ->apiPost(self::BASE.'/issue-vouchers/'.$voucher->id.'/post', [
                'allow_excess' => true,
                'excess_reason' => 'Trying it on',
            ]);

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
        $this->assertSame(VoucherStatus::Draft, $voucher->fresh()->status);
    }

    public function test_approving_an_excess_requires_a_written_reason(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $voucher = $this->draftIssue($order, $item, 45);

        $response = $this->actingAsApi($this->userWith([
            'issue_vouchers.post',
            'issue_vouchers.approve_excess',
        ]))->apiPost(self::BASE.'/issue-vouchers/'.$voucher->id.'/post', ['allow_excess' => true]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['excess_reason']]]);
    }

    public function test_posting_is_refused_when_the_store_is_short(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 12);
        $voucher = $this->draftIssue($order, $item, 40);

        $response = $this->actingAsApi($this->userWith(['issue_vouchers.post']))
            ->apiPost(self::BASE.'/issue-vouchers/'.$voucher->id.'/post');

        // A service RuntimeException, rendered as a business rule rather than
        // a 500 — the caller must be able to tell a refusal from an outage
        // (Finding #14).
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertStringContainsString($item->name, $response->json('error.message'));
    }

    public function test_replaying_a_post_does_not_move_stock_twice(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $voucher = $this->draftIssue($order, $item, 40);

        // One token for both calls — the idempotency cache is keyed on the
        // bearer token (Finding #18).
        $request = $this->actingAsApi($this->userWith(['issue_vouchers.post']));
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $request->apiPost(self::BASE.'/issue-vouchers/'.$voucher->id.'/post', [], $key)->assertOk();
        $request->apiPost(self::BASE.'/issue-vouchers/'.$voucher->id.'/post', [], $key)->assertOk();

        $this->assertSame(40.0, $item->fresh()->availableIn(WarehouseType::WorkInProgress));
    }

    public function test_a_posted_voucher_cannot_be_edited_or_deleted(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $voucher = $this->draftIssue($order, $item, 40);
        app(IssueVoucherService::class)->post($voucher);

        $user = $this->userWith(['issue_vouchers.create']);

        // 403, not 422: IssueVoucherPolicy gates update and delete on
        // `! isPosted()`, so the policy answers before any service guard. That
        // is the same order the panel applies, and a client branching on
        // error.code needs to expect `forbidden` here.
        $this->actingAsApi($user)
            ->apiJson('PUT', self::BASE.'/issue-vouchers/'.$voucher->id.'/lines', ['lines' => []])
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiDelete(self::BASE.'/issue-vouchers/'.$voucher->id)
            ->assertStatus(403);
    }

    // ----------------------------------------------------- return vouchers

    public function test_a_return_draft_is_prefilled_at_zero_and_posting_reverses_the_value(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $this->postIssue($order, $item, 40);

        $created = $this->actingAsApi($this->userWith(['return_vouchers.create']))
            ->apiPost(self::BASE.'/return-vouchers', ['work_order_id' => $order->id]);

        $created->assertCreated();

        // Every issued material, each at zero: the warehouse raises the few
        // that came back rather than deleting the many that did not.
        $created->assertJsonPath('data.lines.0.item_id', $item->id);
        $created->assertJsonPath('data.lines.0.quantity', '0.0000');

        $id = $created->json('data.id');

        $this->actingAsApi($this->userWith(['return_vouchers.create']))
            ->apiJson('PUT', self::BASE.'/return-vouchers/'.$id.'/lines', [
                'lines' => [['item_id' => $item->id, 'quantity' => 5, 'unit_cost' => 1000]],
            ])
            ->assertOk();

        $this->actingAsApi($this->userWith(['return_vouchers.post']))
            ->apiPost(self::BASE.'/return-vouchers/'.$id.'/post')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'posted')
            ->assertJsonPath('data.total_value', '5000.00');

        $item->refresh();

        // The exact inverse of the issue: back to raw stock under the SAME
        // item code, out of work-in-progress.
        $this->assertSame(65.0, $item->availableIn(WarehouseType::RawMaterials));
        $this->assertSame(35.0, $item->availableIn(WarehouseType::WorkInProgress));

        // And the value comes off the operation — returned material is not
        // material the operation consumed.
        $this->assertSame(35000.0, (float) $order->fresh()->actual_material_cost);
    }

    public function test_a_return_voucher_of_nothing_but_zeros_is_refused(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $this->postIssue($order, $item, 40);

        $created = $this->actingAsApi($this->userWith(['return_vouchers.create']))
            ->apiPost(self::BASE.'/return-vouchers', ['work_order_id' => $order->id]);

        $response = $this->actingAsApi($this->userWith(['return_vouchers.post']))
            ->apiPost(self::BASE.'/return-vouchers/'.$created->json('data.id').'/post');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_a_returned_quantity_comes_back_into_the_remaining_requirement(): void
    {
        [$order, $item] = $this->plannedOrder(required: 40, stock: 100);
        $this->postIssue($order, $item, 40);

        // Nothing left to issue while everything is out there.
        $this->actingAsApi($this->userWith(['work_orders.view']))
            ->apiGet(self::BASE.'/work-orders/'.$order->id.'/material-requirement')
            ->assertOk()
            ->assertJsonPath('data.0.remaining', '0.0000');

        $this->postReturn($order, $item, 5);

        // Material that came back is not material the order consumed.
        $this->actingAsApi($this->userWith(['work_orders.view']))
            ->apiGet(self::BASE.'/work-orders/'.$order->id.'/material-requirement')
            ->assertOk()
            ->assertJsonPath('data.0.remaining', '5.0000');
    }

    // --------------------------------------------------------------- gates

    public function test_listing_vouchers_needs_the_view_permission(): void
    {
        $response = $this->actingAsApi($this->userWithoutPermissions())
            ->apiGet(self::BASE.'/issue-vouchers');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->apiGet(self::BASE.'/return-vouchers')->assertStatus(401);
    }

    // ------------------------------------------------------------- helpers

    /**
     * A work order planning `$required` of one raw material, with `$stock` of
     * it on hand in the raw store.
     *
     * @return array{0: WorkOrder, 1: Item}
     */
    private function plannedOrder(float $required, float $stock): array
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 1000]);
        $order = WorkOrder::factory()->create();
        $order->materials()->create(['item_id' => $item->id, 'quantity' => $required, 'unit_cost' => 1000]);

        app(InventoryService::class)->addStock($item, $stock, warehouse: WarehouseType::RawMaterials);

        return [$order, $item];
    }

    private function draftIssue(WorkOrder $order, Item $item, float $quantity): IssueVoucher
    {
        $voucher = $order->issueVouchers()->create([
            'voucher_number' => 'IV-TEST-'.Str::random(6),
            'voucher_date' => now(),
            'status' => VoucherStatus::Draft,
        ]);

        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => 1000]);

        return $voucher;
    }

    private function postIssue(WorkOrder $order, Item $item, float $quantity): void
    {
        app(IssueVoucherService::class)->post($this->draftIssue($order, $item, $quantity));
    }

    private function postReturn(WorkOrder $order, Item $item, float $quantity): void
    {
        $voucher = \App\Models\ReturnVoucher::create([
            'voucher_number' => 'RV-TEST-'.Str::random(6),
            'work_order_id' => $order->id,
            'voucher_date' => now(),
            'status' => VoucherStatus::Draft,
        ]);

        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => 1000]);

        app(\App\Services\ReturnVoucherService::class)->post($voucher);
    }
}
