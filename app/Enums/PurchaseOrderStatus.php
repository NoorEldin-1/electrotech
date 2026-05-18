<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PurchaseOrderStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('resources.enums.purchase_order_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'info',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil-square',
            self::Submitted => 'heroicon-o-paper-airplane',
            self::PartiallyReceived => 'heroicon-o-truck',
            self::Received => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }
}
