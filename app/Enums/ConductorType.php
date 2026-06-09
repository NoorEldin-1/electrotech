<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * "اسم الموصل" (conductor name) — Slide 4 of the Sales modifications asks
 * to replace the free-text "نوع الفصل" field with a fixed list of the
 * busway/panel conductor materials the company actually quotes:
 * copper, aluminium, or bi-metal (نحاس / ألومنيوم / باي ميتال).
 *
 * The Project.section_type column is intentionally left as a plain string
 * (no model cast) so legacy free-text values written before this change
 * still load without a ValueError; the Select simply shows nothing
 * selected for any value outside this list.
 */
enum ConductorType: string implements HasLabel
{
    case Copper = 'copper';
    case Aluminum = 'aluminum';
    case BiMetal = 'bi_metal';

    public function getLabel(): string
    {
        return __('resources.enums.conductor_type.'.$this->value);
    }
}
