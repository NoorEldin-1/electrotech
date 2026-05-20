<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Cache;

class PurchaseOrderObserver
{
    /**
     * Handle the PurchaseOrder "saved" event.
     */
    public function saved(PurchaseOrder $purchaseOrder): void
    {
        Cache::forget('dashboard:pending_pos');
    }

    /**
     * Handle the PurchaseOrder "deleted" event.
     */
    public function deleted(PurchaseOrder $purchaseOrder): void
    {
        Cache::forget('dashboard:pending_pos');
    }
}
