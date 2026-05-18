<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UnitOfMeasure: string implements HasLabel
{
    case Piece = 'pcs';
    case Kilogram = 'kg';
    case Meter = 'm';
    case SquareMeter = 'm2';
    case Liter = 'liter';
    case Set = 'set';
    case Roll = 'roll';
    case Sheet = 'sheet';
    case Box = 'box';
    case Ton = 'ton';

    public function getLabel(): string
    {
        return __('resources.enums.unit_of_measure.' . $this->value);
    }
}
