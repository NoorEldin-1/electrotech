<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SalesAlertService;
use Illuminate\Console\Command;

/**
 * Slide 5 / 11: keep the topbar bell in step with the live pipeline state —
 * a red error alert per Tender operation with no priced offer and per In-Hand
 * operation with no SMB, each cleared automatically once the gap is filled.
 *
 * The alerts are also reconciled on every pipeline transition; this scheduled
 * pass is the safety net that catches anything edited outside those paths.
 */
class NotifyIncompleteOperations extends Command
{
    protected $signature = 'sales:notify-incomplete-operations';

    protected $description = 'Reconcile the bell alerts for pipeline operations missing an offer or an SMB.';

    public function handle(SalesAlertService $alerts): int
    {
        $alerts->reconcileOperationAlerts();

        $this->info('Operation alerts reconciled.');

        return self::SUCCESS;
    }
}
