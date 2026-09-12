<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Models\Account;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns a report service's return value into an API-safe payload.
 *
 * The financial statement services were written for Blade views, so their
 * arrays hold live `Account` models, `Carbon` dates, enums and raw floats,
 * nested several levels deep and shaped differently per statement. Writing a
 * `JsonResource` per statement would mean nine resources that each have to be
 * revised whenever a statement gains a line — and the first one that is missed
 * silently leaks a whole Eloquent model into the response.
 *
 * So this walks the structure instead and normalizes by TYPE:
 *
 *  - an `Account` becomes `{id, code, name, type}` — never the whole model,
 *    which would publish its opening balance, its notes and anything else the
 *    table happens to carry;
 *  - any other model becomes `{id, type}`, deliberately thin: a report should
 *    not be a back door to a record the caller could not read directly;
 *  - a `Carbon` becomes a plain date string, because every date in a financial
 *    report is a date, not an instant;
 *  - an enum becomes `{value, label, color}`, the same shape every other
 *    endpoint uses;
 *  - a **float becomes a decimal string**, for the reason in
 *    API_Development_Plan.md §3.10: a JSON number is a binary double in Dart,
 *    and a statement re-summed on the client would drift from the one the
 *    ledger holds.
 *
 * Integers are left alone — a serial, a count or an id is not money, and
 * turning `412` into `"412.00"` would be worse than useless.
 *
 * The trade-off is that the *shape* of each report is the service's, not this
 * class's. That is deliberate: the shape is documented per endpoint, and
 * pinning it here would mean two places to change when a statement gains a
 * line.
 */
final class ReportSerializer
{
    /**
     * Number of decimal places every float is rendered to.
     *
     * Reports are money and percentages, both of which read at two places.
     * Quantity-bearing reports (the stock card, the work-order material
     * variance) format their own rows and do not come through here.
     */
    private const PRECISION = 2;

    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Account => self::account($value),
            $value instanceof Model => self::model($value),
            $value instanceof CarbonInterface => $value->toDateString(),
            $value instanceof BackedEnum => EnumPresenter::present($value),
            $value instanceof Collection => self::normalize($value->all()),
            is_array($value) => array_map(self::normalize(...), $value),
            is_float($value) => number_format($value, self::PRECISION, '.', ''),
            default => $value,
        };
    }

    /**
     * Accounts get named because a statement row is meaningless without the
     * account it belongs to — but only the four fields a reader needs to
     * identify it.
     *
     * @return array<string, mixed>
     */
    private static function account(Account $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => EnumPresenter::present($account->type),
        ];
    }

    /**
     * Everything else is reduced to an identifier. A report is a summary, and
     * a client that needs the record behind a row should fetch it from its own
     * endpoint, where that record's own policy applies.
     *
     * @return array<string, mixed>
     */
    private static function model(Model $model): array
    {
        return [
            'id' => $model->getKey(),
            'type' => class_basename($model),
        ];
    }
}
