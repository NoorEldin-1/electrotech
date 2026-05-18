<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ItemType: string implements HasLabel, HasColor
{
    case RawMaterial = 'raw_material';
    case FinishedGood = 'finished_good';
    case SemiFinished = 'semi_finished';
    case Consumable = 'consumable';

    public function getLabel(): string
    {
        return __('resources.enums.item_type.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::RawMaterial => 'info',
            self::FinishedGood => 'success',
            self::SemiFinished => 'warning',
            self::Consumable => 'gray',
        };
    }
}
