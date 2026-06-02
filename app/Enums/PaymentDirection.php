<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Direction of an operation cash movement (سلايد 1: الدفعات النقدية).
 *   - incoming: money received (مقبوض) — e.g. a customer payment.
 *   - outgoing: money paid out (مدفوع).
 */
enum PaymentDirection: string implements HasLabel, HasColor
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';

    public function getLabel(): string
    {
        return __('resources.enums.payment_direction.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Incoming => 'success',
            self::Outgoing => 'danger',
        };
    }
}
