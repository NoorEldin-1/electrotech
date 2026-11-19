<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum InteractionChannel: string implements HasIcon, HasLabel
{
    case Call = 'call';
    case Visit = 'visit';
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    // التواصل مع العميل (Final_Modifications_Plan 1.1): call / meeting / message.
    case Meeting = 'meeting';
    case Message = 'message';

    public function getLabel(): string
    {
        return __('resources.enums.interaction_channel.'.$this->value);
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Call => 'heroicon-o-phone',
            self::Visit => 'heroicon-o-truck',
            self::Whatsapp => 'heroicon-o-chat-bubble-oval-left',
            self::Email => 'heroicon-o-envelope',
            self::Meeting => 'heroicon-o-user-group',
            self::Message => 'heroicon-o-chat-bubble-left-ellipsis',
        };
    }
}
