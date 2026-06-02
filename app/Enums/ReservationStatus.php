<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReservationStatus: string implements HasLabel, HasColor
{
    case Active = 'active';
    case Released = 'released';

    public function getLabel(): string
    {
        return __('resources.enums.reservation_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Active => 'warning',
            self::Released => 'gray',
        };
    }
}
