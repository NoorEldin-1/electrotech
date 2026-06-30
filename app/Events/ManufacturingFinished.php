<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WorkOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when manufacturing of a work order is marked finished as a whole
 * (التصنيع.pptx سلايد 2) — regardless of the QA stages. Listeners notify every
 * department that the product is ready for delivery ("تنبيه بقية الأقسام بأن
 * المنتج جاهز للتسليم"). Mirrors OperationActivated.
 */
class ManufacturingFinished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public WorkOrder $workOrder)
    {
    }
}
