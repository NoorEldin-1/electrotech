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

    /**
     * Parse a value that may still carry the live mask's thousands
     * separators (e.g. "5,000") into a float.
     *
     * PHP's (float) cast stops at the first comma — so (float) "5,000" is
     * 5.0. That is exactly why an in-form total preview read the grouped
     * value wrong while the saved / printed figure (computed by OfferItem /
     * OfferTotalsService from the comma-stripped state) was right — the
     * "total is wrong only in the form, correct in print" report of Slide 4.
     * Strip the grouping commas before casting so live previews match the
     * stored figure.
     */
    public static function toFloat(mixed $value): float
    {
        return (float) str_replace(',', '', (string) $value);
    }
}
