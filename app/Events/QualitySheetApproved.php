<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\QualitySheet;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the factory manager gives final approval to a quality sheet
 * (التصنيع سلايد 3) — QA passed and the manager signed off. Listeners notify
 * every department that the operation has finished manufacturing ("تنبيه لجميع
 * الأقسام أن العملية تم الانتهاء من تصنيعها"). Mirrors ManufacturingFinished but
 * is a distinct, later signal: this one is the formal QA sign-off.
 */
class QualitySheetApproved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public QualitySheet $qualitySheet)
    {
    }
}
