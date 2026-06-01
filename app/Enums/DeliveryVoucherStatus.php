<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * إذن تسليم lifecycle. The voucher only becomes `active` — moving stock and
 * posting to the customer account — once BOTH the technical and financial
 * managers have signed it (الاعتماد المزدوج, slide 10).
 */
enum DeliveryVoucherStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('resources.enums.delivery_voucher_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingApproval => 'warning',
            self::Active => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil-square',
            self::PendingApproval => 'heroicon-o-clock',
            self::Active => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }
}
