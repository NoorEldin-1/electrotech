<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * نوع التفاعل مع العميل (Presentation.pptx سلايد 6–7). `Contact` and
 * `SiteReadiness` are timeline signals; `FollowUp` is a plain log entry.
 */
enum InteractionType: string implements HasColor, HasIcon, HasLabel
{
    case Contact = 'contact';
    case SiteReadiness = 'site_readiness';
    case FollowUp = 'follow_up';

    public function getLabel(): string
    {
        return __('resources.enums.interaction_type.'.$this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Contact => 'info',
            self::SiteReadiness => 'success',
            self::FollowUp => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Contact => 'heroicon-o-phone',
            self::SiteReadiness => 'heroicon-o-map-pin',
            self::FollowUp => 'heroicon-o-chat-bubble-left-right',
        };
    }
}
