<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ProjectStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Tender = 'tender';
    case InHand = 'in_hand';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return __('resources.enums.project_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingReview => 'warning',
            self::Approved => 'info',
            self::Tender => 'info',
            self::InHand => 'warning',
            self::InProgress => 'primary',
            self::OnHold => 'danger',
            self::Completed => 'success',
            self::Cancelled => 'danger',
            self::Lost => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil-square',
            self::PendingReview => 'heroicon-o-clock',
            self::Approved => 'heroicon-o-check-badge',
            self::Tender => 'heroicon-o-document-magnifying-glass',
            self::InHand => 'heroicon-o-hand-raised',
            self::InProgress => 'heroicon-o-play',
            self::OnHold => 'heroicon-o-pause',
            self::Completed => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
            self::Lost => 'heroicon-o-no-symbol',
        };
    }
}
