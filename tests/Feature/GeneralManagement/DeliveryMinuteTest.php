<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Models\DeliveryMinute;
use App\Models\DeliveryVoucher;
use App\Models\Project;
use App\Models\User;
use App\Services\DeliveryMinuteService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryMinuteTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_from_delivery_links_operation_and_auto_numbers(): void
    {
        $project = Project::factory()->active()->create();
        $voucher = DeliveryVoucher::factory()->create(['project_id' => $project->id]);

        $minute = app(DeliveryMinuteService::class)->generateFromDelivery($voucher, 'handover notes');

        $this->assertSame($project->id, $minute->project_id);
        $this->assertSame($voucher->id, $minute->delivery_voucher_id);
        $this->assertSame($voucher->customer_id, $minute->customer_id);
        $this->assertStringStartsWith('DM-', $minute->minute_number);
        $this->assertSame('handover notes', $minute->content);
        $this->assertFalse($minute->isDistributed());
    }

    public function test_minute_numbers_are_unique(): void
    {
        $a = DeliveryMinute::factory()->create();
        $b = DeliveryMinute::factory()->create();

        $this->assertNotSame($a->minute_number, $b->minute_number);
        $this->assertStringStartsWith('DM-', $a->minute_number);
        $this->assertStringStartsWith('DM-', $b->minute_number);
    }

    public function test_distribute_notifies_departments_and_is_idempotent(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $tech = User::factory()->create();
        $tech->assignRole('Technical_Office');

        $minute = DeliveryMinute::factory()->create();
        $service = app(DeliveryMinuteService::class);

        $service->distribute($minute);

        $this->assertTrue($minute->fresh()->isDistributed());
        $this->assertSame(1, $tech->fresh()->notifications()->count());

        // Distributing again must not re-notify.
        $service->distribute($minute->fresh());
        $this->assertSame(1, $tech->fresh()->notifications()->count());
    }

    public function test_distribute_is_gated_by_policy(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $minute = DeliveryMinute::factory()->create();

        $manager = User::factory()->create();
        $manager->assignRole('General_Manager');
        $this->assertTrue($manager->can('distribute', $minute));

        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->assertFalse($sales->can('distribute', $minute));
    }
}
