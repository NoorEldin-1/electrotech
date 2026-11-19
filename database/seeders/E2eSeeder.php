<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttachmentCategory;
use App\Enums\BomStatus;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\ItemType;
use App\Enums\WorkOrderStatus;
use App\Models\Attachment;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\Item;
use App\Models\Project;
use App\Models\QualitySheet;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\Concerns\GuardsE2eEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic data for the Playwright suite in tests/e2e (CI only).
 *
 * It builds on the real seeders (permissions + roles, chart of accounts, the
 * demo users) and on the model factories, and fixes every value a spec reads:
 * codes, names, quantities and e-mails never depend on faker's random output.
 *
 * Safe to run twice (everything is looked up by a stable key first), and it
 * refuses to run anywhere but a local / testing environment on sqlite (see
 * GuardsE2eEnvironment): it creates users with a known password.
 *
 * What it leaves behind:
 *  - one user per role the specs need (password: "password"), on top of the
 *    DemoUserSeeder accounts (admin, sales, sales.manager, factory, ...),
 *  - three active operations for the completion gate: blocked, ready, and
 *    "step" (blocked only by a draft delivery voucher — a spec activates it),
 *  - one operation for the engineer-fields spec,
 *  - one operation for the customer-communication spec,
 *  - one operation holding restricted and unrestricted attachments.
 *
 * The inventory chain is built by E2eInventoryChainSeeder, which the chain
 * spec runs itself so that a failure there stays inside that one spec.
 */
class E2eSeeder extends Seeder
{
    use GuardsE2eEnvironment;

    public const PASSWORD = 'password';

    public const CUSTOMER_NAME = 'E2E Customer Co';

    /**
     * email => [name, role]. Mirrors tests/e2e/support/users.ts.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const USERS = [
        'finance@e2e.test' => ['Finance E2E', 'Finance'],
        'general.manager@e2e.test' => ['General Manager E2E', 'General_Manager'],
        'asmaa@e2e.test' => ['Asmaa Timeline Coordinator', 'Timeline_Coordinator'],
        'sales.officer@e2e.test' => ['Sales Officer E2E', 'Sales_Officer'],
        'quality@e2e.test' => ['Quality Inspector E2E', 'Quality_Inspector'],
        'ledger@e2e.test' => ['Ledger Viewer E2E', 'Ledger_Viewer'],
    ];

    public function run(): void
    {
        // Known password, fixed data: local / testing on sqlite only.
        $this->guardE2eEnvironment();

        // Same random sequence on every run: whatever a factory still draws
        // from faker (descriptions, phone numbers) is identical build to build.
        fake()->seed(20261119);

        $this->call([
            RoleAndPermissionSeeder::class,
            ChartOfAccountsSeeder::class,
            DemoUserSeeder::class,
        ]);

        $this->seedUsers();

        $admin = User::query()->where('email', 'admin@electrotech.com')->firstOrFail();
        $customer = Customer::query()->firstOrCreate(
            ['name' => self::CUSTOMER_NAME],
            ['contact_person' => 'E2E Contact', 'phone' => '0100000000', 'email' => 'customer@e2e.test', 'address' => 'Cairo'],
        );

        $this->seedGateOperations($admin, $customer);
        $this->seedEngineerOperation($admin, $customer);
        $this->seedCommunicationOperation($admin, $customer);
        $this->seedDrawingsOperation($admin, $customer);

        $this->writeProjectMap();
    }

    /**
     * code => id of every seeded operation, for the specs (their URLs and API
     * calls need the id, and ids depend on what ran before this seeder).
     */
    private function writeProjectMap(): void
    {
        $map = Project::query()
            ->where('code', 'like', 'E2E-%')
            ->orderBy('id')
            ->pluck('id', 'code')
            ->all();

        $path = storage_path('app/e2e/projects.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($map, JSON_PRETTY_PRINT));
    }

    private function seedUsers(): void
    {
        foreach (self::USERS as $email => [$name, $role]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make(self::PASSWORD)],
            );
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$role]);
        }
    }

    // ------------------------------------------------------------------ gate

    private function seedGateOperations(User $admin, Customer $customer): void
    {
        // Already seeded (second run): the sheets and vouchers below carry
        // fixed numbers, so building them again would collide.
        if (Project::query()->where('code', 'E2E-GATE-STEP')->exists()) {
            return;
        }

        // 1. Blocked on everything: the order is still being manufactured, it
        //    has no quality sheet and nothing has been delivered.
        $blocked = $this->operation($admin, $customer, 'E2E-GATE-BLOCKED', 'Gate blocked operation');
        $this->order($admin, $blocked, 'WO-E2E-GATE-BLOCKED', WorkOrderStatus::InProgress, finished: false);

        // 2. Ready: finished order, approved quality sheet, activated delivery.
        $ready = $this->operation($admin, $customer, 'E2E-GATE-READY', 'Gate ready operation');
        $readyItem = $this->finishedGood('E2E-FG-READY', 'E2E ready product');
        $readyOrder = $this->order($admin, $ready, 'WO-E2E-GATE-READY', WorkOrderStatus::Completed, finished: true, output: $readyItem, quantity: 10);
        QualitySheet::factory()->approved()->create(['work_order_id' => $readyOrder->id, 'sheet_number' => 'QS-E2E-READY']);
        $this->delivery($customer, $ready, $readyItem, 10, DeliveryVoucherStatus::Active, 'DV-E2E-READY');

        // 3. Step: manufactured and inspected, delivery voucher still a draft.
        //    The spec activates it with E2eActivateDeliverySeeder.
        $step = $this->operation($admin, $customer, 'E2E-GATE-STEP', 'Gate step operation');
        $stepItem = $this->finishedGood('E2E-FG-STEP', 'E2E step product');
        $stepOrder = $this->order($admin, $step, 'WO-E2E-GATE-STEP', WorkOrderStatus::Completed, finished: true, output: $stepItem, quantity: 10);
        QualitySheet::factory()->approved()->create(['work_order_id' => $stepOrder->id, 'sheet_number' => 'QS-E2E-STEP']);
        $this->delivery($customer, $step, $stepItem, 10, DeliveryVoucherStatus::Draft, 'DV-E2E-STEP');
    }

    // ------------------------------------------------------ engineer / calls

    private function seedEngineerOperation(User $admin, Customer $customer): void
    {
        if (Project::query()->where('code', 'E2E-ENGINEER')->exists()) {
            return;
        }

        Project::factory()->tender()->create([
            'code' => 'E2E-ENGINEER',
            'name' => 'E2E engineer fields operation',
            'client_name' => $customer->name,
            'customer_id' => $customer->id,
            'engineer_name' => 'Eng. Initial',
            'created_by' => $admin->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
    }

    private function seedCommunicationOperation(User $admin, Customer $customer): void
    {
        $this->operation($admin, $customer, 'E2E-CALLS', 'E2E customer calls operation');
    }

    // --------------------------------------------------------------- drawings

    private function seedDrawingsOperation(User $admin, Customer $customer): void
    {
        $project = $this->operation($admin, $customer, 'E2E-DRAWINGS', 'E2E drawings operation');

        $files = [
            [AttachmentCategory::PreliminaryDrawing, 'e2e-preliminary-drawing.pdf'],
            [AttachmentCategory::ExecutiveDrawing, 'e2e-executive-drawing.pdf'],
            [AttachmentCategory::SiteMeasurement, 'e2e-site-measurement.pdf'],
            [AttachmentCategory::Boq, 'e2e-boq.pdf'],
        ];

        foreach ($files as [$category, $name]) {
            $path = 'attachments/e2e/'.$name;

            if (Attachment::query()->where('project_id', $project->id)->where('file_name', $name)->exists()) {
                continue;
            }

            // A real (tiny) file, so a permitted download streams something. It
            // goes where the app itself would put this category: the private
            // `restricted` disk for the three drawings (no public URL), the
            // public disk for the BOQ.
            Storage::disk($category->storageDisk())->put($path, "%PDF-1.4\n% e2e fixture {$name}\n");

            Attachment::query()->create([
                'project_id' => $project->id,
                'file_name' => $name,
                'file_path' => $path,
                'file_type' => 'application/pdf',
                'file_size' => 64,
                'category' => $category->value,
                'uploaded_by' => $admin->id,
            ]);
        }
    }

    // ---------------------------------------------------------------- builders

    private function operation(User $admin, Customer $customer, string $code, string $name): Project
    {
        $existing = Project::query()->where('code', $code)->first();

        if ($existing !== null) {
            return $existing;
        }

        return Project::factory()->active()->create([
            'code' => $code,
            'name' => $name,
            'client_name' => $customer->name,
            'customer_id' => $customer->id,
            'engineer_name' => 'Eng. Fixed',
            'created_by' => $admin->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'actual_cost' => 0,
        ]);
    }

    private function finishedGood(string $sku, string $name): Item
    {
        return Item::query()->where('sku', $sku)->first()
            ?? Item::factory()->create([
                'sku' => $sku,
                'name' => $name,
                'type' => ItemType::FinishedGood,
                'unit_cost' => 100,
            ]);
    }

    private function order(
        User $admin,
        Project $project,
        string $number,
        WorkOrderStatus $status,
        bool $finished,
        ?Item $output = null,
        float $quantity = 10,
    ): WorkOrder {
        $existing = WorkOrder::query()->where('wo_number', $number)->first();

        if ($existing !== null) {
            return $existing;
        }

        $bom = Bom::factory()->create([
            'project_id' => $project->id,
            'status' => BomStatus::Approved,
            'prepared_by' => $admin->id,
            'approved_by' => $admin->id,
        ]);

        $order = WorkOrder::factory()->create([
            'project_id' => $project->id,
            'bom_id' => $bom->id,
            'wo_number' => $number,
            'title' => 'E2E order '.$number,
            'status' => $status,
            'planned_quantity' => $quantity,
            'actual_start_date' => now()->subDays(2),
            'manufacturing_finished_at' => $finished ? now()->subDay() : null,
            'order_approved_by' => $admin->id,
            'order_approved_at' => now()->subDays(2),
            'qa_approved_by' => $admin->id,
            'qa_approved_at' => now()->subDays(2),
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
        ]);

        if ($output !== null) {
            $order->outputs()->create(['item_id' => $output->id, 'planned_quantity' => $quantity]);
        }

        return $order;
    }

    private function delivery(Customer $customer, Project $project, Item $item, float $quantity, DeliveryVoucherStatus $status, string $number): DeliveryVoucher
    {
        $voucher = DeliveryVoucher::factory()->create([
            'voucher_number' => $number,
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'voucher_date' => '2026-06-01',
            'status' => $status,
            'total_value' => $status === DeliveryVoucherStatus::Active ? $quantity * 100 : 0,
            'activated_at' => $status === DeliveryVoucherStatus::Active ? now() : null,
        ]);

        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => 100]);

        return $voucher;
    }
}
