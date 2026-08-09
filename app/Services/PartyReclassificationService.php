<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تقسيم العملاء والموردين إلى مدين ودائن — ماليات.pptx سلايد 7:
 *
 *   «يجب تقسيمهم الى نوعين: (عملاء مدينة) و (عملاء دائنة – دفعات مقدمة).
 *    المدينة اذا كان رصيد العملاء موجب، ودائنة اذا كان رصيدهم سالب.
 *    وده هيفرق في قائمة المركز المالي، بسبب ان العملاء المدينة تعتبر من ضمن
 *    الاصول، اما العملاء الدائنة تعتبر من ضمن الالتزامات المتداولة.»
 *
 * The split is a per-party decision, so it cannot be made inside the pooled
 * control account (1200 العملاء) — that account has one balance. It is made in
 * the party sub-ledger (`account_entries`), where every customer and supplier
 * carries its own running balance, and the two halves are then presented on
 * opposite sides of the balance sheet.
 *
 * ── Sign convention ────────────────────────────────────────────────────────
 * `account_entries.amount` is signed in the PARTY'S OWN natural direction and
 * summed plainly (see the column comment on the migration and
 * AccountStatementService). A customer's natural side is debit and a
 * supplier's is credit, so the same positive number means opposite things:
 *
 *   customer balance > 0  ⇒ مدين — they owe us          ⇒ أصل متداول
 *   customer balance < 0  ⇒ دائن — a prepayment received ⇒ التزام متداول
 *   supplier balance > 0  ⇒ دائن — we still owe them     ⇒ التزام متداول
 *   supplier balance < 0  ⇒ مدين — an advance we paid    ⇒ أصل متداول
 *
 * The slide states the rule as "positive is debit" for both, describing the
 * accounting idea rather than this system's storage. What it actually asks for
 * — money owed to us on the asset side, money owed by us on the liability side
 * — is what the table above produces.
 */
class PartyReclassificationService
{
    /** Balances smaller than this are treated as settled (rounding noise). */
    private const EPSILON = 0.005;

    /**
     * The customer split as of a date.
     *
     * @return array{debit: float, credit: float, net: float, debit_parties: Collection<int, array{party: mixed, balance: float}>, credit_parties: Collection<int, array{party: mixed, balance: float}>}
     */
    public function customers(?Carbon $asOf = null): array
    {
        return $this->split(Customer::class, $asOf, naturalSideIsDebit: true);
    }

    /**
     * The supplier split as of a date. Same rule, mirrored: a supplier with a
     * debit balance is an advance we paid (أصل), a credit balance is what we
     * still owe (التزام متداول).
     *
     * @return array{debit: float, credit: float, net: float, debit_parties: Collection<int, array{party: mixed, balance: float}>, credit_parties: Collection<int, array{party: mixed, balance: float}>}
     */
    public function suppliers(?Carbon $asOf = null): array
    {
        return $this->split(Supplier::class, $asOf, naturalSideIsDebit: false);
    }

    /**
     * @param  class-string  $model
     * @param  bool  $naturalSideIsDebit  true when a positive stored balance
     *                                    means the party owes us
     * @return array{debit: float, credit: float, net: float, debit_parties: Collection<int, array{party: mixed, balance: float}>, credit_parties: Collection<int, array{party: mixed, balance: float}>}
     */
    public function split(string $model, ?Carbon $asOf = null, bool $naturalSideIsDebit = true): array
    {
        $morphClass = (new $model)->getMorphClass();

        $balances = AccountEntry::query()
            ->where('party_type', $morphClass)
            ->when($asOf, fn ($q) => $q->whereDate('entry_date', '<=', $asOf->toDateString()))
            ->groupBy('party_id')
            ->select('party_id', DB::raw('SUM(amount) as balance'))
            ->pluck('balance', 'party_id');

        $parties = $model::query()
            ->whereIn('id', $balances->keys()->all())
            ->get()
            ->keyBy('id');

        $debitParties = collect();
        $creditParties = collect();

        foreach ($balances as $partyId => $balance) {
            $balance = round((float) $balance, 2);
            $party = $parties->get($partyId);

            if ($party === null || abs($balance) < self::EPSILON) {
                continue;
            }

            // Convert the stored balance into a debit-positive figure before
            // deciding the side, so customers and suppliers are judged by the
            // same rule despite storing opposite signs.
            $debitPositive = $naturalSideIsDebit ? $balance : -$balance;

            if ($debitPositive > 0) {
                $debitParties->push(['party' => $party, 'balance' => $debitPositive]);
            } else {
                $creditParties->push(['party' => $party, 'balance' => abs($debitPositive)]);
            }
        }

        $debit = round((float) $debitParties->sum('balance'), 2);
        $credit = round((float) $creditParties->sum('balance'), 2);

        return [
            'debit' => $debit,
            'credit' => $credit,
            'net' => round($debit - $credit, 2),
            'debit_parties' => $debitParties->sortByDesc('balance')->values(),
            'credit_parties' => $creditParties->sortByDesc('balance')->values(),
        ];
    }

    /**
     * A single party's stored sub-ledger balance (in the party's own natural
     * direction — see the class doc block).
     */
    public function balanceFor(string $partyType, int $partyId, ?Carbon $asOf = null): float
    {
        return round((float) AccountEntry::query()
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->when($asOf, fn ($q) => $q->whereDate('entry_date', '<=', $asOf->toDateString()))
            ->sum('amount'), 2);
    }
}
