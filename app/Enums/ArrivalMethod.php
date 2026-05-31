<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * "طريقة ورود العملية" — how an operation reached the Sales team.
 * Captured at intake (Slide 2 of Sales_Department.md) so we can later
 * analyse which channels convert best into Active operations.
 */
enum ArrivalMethod: string implements HasLabel
{
    case DirectClient = 'direct_client';
    case ConsultantReferral = 'consultant_referral';
    case TenderInvitation = 'tender_invitation';
    case Website = 'website';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('resources.enums.arrival_method.' . $this->value);
    }
}
