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
     *
     * Routed onto a dedicated 'materials' queue so a long BOM loop cannot
     * starve activity-log writes, dashboard refreshes, or other default-
     * queue work. Run a worker with:
     *   php artisan queue:work redis --queue=materials,default --tries=3
     */
    public function __construct(
        public readonly WorkOrder $workOrder,
    ) {
        $this->onQueue('materials');
    }

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

            // Stream BOM items via lazy cursor so memory stays constant
            // even for very large BOMs. The chunked cursor still eager-
            // loads `item` per chunk (default 1000), preventing N+1
            // without hydrating all rows up front.
            $bom->items()
                ->with('item')
                ->lazyById(200)
                ->each(function ($bomItem) use ($inventoryService) {
                    $totalRequired = (float) $bomItem->total_required_quantity;

                    if ($totalRequired <= 0) {
                        return;
                    }

                    // InventoryService utilizes Redis Atomic Locks, ensuring this background
                    // processing doesn't collide with live user interactions on the same items.
                    $inventoryService->deductStock(
                        item: $bomItem->item,
                        quantity: $totalRequired,
                        reference: $this->workOrder,
                        notes: "Issued for WO #{$this->workOrder->wo_number} (BOM item incl. {$bomItem->waste_percentage}% waste)",
                    );
                });

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
