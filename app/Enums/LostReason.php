<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Why a Sales operation was cancelled, captured when it lands on the
 * Lost List (Slide 4 of Sales_Department.md). The structured reason
 * powers year-end analytics ("what fraction of losses are price-driven").
 */
enum LostReason: string implements HasLabel, HasColor
{
    case HighPrice = 'high_price';
    case NotApprovedByConsultant = 'not_approved_by_consultant';
    case ElectricityCompanyApproval = 'electricity_company_approval';
    case PaymentFacilities = 'payment_facilities';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('resources.enums.lost_reason.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::HighPrice => 'danger',
            self::NotApprovedByConsultant => 'warning',
            self::ElectricityCompanyApproval => 'info',
            self::PaymentFacilities => 'gray',
            self::Other => 'gray',
        };
    }
}
