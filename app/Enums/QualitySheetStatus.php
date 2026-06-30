<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a quality sheet (ورقة الجودة): a draft is created (usually when
 * manufacturing finishes), the QA department fills the test results and signs
 * (QaFilled), then the factory manager gives final approval (Approved).
 */
enum QualitySheetStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft = 'draft';
    case QaFilled = 'qa_filled';
    case Approved = 'approved';

    public function getLabel(): string
    {
        return __('resources.enums.quality_sheet_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::QaFilled => 'warning',
            self::Approved => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil-square',
            self::QaFilled => 'heroicon-o-clipboard-document-check',
            self::Approved => 'heroicon-o-check-badge',
        };
    }
}
