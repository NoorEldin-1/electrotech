<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\ClaimStatus;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\FacilityStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\CreditFacility;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\FinancialClaim;
use App\Models\OperationPayment;
use App\Models\Project;
use App\Models\Supplier;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Invoicing a delivery, chasing the money, and the commitments behind it:
 * sales invoices, operation payments, financial claims, credit facilities and
 * party statements.
 */
class ReceivablesApiTest extends ApiTestCase
{
    // -------------------------------------------------------- sales invoices

    public function test_a_delivery_can_be_invoiced_in_instalments(): void
    {
        $voucher = $this->activeVoucher(250000);

        $first = $this->actingAsApi($this->userWith(['sales_invoices.create']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/invoices', [
                'invoice_number' => 'INV-2026-0044',
                'invoice_date' => '2026-09-15',
                'amount' => 150000,
            ]);

        $first->assertCreated();
        $first->assertJsonPath('data.amount', '150000.00');

        // The customer comes from the VOUCHER, never from the payload — an
        // invoice cannot name someone other than whoever received the goods.
        $first->assertJsonPath('data.customer_id', $voucher->customer_id);

        // The voucher's invoicing status is DERIVED on every change.
        $this->actingAsApi($this->userWith(['delivery_vouchers.view']))
            ->apiGet(self::BASE.'/delivery-vouchers/'.$voucher->id)
            ->assertOk()
            ->assertJsonPath('data.invoicing.status.value', 'partially_invoiced')
            ->assertJsonPath('data.invoicing.invoiced_value', '150000.00');

        $this->actingAsApi($this->userWith(['sales_invoices.create']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/invoices', [
                'invoice_number' => 'INV-2026-0051',
                'invoice_date' => '2026-10-01',
                'amount' => 100000,
            ])
            ->assertCreated();

        $this->actingAsApi($this->userWith(['delivery_vouchers.view']))
            ->apiGet(self::BASE.'/delivery-vouchers/'.$voucher->id)
            ->assertOk()
            ->assertJsonPath('data.invoicing.status.value', 'fully_invoiced');
    }

    public function test_invoicing_more_than_was_delivered_is_refused(): void
    {
        $voucher = $this->activeVoucher(250000);

        $response = $this->actingAsApi($this->userWith(['sales_invoices.create']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/invoices', [
                'invoice_number' => 'INV-TOO-BIG',
                'invoice_date' => '2026-09-15',
                'amount' => 300000,
            ]);

        // The rule that keeps "delivered" and "invoiced" reconcilable.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertSame(0, $voucher->invoices()->count());
    }

    public function test_a_voucher_that_was_never_delivered_cannot_be_invoiced(): void
    {
        $voucher = DeliveryVoucher::factory()->create(['status' => DeliveryVoucherStatus::Draft]);

        $response = $this->actingAsApi($this->userWith(['sales_invoices.create']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/invoices', [
                'invoice_number' => 'INV-EARLY',
                'invoice_date' => '2026-09-15',
                'amount' => 1000,
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_deleting_an_invoice_re_derives_the_vouchers_status(): void
    {
        $voucher = $this->activeVoucher(250000);

        $id = $this->actingAsApi($this->userWith(['sales_invoices.create']))
            ->apiPost(self::BASE.'/delivery-vouchers/'.$voucher->id.'/invoices', [
                'invoice_number' => 'INV-2026-0044',
                'invoice_date' => '2026-09-15',
                'amount' => 250000,
            ])->json('data.id');

        $this->actingAsApi($this->userWith(['sales_invoices.delete']))
            ->apiDelete(self::BASE.'/sales-invoices/'.$id)
            ->assertNoContent();

        // Back to not_invoiced rather than a stale "fully invoiced" left behind.
        $this->actingAsApi($this->userWith(['delivery_vouchers.view']))
            ->apiGet(self::BASE.'/delivery-vouchers/'.$voucher->id)
            ->assertOk()
            ->assertJsonPath('data.invoicing.status.value', 'not_invoiced')
            ->assertJsonPath('data.invoicing.invoiced_value', '0.00');
    }

    // ----------------------------------------------------- operation payments

    public function test_it_records_a_payment_and_publishes_the_operation_totals(): void
    {
        $project = Project::factory()->create();

        $this->actingAsApi($this->userWith(['operation_payments.record']))
            ->apiPost(self::BASE.'/operation-payments', [
                'project_id' => $project->id,
                'direction' => PaymentDirection::Incoming->value,
                'method' => PaymentMethod::BankTransfer->value,
                'amount' => 150000,
                'reference' => 'TRF-99213',
            ])
            ->assertCreated()
            ->assertJsonPath('data.direction.value', 'incoming')
            ->assertJsonPath('data.amount', '150000.00');

        $this->actingAsApi($this->userWith(['operation_payments.record']))
            ->apiPost(self::BASE.'/operation-payments', [
                'project_id' => $project->id,
                'direction' => PaymentDirection::Outgoing->value,
                'method' => PaymentMethod::Cash->value,
                'amount' => 20000,
            ])
            ->assertCreated();

        // Published rather than left to each client to sum — two screens
        // summing the same rows differently is how a cash position becomes an
        // argument.
        $this->actingAsApi($this->userWith(['operation_payments.view']))
            ->apiGet(self::BASE.'/projects/'.$project->id.'/payment-totals')
            ->assertOk()
            ->assertJsonPath('data.received', '150000.00')
            ->assertJsonPath('data.paid', '20000.00')
            ->assertJsonPath('data.net', '130000.00');
    }

    public function test_a_payment_that_reached_the_ledger_can_no_longer_be_deleted(): void
    {
        $payment = OperationPayment::factory()->create([
            'journal_entry_id' => \App\Models\JournalEntry::factory()->create()->id,
        ]);

        // 403, not 422: OperationPaymentPolicy gates delete on the journal
        // entry being absent, so the policy answers first.
        $this->actingAsApi($this->userWith(['operation_payments.record']))
            ->apiDelete(self::BASE.'/operation-payments/'.$payment->id)
            ->assertStatus(403);
    }

    public function test_a_payment_allocated_to_a_claim_collects_it_once_fully_paid(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Completed]);
        $customer = Customer::factory()->create();

        $claim = FinancialClaim::factory()->create([
            'project_id' => $project->id,
            'customer_id' => $customer->id,
            'amount' => 100000,
            'status' => ClaimStatus::Draft,
        ]);

        $this->actingAsApi($this->userWith(['financial_claims.submit']))
            ->apiPost(self::BASE.'/financial-claims/'.$claim->id.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'submitted');

        $this->actingAsApi($this->userWith(['operation_payments.record']))
            ->apiPost(self::BASE.'/operation-payments', [
                'project_id' => $project->id,
                'customer_id' => $customer->id,
                'financial_claim_id' => $claim->id,
                'direction' => PaymentDirection::Incoming->value,
                'method' => PaymentMethod::Cheque->value,
                'amount' => 100000,
            ])
            ->assertCreated();

        // The claim settles itself; `collect` is for cash that arrived outside
        // the platform.
        $this->assertSame(ClaimStatus::Collected, $claim->fresh()->status);
    }

    // ------------------------------------------------------ financial claims

    public function test_a_claim_cannot_be_submitted_before_anything_is_deliverable(): void
    {
        $claim = FinancialClaim::factory()->create([
            'project_id' => Project::factory()->create(['status' => ProjectStatus::InProgress]),
            'status' => ClaimStatus::Draft,
        ]);

        $response = $this->actingAsApi($this->userWith(['financial_claims.submit']))
            ->apiPost(self::BASE.'/financial-claims/'.$claim->id.'/submit');

        // Claiming for work that has not been delivered is how a receivable
        // becomes a dispute.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertSame(ClaimStatus::Draft, $claim->fresh()->status);
    }

    public function test_an_active_delivery_is_enough_to_submit_a_claim(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        DeliveryVoucher::factory()->create([
            'project_id' => $project->id,
            'status' => DeliveryVoucherStatus::Active,
        ]);

        $claim = FinancialClaim::factory()->create([
            'project_id' => $project->id,
            'status' => ClaimStatus::Draft,
        ]);

        $this->actingAsApi($this->userWith(['financial_claims.submit']))
            ->apiPost(self::BASE.'/financial-claims/'.$claim->id.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'submitted');
    }

    public function test_a_submitted_claim_can_no_longer_be_edited(): void
    {
        $claim = FinancialClaim::factory()->create(['status' => ClaimStatus::Submitted]);
        $user = $this->userWith(['financial_claims.create']);

        // The customer is already holding a copy.
        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/financial-claims/'.$claim->id, ['amount' => 1])
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiDelete(self::BASE.'/financial-claims/'.$claim->id)
            ->assertStatus(403);
    }

    public function test_submitting_and_collecting_carry_different_permissions(): void
    {
        $claim = FinancialClaim::factory()->create(['status' => ClaimStatus::Submitted]);

        // Being allowed to send a claim is not being allowed to declare it paid.
        $response = $this->actingAsApi($this->userWith(['financial_claims.submit']))
            ->apiPost(self::BASE.'/financial-claims/'.$claim->id.'/collect');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_a_claim_detail_carries_what_has_been_paid_against_it(): void
    {
        $claim = FinancialClaim::factory()->create(['amount' => 250000]);

        OperationPayment::factory()->create([
            'financial_claim_id' => $claim->id,
            'direction' => PaymentDirection::Incoming,
            'amount' => 150000,
        ]);

        $this->actingAsApi($this->userWith(['financial_claims.view']))
            ->apiGet(self::BASE.'/financial-claims/'.$claim->id)
            ->assertOk()
            ->assertJsonPath('data.paid_amount', '150000.00');
    }

    // ----------------------------------------------------- credit facilities

    public function test_a_facility_cannot_be_promised_twice(): void
    {
        $facility = CreditFacility::factory()->create([
            'limit_amount' => 1000000,
            'status' => FacilityStatus::Active,
        ]);

        $this->actingAsApi($this->userWith(['credit_facilities.manage']))
            ->apiPost(self::BASE.'/credit-facilities/'.$facility->id.'/allocate', [
                'project_id' => Project::factory()->create()->id,
                'amount' => 800000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.utilization.used', '800000.00')
            ->assertJsonPath('data.utilization.available', '200000.00');

        $response = $this->actingAsApi($this->userWith(['credit_facilities.manage']))
            ->apiPost(self::BASE.'/credit-facilities/'.$facility->id.'/allocate', [
                'project_id' => Project::factory()->create()->id,
                'amount' => 300000,
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertSame(1, $facility->allocations()->count());
    }

    public function test_releasing_an_allocation_gives_the_room_back(): void
    {
        $facility = CreditFacility::factory()->create(['limit_amount' => 1000000]);

        $allocated = $this->actingAsApi($this->userWith(['credit_facilities.manage']))
            ->apiPost(self::BASE.'/credit-facilities/'.$facility->id.'/allocate', [
                'project_id' => Project::factory()->create()->id,
                'amount' => 800000,
            ]);

        $allocationId = $allocated->json('data.allocations.0.id');

        $this->actingAsApi($this->userWith(['credit_facilities.manage']))
            ->apiPost(self::BASE.'/facility-allocations/'.$allocationId.'/release')
            ->assertOk()
            ->assertJsonPath('data.utilization.available', '1000000.00');

        // The row stays, marked released — the history of what was committed
        // and when survives.
        $this->assertSame(1, $facility->allocations()->count());
    }

    public function test_a_facility_with_allocations_cannot_be_deleted(): void
    {
        $facility = CreditFacility::factory()->create(['limit_amount' => 1000000]);

        $this->actingAsApi($this->userWith(['credit_facilities.manage']))
            ->apiPost(self::BASE.'/credit-facilities/'.$facility->id.'/allocate', [
                'project_id' => Project::factory()->create()->id,
                'amount' => 100,
            ])->assertCreated();

        $response = $this->actingAsApi($this->userWith(['credit_facilities.manage']))
            ->apiDelete(self::BASE.'/credit-facilities/'.$facility->id);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    // ------------------------------------------------------ party statements

    public function test_a_customer_statement_carries_a_running_balance(): void
    {
        $customer = Customer::factory()->create();

        AccountEntry::create([
            'party_type' => $customer->getMorphClass(),
            'party_id' => $customer->id,
            'entry_date' => '2026-09-01',
            'direction' => AccountDirection::Debit,
            'amount' => 250000,
        ]);

        // A settlement comes back as a NEGATIVE amount. The platform's
        // convention throughout — Customer::getBalanceAttribute,
        // Supplier::getBalanceAttribute and the panel's own running-balance
        // column all do SUM(amount) — is that `amount` carries the sign and
        // `direction` is the label beside it. A client must not re-sign the
        // figure by reading the direction, or every settlement would add.
        AccountEntry::create([
            'party_type' => $customer->getMorphClass(),
            'party_id' => $customer->id,
            'entry_date' => '2026-09-15',
            'direction' => AccountDirection::Credit,
            'amount' => -150000,
        ]);

        $response = $this->actingAsApi($this->userWith(['customer_statements.view']))
            ->apiGet(self::BASE.'/customers/'.$customer->id.'/statement');

        $response->assertOk();
        $response->assertJsonPath('data.party_type', 'customer');
        $response->assertJsonPath('data.balance', '100000.00');

        // The running balance is what makes this the document you print.
        $response->assertJsonPath('data.entries.0.running_balance', '250000.00');
        $response->assertJsonPath('data.entries.1.running_balance', '100000.00');
    }

    public function test_customer_and_supplier_statements_carry_separate_permissions(): void
    {
        $supplier = Supplier::factory()->create();

        // Seeing what the company owes its suppliers and what its customers
        // owe it are different privileges.
        $response = $this->actingAsApi($this->userWith(['customer_statements.view']))
            ->apiGet(self::BASE.'/suppliers/'.$supplier->id.'/statement');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_party_movements_cannot_be_written_through_the_api(): void
    {
        $user = $this->userWith(['customer_statements.view']);

        // There are no write routes at all: these rows are written by
        // documents, and an editable statement could be brought into line with
        // a balance somebody expected rather than the documents behind it.
        $this->actingAsApi($user)->apiPost(self::BASE.'/account-entries', [])->assertStatus(405);
    }

    // --------------------------------------------------------------- gates

    public function test_finance_endpoints_are_permission_gated(): void
    {
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/sales-invoices')->assertStatus(403);
        $this->actingAsApi($user)->apiGet(self::BASE.'/operation-payments')->assertStatus(403);
        $this->actingAsApi($user)->apiGet(self::BASE.'/financial-claims')->assertStatus(403);
        $this->actingAsApi($user)->apiGet(self::BASE.'/credit-facilities')->assertStatus(403);
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->apiGet(self::BASE.'/financial-claims')->assertStatus(401);
        $this->apiGet(self::BASE.'/credit-facilities')->assertStatus(401);
    }

    // ------------------------------------------------------------- helpers

    /**
     * An ACTIVE delivery voucher worth `$value`, which is what an invoice can
     * be raised against.
     */
    private function activeVoucher(float $value): DeliveryVoucher
    {
        return DeliveryVoucher::factory()->create([
            'status' => DeliveryVoucherStatus::Active,
            'total_value' => $value,
            'activated_at' => now(),
        ]);
    }
}
