<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a journal entry (قيد يومية):
 *   draft  → editable, NOT reflected in the ledger / trial balance.
 *   posted → committed; its lines appear in the ledgers and trial balance and
 *            the entry becomes immutable (corrections go via a settlement entry).
 */
enum JournalStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function getLabel(): string
    {
        return __('resources.enums.journal_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Posted => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil-square',
            self::Posted => 'heroicon-o-check-circle',
        };
    }
}
