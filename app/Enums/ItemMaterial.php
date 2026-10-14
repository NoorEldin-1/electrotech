<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Conductor/body material (Mat.) of an item. */
enum ItemMaterial: string implements HasLabel
{
    case Copper = 'cu';
    case Aluminium = 'al';
    case Iron = 'fe';

    public function getLabel(): string
    {
        return __('resources.enums.item_material.' . $this->value);
    }
}
