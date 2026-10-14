<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Busbar edge profile (Edge): R rounded, F flat. */
enum ItemEdge: string implements HasLabel
{
    case Rounded = 'rounded';
    case Flat = 'flat';

    public function getLabel(): string
    {
        return __('resources.enums.item_edge.' . $this->value);
    }
}
