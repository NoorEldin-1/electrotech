<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an operation (project) is promoted to active (InProgress).
 * Listeners cascade the operation to the departments — سلايد 1:
 * "بعد تنشيط العملية يتم ترحيلها إلى كل الأقسام".
 */
class OperationActivated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Project $project)
    {
    }
}
