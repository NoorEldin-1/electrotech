<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ProjectOffer;
use App\Services\SalesAlertService;

/**
 * Slide 11: the moment a financial offer is attached to a pipeline operation,
 * notify Sales (with the running offer count). Kept in an observer so it fires
 * regardless of how the offer was created (relation manager, factory, API).
 */
class ProjectOfferObserver
{
    public function created(ProjectOffer $offer): void
    {
        app(SalesAlertService::class)->notifyOfferAttached($offer);
    }
}
