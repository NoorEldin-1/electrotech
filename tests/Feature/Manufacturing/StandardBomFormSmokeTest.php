<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\ItemType;
use App\Filament\Resources\BomResource\Pages\EditBom;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Item;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reworked forms (BOM output-item picker, work-order materials repeater with
 * the auto-fetch/manual-override closures) render without error.
 *
 * Mounts Edit rather than Create: Create's wo_number default calls
 * WorkOrder::generateWoNumber(), whose MySQL SUBSTRING_INDEX is unsupported by
 * the SQLite test DB (same pre-existing limitation as the PO smoke test).
 */
class StandardBomFormSmokeTest extends TestCase
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

    public function test_work_order_form_with_materials_renders(): void
    {
        $product = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $wo = WorkOrder::factory()->create(['output_item_id' => $product->id, 'bom_id' => null]);
        $wo->materials()->create(['item_id' => $raw->id, 'quantity' => 5, 'unit_cost' => 4]);

        Livewire::test(EditWorkOrder::class, ['record' => $wo->getRouteKey()])->assertOk();
    }

    public function test_standard_bom_form_renders(): void
    {
        $product = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $bom = Bom::factory()->standard($product)->create();
        BomItem::factory()->create(['bom_id' => $bom->id]);

        Livewire::test(EditBom::class, ['record' => $bom->getRouteKey()])->assertOk();
    }
}
