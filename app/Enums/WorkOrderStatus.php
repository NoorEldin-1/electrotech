<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum WorkOrderStatus: string implements HasLabel, HasColor, HasIcon
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case QaReview = 'qa_review';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('resources.enums.work_order_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'info',
            self::QaReview => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::InProgress => 'heroicon-o-cog-6-tooth',
            self::QaReview => 'heroicon-o-shield-check',
            self::Completed => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }
}
