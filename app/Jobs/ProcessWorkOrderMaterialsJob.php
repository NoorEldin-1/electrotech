<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\InventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWorkOrderMaterialsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Allows Redis to automatically retry transient lock timeout failures.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     * Gradual backoff strategy to reduce contention.
     */
    public array $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly WorkOrder $workOrder,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(InventoryService $inventoryService): void
    {
        Log::info("Starting material issuance for WO #{$this->workOrder->wo_number}");

        try {
            $bom = $this->workOrder->bom;

            if (! $bom) {
                Log::warning("Work Order #{$this->workOrder->wo_number} has no linked BOM. Job aborted.");
                return;
            }

            // Eager load items to prevent N+1 queries during the loop
            $bomItems = $bom->items()->with('item')->get();

            foreach ($bomItems as $bomItem) {
                $totalRequired = (float) $bomItem->total_required_quantity;

                if ($totalRequired <= 0) {
                    continue;
                }

                // InventoryService utilizes Redis Atomic Locks, ensuring this background
                // processing doesn't collide with live user interactions on the same items.
                $inventoryService->deductStock(
                    item: $bomItem->item,
                    quantity: $totalRequired,
                    reference: $this->workOrder,
                    notes: "Issued for WO #{$this->workOrder->wo_number} (BOM item incl. {$bomItem->waste_percentage}% waste)",
                );
            }

            Log::info("Successfully issued materials for WO #{$this->workOrder->wo_number}.");
        } catch (\Throwable $e) {
            Log::error("Failed to issue materials for WO #{$this->workOrder->wo_number}. Error: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            // Re-throw to trigger the backoff/retry mechanism if retries remain
            throw $e;
        }
    }
}
