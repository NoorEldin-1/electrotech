<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle shared by the simple single-step vouchers (addition & issue):
 *   draft  → created, editable, no stock/financial effect yet
 *   posted → committed; stock moved and the financial entry was written.
 *
 * The delivery voucher has a richer lifecycle of its own (dual approval),
 * see App\Enums\DeliveryVoucherStatus.
 */
enum VoucherStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function getLabel(): string
    {
        return __('resources.enums.voucher_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Posted => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil-square',
            self::Posted => 'heroicon-o-check-circle',
        };
    }
}
