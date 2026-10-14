<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Busway product line (الطراز): PT power, DT distribution, LT lighting. */
enum ProductLine: string implements HasLabel
{
    case PT = 'pt';
    case DT = 'dt';
    case LT = 'lt';

    public function getLabel(): string
    {
        return __('resources.enums.product_line.' . $this->value);
    }
}
