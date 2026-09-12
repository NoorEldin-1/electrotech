<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\MasterData;

use App\Enums\AccountDirection;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Customers and suppliers together: they are the same shape of record (a party
 * with contact details and a ledger balance) and differ only in which
 * documents post against them, so testing them side by side keeps the two
 * contracts from drifting apart.
 */
class PartyApiTest extends ApiTestCase
{
    // ------------------------------------------------------------- customers

    public function test_it_lists_customers_without_the_balance(): void
    {
        Customer::factory()->count(2)->create();

        $response = $this->actingAsApi($this->userWith(['customers.view']))
            ->apiGet(self::BASE.'/customers');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);

        // `balance` is a per-row aggregate, so the list deliberately omits it.
        $response->assertJsonMissingPath('data.0.balance');
    }

    public function test_the_customer_detail_carries_a_signed_ledger_balance(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->userWith(['customers.view']);

        AccountEntry::create([
            'party_type' => $customer->getMorphClass(),
            'party_id' => $customer->id,
            'entry_date' => now(),
            'direction' => AccountDirection::Debit,
            'amount' => 1500.25,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/customers/'.$customer->id);

        $response->assertOk();
        $response->assertJsonPath('data.balance', '1500.25');
    }

    public function test_it_creates_and_updates_a_customer(): void
    {
        $response = $this->actingAsApi($this->userWith(['customers.create']))
            ->apiPost(self::BASE.'/customers', [
                'name' => 'Delta Contracting',
                'email' => 'info@example.com',
            ]);

        $response->assertCreated();
        $id = $response->json('data.id');

        $this->actingAsApi($this->userWith(['customers.edit']))
            ->apiPatch(self::BASE.'/customers/'.$id, ['name' => 'Delta Contracting Co.'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Delta Contracting Co.');
    }

    public function test_it_refuses_to_delete_a_customer_that_still_owns_projects(): void
    {
        $customer = Customer::factory()->create();
        Project::factory()->create(['customer_id' => $customer->id]);

        $response = $this->actingAsApi($this->userWith(['customers.delete']))
            ->apiDelete(self::BASE.'/customers/'.$customer->id);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_it_deletes_a_customer_with_no_projects(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAsApi($this->userWith(['customers.delete']))
            ->apiDelete(self::BASE.'/customers/'.$customer->id)
            ->assertNoContent();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_customer_endpoints_are_permission_gated(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/customers')->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/customers/'.$customer->id)->assertForbidden();
        $this->actingAsApi($user)->apiPost(self::BASE.'/customers', [])->assertForbidden();
        $this->actingAsApi($user)->apiPatch(self::BASE.'/customers/'.$customer->id, [])->assertForbidden();
        $this->actingAsApi($user)->apiDelete(self::BASE.'/customers/'.$customer->id)->assertForbidden();
    }

    public function test_customer_validation_rejects_a_malformed_email(): void
    {
        $response = $this->actingAsApi($this->userWith(['customers.create']))
            ->apiPost(self::BASE.'/customers', ['name' => 'X', 'email' => 'not-an-email']);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    // ------------------------------------------------------------- suppliers

    public function test_it_lists_suppliers_and_exposes_the_profit_tax_exemption(): void
    {
        Supplier::factory()->create(['profit_tax_exempt' => true]);
        Supplier::factory()->create(['profit_tax_exempt' => false]);

        $response = $this->actingAsApi($this->userWith(['suppliers.view']))
            ->apiGet(self::BASE.'/suppliers?filter[profit_tax_exempt]=true');

        $response->assertOk();
        $response->assertJsonPath('meta.pagination.total', 1);
        $response->assertJsonPath('data.0.profit_tax_exempt', true);
    }

    public function test_the_supplier_detail_carries_a_balance(): void
    {
        $supplier = Supplier::factory()->create();
        $user = $this->userWith(['suppliers.view']);

        AccountEntry::create([
            'party_type' => $supplier->getMorphClass(),
            'party_id' => $supplier->id,
            'entry_date' => now(),
            'direction' => AccountDirection::Credit,
            'amount' => 800,
            'created_by' => $user->id,
        ]);

        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/suppliers/'.$supplier->id)
            ->assertOk()
            ->assertJsonPath('data.balance', '800.00');
    }

    public function test_it_creates_a_supplier(): void
    {
        $this->actingAsApi($this->userWith(['suppliers.create']))
            ->apiPost(self::BASE.'/suppliers', [
                'name' => 'Cairo Metals',
                'profit_tax_exempt' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.profit_tax_exempt', true);
    }

    public function test_it_refuses_to_delete_a_supplier_with_purchase_orders(): void
    {
        $supplier = Supplier::factory()->create();
        PurchaseOrder::factory()->create(['supplier_id' => $supplier->id]);

        $response = $this->actingAsApi($this->userWith(['suppliers.delete']))
            ->apiDelete(self::BASE.'/suppliers/'.$supplier->id);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_supplier_endpoints_are_permission_gated(): void
    {
        $supplier = Supplier::factory()->create();
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/suppliers')->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/suppliers/'.$supplier->id)->assertForbidden();
        $this->actingAsApi($user)->apiPost(self::BASE.'/suppliers', [])->assertForbidden();
        $this->actingAsApi($user)->apiPatch(self::BASE.'/suppliers/'.$supplier->id, [])->assertForbidden();
        $this->actingAsApi($user)->apiDelete(self::BASE.'/suppliers/'.$supplier->id)->assertForbidden();
    }

    // ------------------------------------------------------------------ auth

    public function test_both_catalogues_require_a_token(): void
    {
        $this->apiGet(self::BASE.'/customers')->assertUnauthorized();
        $this->apiGet(self::BASE.'/suppliers')->assertUnauthorized();
    }
}
