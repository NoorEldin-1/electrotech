<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Surface treatment (المعالجة): بدون / مدهون / منحس / مقصدر. */
enum ItemTreatment: string implements HasLabel
{
    case None = 'none';
    case Painted = 'painted';
    case CopperCoated = 'copper_coated';
    case Tinned = 'tinned';

    public function getLabel(): string
    {
        return __('resources.enums.item_treatment.' . $this->value);
    }
}
