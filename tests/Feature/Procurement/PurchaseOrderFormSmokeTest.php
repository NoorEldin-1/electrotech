<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Filament\Resources\PurchaseOrderResource\Pages\EditPurchaseOrder;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reworked PO form (supplier relationship, live totals preview, item-card
 * suffix action, attachments section) renders without error.
 *
 * Mounts the Edit page rather than Create: the shared form schema is the same,
 * and Create's po_number default calls PurchaseOrder::generatePoNumber(), whose
 * MySQL SUBSTRING_INDEX is unsupported by the SQLite test DB (pre-existing).
 */
class PurchaseOrderFormSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_edit_form_renders(): void
    {
        $supplier = Supplier::factory()->create();
        $po = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id]);
        $item = Item::factory()->create();
        $po->items()->create(['item_id' => $item->id, 'quantity' => 3, 'unit_price' => 10]);

        Livewire::test(EditPurchaseOrder::class, ['record' => $po->getRouteKey()])->assertOk();
    }
}
