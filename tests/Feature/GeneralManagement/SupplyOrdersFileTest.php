<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\DeliveryVoucherStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Filament\Pages\SupplyOrdersFile;
use App\Models\DeliveryVoucher;
use App\Models\OperationPayment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\OperationPaymentService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplyOrdersFileTest extends TestCase
{
    use RefreshDatabase;

    private function page(int $projectId): SupplyOrdersFile
    {
        $page = new SupplyOrdersFile();
        $page->projectId = $projectId;

        return $page;
    }

    public function test_summary_aggregates_revenue_received_and_outstanding(): void
    {
        config(['operations.auto_journal_payments' => false]);
        $this->actingAs(User::factory()->create());

        $project = Project::factory()->active()->create();
        DeliveryVoucher::factory()->create([
            'project_id' => $project->id,
            'status' => DeliveryVoucherStatus::Active,
            'total_value' => 2000,
        ]);
        app(OperationPaymentService::class)->record([
            'project_id' => $project->id,
            'direction' => PaymentDirection::Incoming,
            'method' => PaymentMethod::Cash,
            'amount' => 1500,
        ]);

        $summary = $this->page($project->id)->getSummary();

        $this->assertEquals(2000.0, $summary['revenue']);
        $this->assertEquals(1500.0, $summary['received']);
        $this->assertEquals(500.0, $summary['outstanding']);
    }

    public function test_purchase_orders_are_listed_for_the_operation(): void
    {
        $project = Project::factory()->active()->create();
        PurchaseOrder::factory()->count(2)->create(['project_id' => $project->id]);
        PurchaseOrder::factory()->create(); // another operation — excluded

        $this->assertCount(2, $this->page($project->id)->getPurchaseOrders());
    }

    public function test_access_is_gated_by_permission(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('General_Manager');
        $this->actingAs($manager);
        $this->assertTrue(SupplyOrdersFile::canAccess());

        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $this->assertFalse(SupplyOrdersFile::canAccess());
    }
}
