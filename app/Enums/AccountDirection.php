<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Direction of an account ledger entry. Stored amounts are signed so that
 * SUM(amount) is the party's natural running balance:
 *   - Supplier credit (we received goods) → we owe more  → positive
 *   - Customer  debit  (we delivered goods) → they owe more → positive
 */
enum AccountDirection: string implements HasLabel, HasColor
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function getLabel(): string
    {
        return __('resources.enums.account_direction.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Debit => 'danger',
            self::Credit => 'success',
        };
    }
}
