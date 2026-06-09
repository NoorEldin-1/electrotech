<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

/**
 * EGP money input with live thousands separators (e.g. 1,234.56) — Slide 1
 * of the Sales modifications ("فاصلة بعد كل ثلاث أرقام وفاصلة للعلامات العشرية").
 *
 * The `$money` JS mask formats the value while the user types; the grouping
 * commas are then stripped on dehydrate via stripCharacters(',') so the value
 * persisted to the DB stays a clean decimal the `decimal:2` cast can store.
 *
 * Reuse this anywhere a monetary amount is entered (project budget/cost, offer
 * amounts, …) so the formatting stays identical across the whole Sales module.
 */
final class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->prefix('EGP')
            ->mask(RawJs::make('$money($input)'))
            ->stripCharacters(',');
    }
}
