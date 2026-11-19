<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DeliveryVoucherStatus;
use App\Models\DeliveryVoucher;
use App\Models\Project;
use Database\Seeders\Concerns\GuardsE2eEnvironment;
use Illuminate\Database\Seeder;

/**
 * One step for tests/e2e/specs/01-completion-gate.spec.ts: activates the
 * delivery voucher of the "E2E-GATE-STEP" operation, which was the only thing
 * still blocking its completion. The spec asserts that confirming "Complete"
 * is refused (the modal names the missing delivery) before this runs, and goes
 * through after it.
 *
 * It flips the voucher straight to Active on purpose — the gate looks at the
 * voucher's status and lines, and the delivery flow itself is exercised by the
 * inventory chain spec.
 */
class E2eActivateDeliverySeeder extends Seeder
{
    use GuardsE2eEnvironment;

    public function run(): void
    {
        $this->guardE2eEnvironment();

        $project = Project::query()->where('code', 'E2E-GATE-STEP')->firstOrFail();

        DeliveryVoucher::query()
            ->where('project_id', $project->id)
            ->get()
            ->each(fn (DeliveryVoucher $voucher) => $voucher->update([
                'status' => DeliveryVoucherStatus::Active,
                'total_value' => 1000,
                'activated_at' => now(),
            ]));
    }
}
