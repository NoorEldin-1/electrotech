<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\IssueVoucherService;
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
     * Execute the job: create an issue voucher from the work order's BOM and
     * post it (transfer raw → WIP + load cost onto the project). This is the
     * async equivalent of issuing materials through the warehouse UI.
     */
    public function handle(IssueVoucherService $issueVoucherService): void
    {
        Log::info("Starting material issuance for WO #{$this->workOrder->wo_number}");

        try {
            if (! $this->workOrder->materials()->exists() && ! $this->workOrder->bom) {
                Log::warning("Work Order #{$this->workOrder->wo_number} has no materials or linked BOM. Job aborted.");
                return;
            }

            $voucher = $issueVoucherService->createFromWorkOrder($this->workOrder);
            $issueVoucherService->post($voucher);

            Log::info("Successfully issued materials for WO #{$this->workOrder->wo_number} via {$voucher->voucher_number}.");
        } catch (\Throwable $e) {
            Log::error("Failed to issue materials for WO #{$this->workOrder->wo_number}. Error: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            // Re-throw to trigger the backoff/retry mechanism if retries remain
            throw $e;
        }
    }
}
