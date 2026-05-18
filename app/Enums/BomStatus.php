<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BomStatus: string implements HasLabel, HasColor
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return __('resources.enums.bom_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingApproval => 'warning',
            self::Approved => 'success',
            self::Superseded => 'danger',
        };
    }
}
