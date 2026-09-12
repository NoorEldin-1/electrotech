<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery;

use App\Enums\DeliveryVoucherStatus;
use App\Enums\InstallationStatus;
use App\Enums\ItemType;
use App\Enums\WarehouseType;
use App\Models\Customer;
use App\Models\DeliveryMinute;
use App\Models\DeliveryVoucher;
use App\Models\Installation;
use App\Models\Item;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Delivery & field ops: the dual-signature delivery voucher, the minute that
 * records the handover, and the site work that follows.
 */
class DeliveryApiTest extends ApiTestCase
{
    // ----------------------------------------------------- delivery vouchers

    public function test_it_raises_a_draft_voucher_with_a_generated_number(): void
    {
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['type' => ItemType::FinishedGood, 'unit_cost' => 1000]);

        $response = $this->actingAsApi($this->userWith(['delivery_vouchers.create']))
            ->apiPost(self::BASE.'/delivery-vouchers', [
                'customer_id' => $customer->id,
                'supply_order_number' => 'SO-2026-88',
                'lines' => [['item_id' => $item->id, 'quantity' => 9]],
            ]);

        $response->assertCreated();
        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.status.value', 'draft');
        $this->assertMatchesRegularExpression('/^DV-\d{6}-\d{4}$/', $response->json('data.voucher_number'));

        // The delivered VALUE is zero until the voucher activates — nothing has
        // been delivered yet — while the lines already price out.
        $response->assertJsonPath('data.total_value', '0.00');
        $response->assertJsonPath('data.lines_value', '9000.00');

        // unit_cost falls back to the item card: what a delivery is worth is
        // what the goods cost, not a price typed at the loading bay.
        $response->assertJsonPath('data.lines.0.unit_cost', '1000.00');
    }

    public function test_neither_signature_can_be_sent_as_a_field(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAsApi($this->userWith(['delivery_vouchers.create']))
            ->apiPost(self::BASE.'/delivery-vouchers', [
                'customer_id' => $customer->id,
                'technical_approved_at' => now()->toIso8601String(),
                'financial_approved_at' => now()->toIso8601String(),
                'status' => DeliveryVoucherStatus::Active->value,
            ]);

        $response->assertCreated();

        // A voucher that could be created pre-approved would make the
        // dual-signature rule decorative.
        $response->assertJsonPath('data.status.value', 'draft');
        $response->assertJsonPath('data.approvals.technical_approved', false);
        $response->assertJsonPath('data.approvals.financial_approved', false);
    }

    public function test_one_signature_alone_does_not_activate_the_voucher(): void
    {
        [$voucher, $item] = $this->deliverableVoucher();

        $response = $this->actingAsApi($this->userWith(['delivery_vouchers.approve_technical']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-technical');

        $response->assertOk();
        $response->assertJsonPath('data.status.value', 'pending_approval');
        $response->assertJsonPath('data.approvals.technical_approved', true);
        $response->assertJsonPath('data.approvals.fully_approved', false);

        // Nothing has moved on one signature.
        $this->assertSame(20.0, $item->fresh()->availableIn(WarehouseType::FinishedGoods));
    }

    public function test_the_second_signature_activates_and_moves_everything_at_once(): void
    {
        [$voucher, $item, $customer] = $this->deliverableVoucher();

        $this->actingAsApi($this->userWith(['delivery_vouchers.approve_technical']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-technical')
            ->assertOk();

        $response = $this->actingAsApi($this->userWith(['delivery_vouchers.approve_financial']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-financial');

        $response->assertOk();
        $response->assertJsonPath('data.status.value', 'active');
        $response->assertJsonPath('data.approvals.fully_approved', true);
        $response->assertJsonPath('data.total_value', '9000.00');
        $this->assertNotNull($response->json('data.activated_at'));

        // Activation is one transaction: the goods leave, and the customer is
        // debited with the delivered value.
        $this->assertSame(11.0, $item->fresh()->availableIn(WarehouseType::FinishedGoods));
        $this->assertSame(1, $customer->accountEntries()->count());
        $this->assertSame(9000.0, (float) $customer->accountEntries()->sum('amount'));
    }

    public function test_the_order_of_the_two_signatures_does_not_matter(): void
    {
        [$voucher] = $this->deliverableVoucher();

        $this->actingAsApi($this->userWith(['delivery_vouchers.approve_financial']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-financial')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'pending_approval');

        $this->actingAsApi($this->userWith(['delivery_vouchers.approve_technical']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-technical')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'active');
    }

    public function test_a_failed_activation_does_not_leave_a_signature_behind(): void
    {
        // Only 2 in stock against a voucher for 9.
        [$voucher, $item] = $this->deliverableVoucher(stock: 2);

        $this->actingAsApi($this->userWith(['delivery_vouchers.approve_technical']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-technical')
            ->assertOk();

        $response = $this->actingAsApi($this->userWith(['delivery_vouchers.approve_financial']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-financial');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');

        $voucher->refresh();

        // The signature and the activation are ONE unit of work. A surviving
        // signature would show an approval that never took effect, while the
        // user was told it failed.
        $this->assertFalse($voucher->isFinancialApproved());
        $this->assertFalse($voucher->isActive());
        $this->assertSame(2.0, $item->fresh()->availableIn(WarehouseType::FinishedGoods));
    }

    public function test_each_signature_carries_its_own_permission(): void
    {
        [$voucher] = $this->deliverableVoucher();

        // Holding the technical permission is not holding the financial one.
        $response = $this->actingAsApi($this->userWith(['delivery_vouchers.approve_technical']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-financial');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_an_active_voucher_can_no_longer_be_changed(): void
    {
        [$voucher] = $this->deliverableVoucher();
        $this->activate($voucher);

        $user = $this->userWith(['delivery_vouchers.create', 'delivery_vouchers.cancel']);

        // 403 rather than 422: the policy gates update, delete and cancel on
        // `! isActive()`, so it answers before any service guard is reached.
        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/delivery-vouchers/'.$voucher->id, ['notes' => 'late edit'])
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiJson('PUT', self::BASE.'/delivery-vouchers/'.$voucher->id.'/lines', ['lines' => []])
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/cancel')
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiDelete(self::BASE.'/delivery-vouchers/'.$voucher->id)
            ->assertStatus(403);
    }

    public function test_replaying_an_approval_does_not_debit_the_customer_twice(): void
    {
        [$voucher, , $customer] = $this->deliverableVoucher();

        $this->actingAsApi($this->userWith(['delivery_vouchers.approve_technical']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-technical')
            ->assertOk();

        // One token for both calls — the idempotency cache is keyed on the
        // bearer token (Finding #18).
        $request = $this->actingAsApi($this->userWith(['delivery_vouchers.approve_financial']));
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $request->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-financial', [], $key)->assertOk();
        $request->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/approve-financial', [], $key)->assertOk();

        $this->assertSame(1, $customer->accountEntries()->count());
    }

    public function test_it_filters_and_paginates_vouchers(): void
    {
        DeliveryVoucher::factory()->count(3)->create(['status' => DeliveryVoucherStatus::Draft]);
        DeliveryVoucher::factory()->count(2)->create(['status' => DeliveryVoucherStatus::Active]);

        $response = $this->actingAsApi($this->userWith(['delivery_vouchers.view']))
            ->apiGet(self::BASE.'/delivery-vouchers?filter[status]=draft&per_page=2');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $response->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_the_voucher_index_does_not_grow_its_query_count_with_its_rows(): void
    {
        $user = $this->userWith(['delivery_vouchers.view']);

        // Warm-up: the first request also builds Spatie's permission cache.
        $this->actingAsApi($user)->apiGet(self::BASE.'/delivery-vouchers');

        $count = function (int $rows) use ($user): int {
            DeliveryVoucher::query()->forceDelete();
            DeliveryVoucher::factory()->count($rows)->create();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAsApi($user)->apiGet(self::BASE.'/delivery-vouchers')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(3), $count(9));
    }

    // ------------------------------------------------------ delivery minutes

    public function test_a_minute_inherits_the_operation_and_customer_from_its_voucher(): void
    {
        $voucher = DeliveryVoucher::factory()->create(['project_id' => Project::factory()]);

        $response = $this->actingAsApi($this->userWith(['delivery_minutes.create']))
            ->apiPost(self::BASE.'/delivery-minutes', [
                'delivery_voucher_id' => $voucher->id,
                'content' => 'Nine panels received in good order.',
            ]);

        $response->assertCreated();
        $this->assertMatchesRegularExpression('/^DM-\d{6}-\d{4}$/', $response->json('data.minute_number'));

        // Inherited, not sent: a minute naming a different customer from the
        // delivery it records would be unresolvable afterwards.
        $response->assertJsonPath('data.customer_id', $voucher->customer_id);
        $response->assertJsonPath('data.project_id', $voucher->project_id);
        $response->assertJsonPath('data.distributed', false);
    }

    public function test_a_minute_cannot_be_raised_from_a_voucher_with_no_operation(): void
    {
        $voucher = DeliveryVoucher::factory()->create(['project_id' => null]);

        $response = $this->actingAsApi($this->userWith(['delivery_minutes.create']))
            ->apiPost(self::BASE.'/delivery-minutes', ['delivery_voucher_id' => $voucher->id]);

        // A minute is filed in an operation's folder and circulated in its
        // name, so the refusal is explicit rather than a 500 from the insert.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_distribution_is_one_way_and_idempotent(): void
    {
        $minute = DeliveryMinute::factory()->create(['distributed_at' => null]);
        $user = $this->userWith(['delivery_minutes.distribute']);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/delivery-minutes/'.$minute->id.'/distribute')
            ->assertOk()
            ->assertJsonPath('data.distributed', true);

        $firstStamp = $minute->fresh()->distributed_at;

        // A retry is a silent success and sends nothing again — which matters
        // on a weak link, where a client cannot tell a lost response from a
        // lost request.
        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/delivery-minutes/'.$minute->id.'/distribute')
            ->assertOk();

        $this->assertEquals($firstStamp, $minute->fresh()->distributed_at);
    }

    public function test_a_distributed_minute_freezes(): void
    {
        $minute = DeliveryMinute::factory()->create(['distributed_at' => now()]);
        $user = $this->userWith(['delivery_minutes.create']);

        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/delivery-minutes/'.$minute->id, ['content' => 'revised'])
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiDelete(self::BASE.'/delivery-minutes/'.$minute->id)
            ->assertStatus(403);
    }

    // -------------------------------------------------------- field work

    public function test_an_installation_walks_pending_to_completed(): void
    {
        $installation = Installation::factory()->create(['status' => InstallationStatus::Pending]);
        $user = $this->userWith(['installations.manage']);

        $started = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/installations/'.$installation->id.'/start');

        $started->assertOk();
        $started->assertJsonPath('data.status.value', 'in_progress');
        $this->assertNotNull($started->json('data.started_at'));

        $completed = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/installations/'.$installation->id.'/complete');

        $completed->assertOk();
        $completed->assertJsonPath('data.status.value', 'completed');
        $this->assertNotNull($completed->json('data.completed_at'));
    }

    public function test_an_installation_cannot_skip_straight_to_completed(): void
    {
        $installation = Installation::factory()->create(['status' => InstallationStatus::Pending]);

        $response = $this->actingAsApi($this->userWith(['installations.manage']))
            ->apiPost(self::BASE.'/installations/'.$installation->id.'/complete');

        // The start time is what the duration on site is measured from, and an
        // installation with no start has no duration.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertSame(InstallationStatus::Pending, $installation->fresh()->status);
    }

    public function test_the_installation_status_cannot_be_patched(): void
    {
        $installation = Installation::factory()->create(['status' => InstallationStatus::Pending]);

        $this->actingAsApi($this->userWith(['installations.manage']))
            ->apiPatch(self::BASE.'/installations/'.$installation->id, [
                'status' => InstallationStatus::Completed->value,
                'notes' => 'Crane booked',
            ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Crane booked');

        // A status a client can set is a status that drifts from the
        // timestamps beside it.
        $this->assertSame(InstallationStatus::Pending, $installation->fresh()->status);
        $this->assertNull($installation->fresh()->completed_at);
    }

    public function test_a_site_survey_defaults_its_surveyor_to_the_caller(): void
    {
        $project = Project::factory()->create();
        $user = $this->userWith(['site_surveys.manage']);

        $response = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/site-surveys', [
                'project_id' => $project->id,
                'measurements' => 'Riser 4.2m, clearance 800mm',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.surveyed_by', $user->id);

        // Free text, read back verbatim — a schema that fitted the last ten
        // sites would silently lose the eleventh.
        $response->assertJsonPath('data.measurements', 'Riser 4.2m, clearance 800mm');
    }

    public function test_surveys_are_searchable_and_filterable(): void
    {
        $project = Project::factory()->create();
        SiteSurvey::factory()->count(2)->create(['project_id' => $project->id]);
        SiteSurvey::factory()->create();

        $this->actingAsApi($this->userWith(['site_surveys.view']))
            ->apiGet(self::BASE.'/site-surveys?filter[project]='.$project->id)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
    }

    // --------------------------------------------------------------- gates

    public function test_delivery_endpoints_are_permission_gated(): void
    {
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/delivery-vouchers')->assertStatus(403);
        $this->actingAsApi($user)->apiGet(self::BASE.'/delivery-minutes')->assertStatus(403);
        $this->actingAsApi($user)->apiGet(self::BASE.'/installations')->assertStatus(403);
        $this->actingAsApi($user)->apiGet(self::BASE.'/site-surveys')->assertStatus(403);
    }

    public function test_a_token_without_the_delivery_ability_is_refused(): void
    {
        $response = $this->actingAsApi($this->admin(), ['sales'])
            ->apiGet(self::BASE.'/delivery-vouchers');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->apiGet(self::BASE.'/delivery-vouchers')->assertStatus(401);
        $this->apiGet(self::BASE.'/installations')->assertStatus(401);
    }

    // ------------------------------------------------------------- helpers

    /**
     * A draft voucher for 9 units of a finished good, with `$stock` of it on
     * hand in finished goods.
     *
     * @return array{0: DeliveryVoucher, 1: Item, 2: Customer}
     */
    private function deliverableVoucher(float $stock = 20): array
    {
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['type' => ItemType::FinishedGood, 'unit_cost' => 1000]);

        $voucher = DeliveryVoucher::factory()->create([
            'customer_id' => $customer->id,
            'status' => DeliveryVoucherStatus::Draft,
        ]);

        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => 9, 'unit_cost' => 1000]);

        // Seeding stock writes an inventory transaction stamped with the
        // acting user, so the fixture needs one signed in — otherwise
        // InventoryService falls back to user id 1, which may not exist.
        $this->actingAs($this->admin());
        app(InventoryService::class)->addStock($item, $stock, warehouse: WarehouseType::FinishedGoods);

        return [$voucher, $item, $customer];
    }

    private function activate(DeliveryVoucher $voucher): void
    {
        $service = app(\App\Services\DeliveryVoucherService::class);
        $service->approveTechnical($voucher, $this->admin());
        $service->approveFinancial($voucher->fresh(), $this->admin());
    }
}
