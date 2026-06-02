<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ClaimStatus: string implements HasLabel, HasColor
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Collected = 'collected';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('resources.enums.claim_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::Collected => 'success',
            self::Cancelled => 'danger',
        };
    }
}
