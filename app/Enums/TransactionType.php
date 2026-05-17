<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TransactionType: string implements HasLabel, HasColor
{
    case In = 'in';
    case Out = 'out';
    case Hold = 'hold';
    case Release = 'release';

    public function getLabel(): string
    {
        return match ($this) {
            self::In => 'Stock In',
            self::Out => 'Stock Out',
            self::Hold => 'Hold/Reserve',
            self::Release => 'Release',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::In => 'success',
            self::Out => 'danger',
            self::Hold => 'warning',
            self::Release => 'info',
        };
    }
}
