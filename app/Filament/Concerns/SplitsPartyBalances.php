<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ماليات.pptx سلايد 7 — "يجب تقسيمهم الى نوعين: (مدينة) و (دائنة – دفعات
 * مقدمة)… وده هيفرق في قائمة المركز المالي".
 *
 * Shared by the customer and supplier tables so the two lists classify a party
 * by exactly the same rule the balance sheet uses when it splits the control
 * account (see PartyReclassificationService). One rule, three screens.
 *
 * `account_entries.amount` is stored signed in the PARTY'S OWN natural
 * direction, so the same positive number means opposite things: a customer's
 * natural side is debit (they owe us) and a supplier's is credit (we owe
 * them). Each resource declares its side through partyNaturalSideIsDebit().
 */
trait SplitsPartyBalances
{
    /** Balances below this count as settled, not as a hair-thin debit. */
    private const BALANCE_EPSILON = 0.005;

    /**
     * True when a positive stored balance means the party owes us. Customers
     * override nothing (debit is their natural side); suppliers return false.
     */
    protected static function partyNaturalSideIsDebit(): bool
    {
        return true;
    }

    /**
     * Which side of the balance sheet this party falls on.
     *
     * @return 'debit'|'credit'|'settled'
     */
    protected static function balanceNature(float $balance): string
    {
        $debitPositive = static::partyNaturalSideIsDebit() ? $balance : -$balance;

        return match (true) {
            $debitPositive > self::BALANCE_EPSILON => 'debit',
            $debitPositive < -self::BALANCE_EPSILON => 'credit',
            default => 'settled',
        };
    }

    /**
     * Filter a party list by balance side. The balance is a SUM over the
     * sub-ledger rather than a column, so the filter compares against a
     * correlated subquery instead of a plain where.
     */
    protected static function filterByBalanceNature(Builder $query, ?string $nature): Builder
    {
        if (! in_array($nature, ['debit', 'credit', 'settled'], true)) {
            return $query;
        }

        $model = $query->getModel();
        $morphClass = $model->getMorphClass();
        $table = $model->getTable();

        // COALESCE so a party with no postings at all still counts as settled
        // rather than dropping out of every option.
        $sub = DB::table('account_entries')
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('account_entries.party_id', "{$table}.id")
            ->where('account_entries.party_type', $morphClass);

        // Flip the comparison rather than the SQL when the party's natural
        // side is credit, so the raw subquery stays simple.
        $sql = '(' . $sub->toSql() . ')';
        $bindings = $sub->getBindings();
        $epsilon = self::BALANCE_EPSILON;
        $debitIsPositive = static::partyNaturalSideIsDebit();

        return match ($nature) {
            'debit' => $debitIsPositive
                ? $query->whereRaw("{$sql} > ?", [...$bindings, $epsilon])
                : $query->whereRaw("{$sql} < ?", [...$bindings, -$epsilon]),
            'credit' => $debitIsPositive
                ? $query->whereRaw("{$sql} < ?", [...$bindings, -$epsilon])
                : $query->whereRaw("{$sql} > ?", [...$bindings, $epsilon]),
            default => $query->whereRaw("ABS({$sql}) <= ?", [...$bindings, $epsilon]),
        };
    }
}
