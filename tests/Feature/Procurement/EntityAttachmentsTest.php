<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\EntityAttachmentPersistence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Slides 3 & 6: suppliers and purchase orders carry attached documents via the
 * now-polymorphic Attachment table, without disturbing project attachments.
 */
class EntityAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_attachment_is_created_and_relocated(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $supplier = Supplier::factory()->create();
        Storage::disk('public')->put('supplier-attachments/new/commercial_registry/reg.pdf', 'x');

        EntityAttachmentPersistence::sync(
            $supplier,
            ['commercial_registry' => ['supplier-attachments/new/commercial_registry/reg.pdf']],
            'supplier-attachments',
        );

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => Supplier::class,
            'attachable_id' => $supplier->id,
            'category' => 'commercial_registry',
        ]);
        Storage::disk('public')->assertExists("supplier-attachments/{$supplier->id}/commercial_registry/reg.pdf");
        $this->assertCount(1, $supplier->attachments()->get());
    }

    public function test_removed_path_deletes_the_attachment_and_file(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $supplier = Supplier::factory()->create();
        Storage::disk('public')->put('supplier-attachments/new/tax_card/card.pdf', 'x');
        EntityAttachmentPersistence::sync(
            $supplier,
            ['tax_card' => ['supplier-attachments/new/tax_card/card.pdf']],
            'supplier-attachments',
        );
        $stored = $supplier->attachments()->first()->file_path;

        // Re-sync with an empty list for the category → removal.
        EntityAttachmentPersistence::sync($supplier, ['tax_card' => []], 'supplier-attachments');

        $this->assertSame(0, $supplier->attachments()->count());
        Storage::disk('public')->assertMissing($stored);
    }

    public function test_purchase_order_attachment_is_polymorphic(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $po = PurchaseOrder::factory()->create();
        Storage::disk('public')->put('po-attachments/new/po_scan/po.pdf', 'x');

        EntityAttachmentPersistence::sync(
            $po,
            ['po_scan' => ['po-attachments/new/po_scan/po.pdf']],
            'po-attachments',
        );

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => PurchaseOrder::class,
            'attachable_id' => $po->id,
            'category' => 'po_scan',
        ]);
    }

    public function test_project_attachments_still_use_project_id(): void
    {
        $project = Project::factory()->create();

        $project->attachments()->create([
            'file_name' => 'plan.pdf',
            'file_path' => 'attachments/1/boq/plan.pdf',
            'category' => 'boq',
            'uploaded_by' => User::factory()->create()->id,
        ]);

        $row = Attachment::where('project_id', $project->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->attachable_type);
        $this->assertTrue($project->attachments->contains($row));
    }
}
