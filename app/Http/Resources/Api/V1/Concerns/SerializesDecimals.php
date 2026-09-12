<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Concerns;

/**
 * Money and quantity serialization for API resources.
 *
 * API_Development_Plan.md §3.10 requires every decimal to leave the server as
 * a JSON *string*. The reason is not stylistic: the columns are
 * `decimal(x, 2)` / `decimal(x, 4)`, and a JSON number is parsed by Dart as a
 * binary double. `0.1 + 0.2` is not `0.3` in a double, so a purchase order's
 * total re-summed on the client would drift from the one the ledger holds —
 * by cents at first, and by visible amounts once a report aggregates
 * thousands of lines. A string hands the client the exact decimal the
 * database stores; Flutter parses it with `Decimal.parse()`.
 *
 * `null` stays `null` — an empty amount is not zero, and flattening the two
 * would make "no price entered yet" indistinguishable from "free".
 */
trait SerializesDecimals
{
    /**
     * A money amount — always 2 decimal places, matching `decimal:2`.
     */
    protected function money(mixed $value): ?string
    {
        return $this->decimalString($value, 2);
    }

    /**
     * A quantity — 4 decimal places, matching `decimal:4`. Quantities carry
     * more precision than money because a BOM line can legitimately need
     * 0.0125 of a roll.
     */
    protected function quantity(mixed $value): ?string
    {
        return $this->decimalString($value, 4);
    }

    protected function decimalString(mixed $value, int $precision): ?string
    {
        return $value === null ? null : number_format((float) $value, $precision, '.', '');
    }
}
