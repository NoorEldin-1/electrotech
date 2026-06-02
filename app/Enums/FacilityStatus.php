<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FacilityStatus: string implements HasLabel, HasColor
{
    case Active = 'active';
    case Expired = 'expired';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return __('resources.enums.facility_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Active => 'success',
            self::Expired => 'warning',
            self::Closed => 'gray',
        };
    }
}
