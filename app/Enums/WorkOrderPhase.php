<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * أي طلب تصنيع هذا على الخط الزمني (Presentation.pptx سلايد 4): the deck has
 * two manufacturing orders per operation — one after the preliminary drawing
 * is approved and one after the shop (executive) drawing is approved.
 */
enum WorkOrderPhase: string implements HasColor, HasLabel
{
    case Preliminary = 'preliminary';
    case Executive = 'executive';

    public function getLabel(): string
    {
        return __('resources.enums.work_order_phase.'.$this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Preliminary => 'info',
            self::Executive => 'primary',
        };
    }
}
