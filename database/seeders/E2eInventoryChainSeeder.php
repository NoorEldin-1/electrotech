<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BomStatus;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\ItemType;
use App\Enums\VoucherStatus;
use App\Enums\WorkOrderStatus;
use App\Models\AdditionVoucher;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\Item;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AdditionVoucherService;
use App\Services\DeliveryVoucherService;
use App\Services\IssueVoucherService;
use App\Services\WorkOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The inventory chain of the Final_Modifications plan (Tasks 6-8), driven
 * through the REAL services so the automatic journals fire exactly as in the
 * app — purchase, issue, finish, deliver — and the accounting UI can then be
 * checked against it. Run by tests/e2e/specs/05-inventory-chain.spec.ts (not by
 * E2eSeeder), so a failure here stays inside that one spec.
 *
 *   purchase   100 x raw material @ 50                          = 5000
 *   operation X  issue 40 (2000), manufacturing finished
 *   operation Y  issue 20 (1000), still being manufactured
 *   operation Z  issue 10 (500), finished, completed, 5 delivered
 *
 * Expected, if the plan is implemented: 1310 = 1500, 1320 = 1000 (Y only),
 * and 1330 holds X's 2000 (Z's cost went to cost of goods sold on delivery).
 * Only the two figures that follow from the issues alone are asserted as
 * absolutes by the spec; everything else is compared with the ledger snapshot
 * this seeder writes to storage/app/e2e/chain.json.
 *
 * Idempotent: a second run (Playwright retry) only rewrites the snapshot.
 */
class E2eInventoryChainSeeder extends Seeder
{
    public const SNAPSHOT = 'e2e/chain.json';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('E2eInventoryChainSeeder must never run in production.');
        }

        if (Item::query()->where('sku', 'E2E-RAW-COPPER')->exists()) {
            $this->writeSnapshot();

            return;
        }

        fake()->seed(20261120);

        $admin = User::query()->where('email', 'admin@electrotech.com')->firstOrFail();
        // Stock movements and vouchers stamp the acting user.
        Auth::setUser($admin);

        $customer = Customer::query()->firstOrCreate(
            ['name' => E2eSeeder::CUSTOMER_NAME],
            ['contact_person' => 'E2E Contact', 'phone' => '0100000000', 'email' => 'customer@e2e.test', 'address' => 'Cairo'],
        );
        $supplier = Supplier::factory()->create(['name' => 'E2E Chain Supplier']);

        $raw = Item::factory()->create([
            'sku' => 'E2E-RAW-COPPER',
            'name' => 'E2E copper bar',
            'type' => ItemType::RawMaterial,
            'unit_cost' => 50,
        ]);
        $finishedGood = Item::factory()->create([
            'sku' => 'E2E-FG-CHAIN',
            'name' => 'E2E chain product',
            'type' => ItemType::FinishedGood,
            'unit_cost' => 100,
        ]);

        // 1. Purchase: the raw store receives 100 @ 50 against the supplier.
        $receipt = AdditionVoucher::factory()->create([
            'voucher_number' => 'AV-E2E-CHAIN',
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-E2E-CHAIN',
            'invoice_value' => 5000,
            'voucher_date' => now()->toDateString(),
            'status' => VoucherStatus::Draft,
        ]);
        $receipt->lines()->create(['item_id' => $raw->id, 'quantity' => 100, 'unit_cost' => 50]);
        app(AdditionVoucherService::class)->post($receipt);

        // 2. Three operations, each issued material from the raw store.
        $x = $this->operation($admin, $customer, 'E2E-CHAIN-X', 'E2E chain operation X');
        $y = $this->operation($admin, $customer, 'E2E-CHAIN-Y', 'E2E chain operation Y');
        $z = $this->operation($admin, $customer, 'E2E-CHAIN-Z', 'E2E chain operation Z');

        $orderX = $this->started($admin, $x, 'WO-E2E-CHAIN-X', $raw, $finishedGood, materialQuantity: 40, outputQuantity: 40);
        $orderY = $this->started($admin, $y, 'WO-E2E-CHAIN-Y', $raw, $finishedGood, materialQuantity: 20, outputQuantity: 20);
        $orderZ = $this->started($admin, $z, 'WO-E2E-CHAIN-Z', $raw, $finishedGood, materialQuantity: 10, outputQuantity: 5);

        $issues = app(IssueVoucherService::class);
        foreach ([$orderX, $orderY, $orderZ] as $order) {
            $issues->post($issues->createFromWorkOrder($order));
        }

        // 3. X: manufacturing finished -> work in progress becomes finished goods.
        $orders = app(WorkOrderService::class);
        $orders->finishManufacturing($orderX);

        // 4. Z: finished, completed (5 pieces into finished goods), delivered.
        $output = $orderZ->outputs()->firstOrFail();
        $orders->submitForQa($orderZ->fresh(), [
            ['output_id' => $output->id, 'produced_quantity' => 5, 'waste_quantity' => 0],
        ]);
        $orders->finishManufacturing($orderZ->fresh());
        $orders->complete($orderZ->fresh());

        $delivery = DeliveryVoucher::factory()->create([
            'voucher_number' => 'DV-E2E-CHAIN-Z',
            'customer_id' => $customer->id,
            'project_id' => $z->id,
            'voucher_date' => now()->toDateString(),
            'status' => DeliveryVoucherStatus::Draft,
            'total_value' => 0,
        ]);
        $delivery->lines()->create(['item_id' => $finishedGood->id, 'quantity' => 5, 'unit_cost' => 100]);

        $deliveries = app(DeliveryVoucherService::class);
        $deliveries->approveTechnical($delivery, $admin);
        $deliveries->approveFinancial($delivery->fresh(), $admin);

        $this->writeSnapshot();
    }

    private function operation(User $admin, Customer $customer, string $code, string $name): Project
    {
        return Project::factory()->active()->create([
            'code' => $code,
            'name' => $name,
            'client_name' => $customer->name,
            'customer_id' => $customer->id,
            'created_by' => $admin->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'actual_cost' => 0,
        ]);
    }

    /**
     * A manufacturing order that both approvals have cleared and that has
     * started: materials planned from the raw store, one finished product.
     */
    private function started(User $admin, Project $project, string $number, Item $raw, Item $product, float $materialQuantity, float $outputQuantity): WorkOrder
    {
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
            'status' => WorkOrderStatus::InProgress,
            'planned_quantity' => $outputQuantity,
            'actual_start_date' => now()->subHours(3),
            'manufacturing_finished_at' => null,
            'order_approved_by' => $admin->id,
            'order_approved_at' => now()->subHours(4),
            'qa_approved_by' => $admin->id,
            'qa_approved_at' => now()->subHours(4),
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
        ]);

        $order->materials()->create([
            'item_id' => $raw->id,
            'quantity' => $materialQuantity,
            'unit_cost' => 50,
            'is_manual' => true,
        ]);
        $order->outputs()->create(['item_id' => $product->id, 'planned_quantity' => $outputQuantity]);

        return $order;
    }

    /**
     * What the ledger says right now, for the spec to compare the screens with:
     * posted balance (debit - credit) of the inventory accounts and of cost of
     * goods sold, the posted totals (trial balance), and how many entries exist.
     */
    private function writeSnapshot(): void
    {
        $balance = function (string $code): array {
            $row = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('e.status', 'posted')
                ->where('a.code', $code)
                ->selectRaw("COALESCE(SUM(CASE WHEN l.direction = 'debit' THEN l.amount ELSE -l.amount END), 0) AS balance, COUNT(l.id) AS lines")
                ->first();

            return [
                'exists' => DB::table('accounts')->where('code', $code)->exists(),
                'balance' => round((float) ($row->balance ?? 0), 2),
                'lines' => (int) ($row->lines ?? 0),
            ];
        };

        $totals = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')
            ->selectRaw("COALESCE(SUM(CASE WHEN l.direction = 'debit' THEN l.amount ELSE 0 END), 0) AS debit, COALESCE(SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE 0 END), 0) AS credit")
            ->first();

        $snapshot = [
            'accounts' => [
                '1300' => $balance('1300'),
                '1310' => $balance('1310'),
                '1320' => $balance('1320'),
                '1330' => $balance('1330'),
                '5070' => $balance('5070'),
            ],
            'total_debit' => round((float) ($totals->debit ?? 0), 2),
            'total_credit' => round((float) ($totals->credit ?? 0), 2),
            'posted_entries' => (int) DB::table('journal_entries')->where('status', 'posted')->count(),
            'operations' => ['E2E-CHAIN-X', 'E2E-CHAIN-Y', 'E2E-CHAIN-Z'],
            // The only figures that follow from the purchase and the three
            // issues alone, whatever the later steps do.
            'expected' => ['1310' => 1500.0, '1320' => 1000.0],
        ];

        $path = storage_path('app/'.self::SNAPSHOT);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
