<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Inventory;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\ItemType;
use App\Enums\LossType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\Account;
use App\Models\AdditionVoucher;
use App\Models\DepreciationVoucher;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\WorkOrder;
use App\Services\InventoryService;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Addition vouchers (goods receipt) and depreciation vouchers (loss
 * write-off): the two documents that move stock without a work order behind
 * them.
 */
class VoucherApiTest extends ApiTestCase
{
    // ------------------------------------------------- addition vouchers

    public function test_it_creates_and_posts_an_addition_voucher(): void
    {
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);

        $created = $this->actingAsApi($this->userWith(['addition_vouchers.create']))
            ->apiPost(self::BASE.'/addition-vouchers', [
                'supplier_id' => $supplier->id,
                'invoice_number' => 'INV-1',
                'invoice_value' => 40000,
                'lines' => [
                    ['item_id' => $item->id, 'quantity' => 40, 'unit_cost' => 1000],
                ],
            ]);

        $created->assertCreated();
        $created->assertJsonPath('data.status.value', 'draft');
        $this->assertMatchesRegularExpression('/^AV-\d{6}-\d{4}$/', $created->json('data.voucher_number'));

        // Nothing has moved yet: a draft is a document, not a movement.
        $this->assertSame(0.0, (float) Inventory::where('item_id', $item->id)->sum('on_hand_quantity'));

        $id = $created->json('data.id');

        $this->actingAsApi($this->userWith(['addition_vouchers.post']))
            ->apiPost(self::BASE.'/addition-vouchers/'.$id.'/post')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'posted');

        // Posting is the moment the stock arrives and the supplier is credited.
        $this->assertSame(40.0, (float) Inventory::where('item_id', $item->id)->sum('on_hand_quantity'));
        $this->assertSame(1, $supplier->accountEntries()->count());
    }

    public function test_posting_twice_is_refused(): void
    {
        $voucher = $this->draftAdditionVoucher();
        $user = $this->userWith(['addition_vouchers.post']);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/post')->assertOk();

        // Refused, not ignored — this is what makes double-posting impossible
        // even if the Idempotency-Key is fumbled.
        //
        // It comes back as 403 rather than 422 because AdditionVoucherPolicy
        // gates `post` on `! $voucher->isPosted()`, so the policy answers
        // before the service's own guard is reached. That is the same order
        // the panel applies (the action is hidden once posted), and the
        // service check behind it stays as the last line of defence.
        $response = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/post');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_a_posted_voucher_cannot_be_deleted(): void
    {
        $voucher = $this->draftAdditionVoucher();

        $this->actingAsApi($this->userWith(['addition_vouchers.post']))
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/post')->assertOk();

        // It has already moved stock and credited a supplier; deleting it
        // would leave the ledger holding movements with no source document.
        $response = $this->actingAsApi($this->userWith(['addition_vouchers.create']))
            ->apiDelete(self::BASE.'/addition-vouchers/'.$voucher->id);

        $response->assertStatus(403);
    }

    public function test_the_invoicing_status_is_derived_not_written(): void
    {
        $voucher = $this->draftAdditionVoucher(invoiceNumber: null);
        $user = $this->userWith(['addition_vouchers.post', 'addition_vouchers.invoice']);

        $posted = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/post');
        $posted->assertOk();
        $posted->assertJsonPath('data.invoicing_status.value', 'not_invoiced');

        $invoiced = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/invoice', [
                'invoice_number' => 'INV-88213',
                'invoice_value' => 40250,
            ]);

        $invoiced->assertOk();
        $invoiced->assertJsonPath('data.invoicing_status.value', 'invoiced');
        $invoiced->assertJsonPath('data.invoice_number', 'INV-88213');

        // The supplier was credited at posting with the stock value; recording
        // the invoice CORRECTS that entry rather than adding a second one,
        // which would double the balance.
        $this->assertSame(1, $voucher->fresh()->supplier->accountEntries()->count());
    }

    public function test_it_reports_a_mismatch_between_the_invoice_and_what_arrived(): void
    {
        $voucher = $this->draftAdditionVoucher(invoiceNumber: null);
        $user = $this->userWith(['addition_vouchers.post', 'addition_vouchers.invoice']);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/post')->assertOk();

        // 40 x 1000 = 40,000 entered the store; the supplier invoiced 40,250.
        $response = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/invoice', [
                'invoice_number' => 'INV-2',
                'invoice_value' => 40250,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.invoice_value_mismatch', '250.00');
    }

    public function test_it_closes_a_posted_voucher_that_will_never_be_invoiced(): void
    {
        $voucher = $this->draftAdditionVoucher(invoiceNumber: null);
        $user = $this->userWith(['addition_vouchers.post', 'addition_vouchers.invoice']);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/post')->assertOk();

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/close', [
                'reason' => 'Free replacement for a damaged delivery',
            ])
            ->assertOk()
            ->assertJsonPath('data.invoicing_status.value', 'closed_uninvoiced');
    }

    public function test_a_draft_cannot_be_closed(): void
    {
        $voucher = $this->draftAdditionVoucher(invoiceNumber: null);

        // A draft is still editable and deletable, so closing it means nothing.
        $response = $this->actingAsApi($this->userWith(['addition_vouchers.invoice']))
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/close', ['reason' => 'No invoice']);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_closing_requires_a_written_reason(): void
    {
        $voucher = $this->draftAdditionVoucher(invoiceNumber: null);

        $this->actingAsApi($this->userWith(['addition_vouchers.invoice']))
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/close', [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);
    }

    public function test_a_voucher_needs_a_supplier_or_at_least_a_name(): void
    {
        $item = Item::factory()->create();

        // A receipt from nobody leaves the stock card's description column
        // with nothing to show.
        $this->actingAsApi($this->userWith(['addition_vouchers.create']))
            ->apiPost(self::BASE.'/addition-vouchers', [
                'lines' => [['item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['supplier_id']]]);
    }

    public function test_a_voucher_needs_at_least_one_line(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAsApi($this->userWith(['addition_vouchers.create']))
            ->apiPost(self::BASE.'/addition-vouchers', [
                'supplier_id' => $supplier->id,
                'lines' => [],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['lines']]]);
    }

    public function test_posting_needs_more_than_permission_to_create(): void
    {
        $voucher = $this->draftAdditionVoucher();

        // Raising a receipt and committing it to the stock ledger and the
        // supplier's account are different acts.
        $this->actingAsApi($this->userWith(['addition_vouchers.view', 'addition_vouchers.create']))
            ->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/post')
            ->assertForbidden();
    }

    public function test_a_replayed_post_does_not_add_stock_twice(): void
    {
        $voucher = $this->draftAdditionVoucher();
        $itemId = $voucher->lines()->value('item_id');

        $key = (string) Str::uuid();
        $this->actingAsApi($this->userWith(['addition_vouchers.post']));

        $this->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/post', [], ['Idempotency-Key' => $key])
            ->assertOk();
        $this->apiPost(self::BASE.'/addition-vouchers/'.$voucher->id.'/post', [], ['Idempotency-Key' => $key])
            ->assertOk();

        $this->assertSame(40.0, (float) Inventory::where('item_id', $itemId)->sum('on_hand_quantity'));
    }

    // --------------------------------------------- depreciation vouchers

    public function test_it_prefills_a_depreciation_voucher_from_the_work_orders_issues(): void
    {
        [$workOrder, $item] = $this->workOrderWithIssuedMaterial();

        $response = $this->actingAsApi($this->userWith(['depreciation_vouchers.create']))
            ->apiPost(self::BASE.'/depreciation-vouchers', ['work_order_id' => $workOrder->id]);

        $response->assertCreated();
        $response->assertJsonPath('data.status.value', 'draft');

        // Pre-filled with what was issued, all at zero: the alternative is
        // asking someone on the shop floor to recall which of forty materials
        // went into the job.
        $response->assertJsonPath('data.lines.0.item_id', $item->id);
        $response->assertJsonPath('data.lines.0.quantity', '0.0000');
    }

    public function test_it_sets_the_loss_quantities_and_posts(): void
    {
        [$workOrder, $item] = $this->workOrderWithIssuedMaterial();

        $voucher = $this->actingAsApi($this->userWith(['depreciation_vouchers.create']))
            ->apiPost(self::BASE.'/depreciation-vouchers', ['work_order_id' => $workOrder->id])
            ->json('data');

        $this->actingAsApi($this->userWith(['depreciation_vouchers.create']))
            ->apiPatch(self::BASE.'/depreciation-vouchers/'.$voucher['id'], [
                'loss_type' => 'abnormal',
                'lines' => [
                    ['item_id' => $item->id, 'quantity' => 3, 'unit_cost' => 1000],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', '3.0000');

        $posted = $this->actingAsApi($this->userWith(['depreciation_vouchers.post']))
            ->apiPost(self::BASE.'/depreciation-vouchers/'.$voucher['id'].'/post');

        $posted->assertOk();
        $posted->assertJsonPath('data.status.value', 'posted');
        $posted->assertJsonPath('data.total_value', '3000.00');

        // The loss came out of work-in-progress, lowering the item's balance
        // and value on its stock card.
        $wip = Inventory::where('item_id', $item->id)
            ->where('warehouse_type', WarehouseType::WorkInProgress)
            ->sole();
        $this->assertSame(7.0, (float) $wip->on_hand_quantity);

        // And a balanced journal entry carried the value to a loss account.
        $this->assertNotNull($posted->json('data.journal_entry_id'));
    }

    public function test_a_voucher_with_every_line_at_zero_cannot_be_posted(): void
    {
        [$workOrder] = $this->workOrderWithIssuedMaterial();

        $voucher = $this->actingAsApi($this->userWith(['depreciation_vouchers.create']))
            ->apiPost(self::BASE.'/depreciation-vouchers', ['work_order_id' => $workOrder->id])
            ->json('data');

        // A "posted" voucher recording no loss is worse than no voucher.
        $response = $this->actingAsApi($this->userWith(['depreciation_vouchers.post']))
            ->apiPost(self::BASE.'/depreciation-vouchers/'.$voucher['id'].'/post');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_a_posted_depreciation_voucher_is_immutable(): void
    {
        $voucher = DepreciationVoucher::factory()->create([
            'status' => VoucherStatus::Posted,
            'loss_type' => LossType::Abnormal,
        ]);

        $response = $this->actingAsApi($this->userWith(['depreciation_vouchers.create']))
            ->apiPatch(self::BASE.'/depreciation-vouchers/'.$voucher->id, ['notes' => 'x']);

        $response->assertStatus(403);
    }

    public function test_the_loss_type_must_come_from_the_enum(): void
    {
        $voucher = DepreciationVoucher::factory()->create([
            'status' => VoucherStatus::Draft,
            'loss_type' => LossType::Abnormal,
        ]);

        // Abnormal loss is reversed off the operation's cost and natural loss
        // is not, so a typo here misstates what the job cost.
        $this->actingAsApi($this->userWith(['depreciation_vouchers.create']))
            ->apiPatch(self::BASE.'/depreciation-vouchers/'.$voucher->id, ['loss_type' => 'careless'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['loss_type']]]);
    }

    // ------------------------------------------------------------------ RBAC

    public function test_voucher_endpoints_are_permission_gated(): void
    {
        $voucher = $this->draftAdditionVoucher();
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/addition-vouchers')->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/addition-vouchers/'.$voucher->id)->assertForbidden();
        $this->actingAsApi($user)->apiPost(self::BASE.'/addition-vouchers', [])->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/depreciation-vouchers')->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->apiGet(self::BASE.'/addition-vouchers')->assertUnauthorized();
        $this->apiGet(self::BASE.'/depreciation-vouchers')->assertUnauthorized();
    }

    // ----------------------------------------------------------------- setup

    private function draftAdditionVoucher(?string $invoiceNumber = 'INV-1'): AdditionVoucher
    {
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 1000]);

        $voucher = AdditionVoucher::create([
            'voucher_number' => AdditionVoucher::generateVoucherNumber(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'invoice_number' => $invoiceNumber,
            'invoice_value' => $invoiceNumber === null ? 0 : 40000,
            'voucher_date' => now(),
            'status' => VoucherStatus::Draft,
        ]);

        $voucher->lines()->create([
            'item_id' => $item->id,
            'quantity' => 40,
            'unit_cost' => 1000,
        ]);

        return $voucher->fresh();
    }

    /**
     * A work order with material already sitting in work-in-progress, which is
     * the only state from which a loss can be written off.
     *
     * @return array{0: WorkOrder, 1: Item}
     */
    private function workOrderWithIssuedMaterial(): array
    {
        $workOrder = WorkOrder::factory()->create();
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 1000]);

        // The loss journal is skipped silently when these accounts are not in
        // the chart (deliberately, so a missing account never blocks the stock
        // move). Seeding them means the test exercises the real posting path
        // rather than the fallback.
        Account::create([
            'code' => config('operations.inventory_account_code', '1300'),
            'name' => 'Inventory', 'type' => AccountType::Asset,
            'nature' => AccountDirection::Debit, 'currency' => 'EGP', 'is_active' => true,
        ]);
        Account::create([
            'code' => config('operations.abnormal_loss_account_code', '5060'),
            'name' => 'Manufacturing loss', 'type' => AccountType::Expense,
            'nature' => AccountDirection::Debit, 'currency' => 'EGP', 'is_active' => true,
        ]);

        app(InventoryService::class)->addStock(
            item: $item,
            quantity: 10,
            warehouse: WarehouseType::WorkInProgress,
            unitCost: 1000,
        );

        // The service pre-fills from POSTED issue vouchers, so the draft needs
        // one to have something to list.
        $issue = $workOrder->issueVouchers()->create([
            'voucher_number' => 'IV-TEST-'.Str::random(6),
            'status' => VoucherStatus::Posted,
            'voucher_date' => now(),
        ]);
        $issue->lines()->create([
            'item_id' => $item->id,
            'quantity' => 10,
            'unit_cost' => 1000,
        ]);

        return [$workOrder, $item];
    }
}
