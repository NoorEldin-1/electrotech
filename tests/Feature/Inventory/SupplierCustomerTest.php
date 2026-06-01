<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Customer;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_procurement_manages_suppliers_but_not_delete(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Procurement');

        $this->assertTrue($user->can('suppliers.view'));
        $this->assertTrue($user->can('suppliers.create'));
        $this->assertTrue($user->can('suppliers.edit'));
        $this->assertFalse($user->can('suppliers.delete'));
    }

    public function test_sales_manages_customers(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');

        $this->assertTrue($user->can('customers.view'));
        $this->assertTrue($user->can('customers.create'));
        $this->assertTrue($user->can('customers.edit'));
    }

    public function test_warehouse_manager_cannot_manage_suppliers(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Warehouse_Manager');

        $this->assertFalse($user->can('suppliers.create'));
        $this->assertFalse($user->can('customers.create'));
    }

    public function test_purchase_order_links_to_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        $po = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id]);

        $this->assertTrue($po->supplier->is($supplier));
        $this->assertTrue($supplier->purchaseOrders->contains($po));
    }

    public function test_project_links_to_customer(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->create(['customer_id' => $customer->id]);

        $this->assertTrue($project->customer->is($customer));
        $this->assertTrue($customer->projects->contains($project));
    }
}
