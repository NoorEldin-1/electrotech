<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Classification of a chart-of-accounts entry (تصنيف الحساب). Drives the
 * default natural balance side and how the account is grouped in the trial
 * balance (ميزان المراجعة):
 *   - asset / expense   → natural debit  (مدين)
 *   - liability / equity / revenue → natural credit (دائن)
 */
enum AccountType: string implements HasLabel, HasColor
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function getLabel(): string
    {
        return __('resources.enums.account_type.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Asset => 'info',
            self::Liability => 'warning',
            self::Equity => 'primary',
            self::Revenue => 'success',
            self::Expense => 'danger',
        };
    }

    /**
     * The natural balance side for this account type.
     */
    public function naturalDirection(): AccountDirection
    {
        return match ($this) {
            self::Asset, self::Expense => AccountDirection::Debit,
            self::Liability, self::Equity, self::Revenue => AccountDirection::Credit,
        };
    }
}
