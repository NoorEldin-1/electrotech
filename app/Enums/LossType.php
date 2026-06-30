<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Classification of manufacturing loss for a depreciation voucher (التصنيع
 * سلايدات 5–6). Drives how post() treats the operation cost and which loss
 * account the journal carries:
 *   - natural (الهالك الطبيعي): cutting filings/برادة — stays loaded on the
 *     operation; booked to operating expenses.
 *   - abnormal (الهالك الغير طبيعي): a defective full strip — reversed off the
 *     operation; booked to the manufacturing-loss account.
 *
 * (Scrap / الفضلات is a third loss kind but is handled separately by the
 * Return Voucher — it is returnable stock, not a write-off.)
 */
enum LossType: string implements HasLabel, HasColor
{
    case Natural = 'natural';
    case Abnormal = 'abnormal';

    public function getLabel(): string
    {
        return __('resources.enums.loss_type.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Natural => 'warning',
            self::Abnormal => 'danger',
        };
    }
}
