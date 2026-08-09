<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatementSection;
use App\Models\Account;
use Illuminate\Support\Carbon;

/**
 * قائمة التدفقات النقدية — ماليات.pptx سلايدات 8 و 9.
 *
 * سلايد 8 states the purpose plainly: "الهدف من القائمة (تعديل حساب صافى
 * الربح)… بداية القائمة بحساب صافى الربح (بيوخذ من قائمة الدخل) ويتم اضافة او
 * طرح بقية الحسابات". So the statement is built by adjusting net profit, and
 * net profit comes from IncomeStatementService — never recomputed here.
 *
 * The four possibilities سلايد 9 spells out for every working-capital account:
 *
 *   1- الفرق موجب والحساب اصول    ⇒ يتم طرحه
 *   2- الفرق سالب والحساب اصول    ⇒ يتم اضافته
 *   3- الفرق موجب والحساب التزامات ⇒ يتم اضافته
 *   4- الفرق سالب والحساب التزامات ⇒ يتم طرحه
 *
 * are one rule with two signs: Δ = رصيد اخر الفترة − رصيد اول الفترة, and the
 * cash effect is −Δ for an asset and +Δ for a liability. Each row still
 * reports which of the four cases it hit, so the reader can check the slide
 * against the screen.
 *
 * ── Two requirements on سلايد 9 that contradict each other ─────────────────
 * The slide states the opening subtotal twice — as an arrow ("يتم خصم الاهلاك
 * والمخصصات") and as a formula ("صافى الربح – الاهلاك والمخصصات + ارباح
 * راسمالية"). The SAME slide ends with the test the statement has to pass:
 * "ويساوى رصيد النقدية اخر الفترة ويتم مطابقته مع الواقع".
 *
 * Those two cannot both hold. Because revenue and expense accounts have no
 * balance-sheet counterpart until the year is closed, the closing cash the
 * statement derives equals the cash the ledger holds if and only if:
 *
 *   • depreciation and provisions are ADDED BACK (they reduced net profit
 *     without moving cash), and
 *   • capital gains take NO adjustment at all — the proceeds already arrived
 *     as an ordinary cash receipt, and this system books no separate disposal
 *     line in investing for them to be moved to.
 *
 * Subtracting depreciation and provisions instead, and adding capital gains,
 * throws the closing cash out by exactly 2×(depreciation + provisions) +
 * capital gains — with the demo data, 720,000 — and the reconciliation the
 * slide asks for fails every time.
 *
 * So the default follows the slide's own final check rather than its wording.
 * The literal formula stays one config flag away —
 * finance.cash_flow.add_back_non_cash = false — for the accountant to compare
 * the two side by side; the screen names which one is running either way.
 */
class CashFlowStatementService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly IncomeStatementService $income,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?Carbon $from = null, ?Carbon $to = null): array
    {
        $to ??= Carbon::today();
        $from ??= $to->copy()->startOfYear();

        // The instant before the period opens — the "رصيد اول الفترة" side of
        // every difference in the statement.
        $opening = $from->copy()->subDay();

        $netProfit = $this->income->netProfit($from, $to);

        $addBack = (bool) config('finance.cash_flow.add_back_non_cash', true);
        $nonCashSign = $addBack ? 1 : -1;
        // Under the reconciling default, capital gains need no adjustment at
        // all: the money is already in the cash figure and nothing moves it to
        // investing. Under the client's literal formula they are added.
        $capitalGainsSign = $addBack ? 0 : 1;

        $depreciation = $this->sectionDelta(StatementSection::AccumulatedDepreciation, $opening, $to);
        $provisions = $this->sectionDelta(StatementSection::Provisions, $opening, $to);
        $capitalGains = $this->periodMovement(StatementSection::CapitalGains, $from, $to);

        $adjustments = [
            $this->adjustment('depreciation', $nonCashSign * $depreciation['total'], $depreciation['rows'], $nonCashSign),
            $this->adjustment('provisions', $nonCashSign * $provisions['total'], $provisions['rows'], $nonCashSign),
            $this->adjustment('capital_gains', $capitalGainsSign * $capitalGains['total'], $capitalGains['rows'], $capitalGainsSign),
        ];

        $operatingProfit = round($netProfit + array_sum(array_column($adjustments, 'amount')), 2);

        $workingCapital = array_merge(
            $this->workingCapitalRows(StatementSection::CurrentAssets, $opening, $to, isAsset: true),
            $this->workingCapitalRows(StatementSection::CurrentLiabilities, $opening, $to, isAsset: false),
        );

        $workingCapitalTotal = round(array_sum(array_column($workingCapital, 'amount')), 2);
        $operatingCash = round($operatingProfit + $workingCapitalTotal, 2);

        $investing = $this->workingCapitalRows(StatementSection::FixedAssets, $opening, $to, isAsset: true);
        $investingTotal = round(array_sum(array_column($investing, 'amount')), 2);

        $financing = array_merge(
            $this->workingCapitalRows(StatementSection::PartnersCurrentAccount, $opening, $to, isAsset: false),
            $this->workingCapitalRows(StatementSection::Equity, $opening, $to, isAsset: false),
        );
        $financingTotal = round(array_sum(array_column($financing, 'amount')), 2);

        $netChange = round($operatingCash + $investingTotal + $financingTotal, 2);

        // "ويساوى رصيد النقدية اخر الفترة ويتم مطابقته مع الواقع" — the whole
        // statement is only credible if the number it derives equals the cash
        // the ledger actually holds. The screen says which it is.
        $openingCash = $this->cashBalance($opening);
        $actualClosingCash = $this->cashBalance($to);
        $derivedClosingCash = round($openingCash + $netChange, 2);

        return [
            'from' => $from,
            'to' => $to,
            'net_profit' => $netProfit,
            'adjustments' => $adjustments,
            'operating_profit_before_wc' => $operatingProfit,
            'working_capital' => $workingCapital,
            'working_capital_total' => $workingCapitalTotal,
            'operating_cash' => $operatingCash,
            'investing' => $investing,
            'investing_total' => $investingTotal,
            'financing' => $financing,
            'financing_total' => $financingTotal,
            'net_change' => $netChange,
            'opening_cash' => $openingCash,
            'derived_closing_cash' => $derivedClosingCash,
            'actual_closing_cash' => $actualClosingCash,
            'reconciled' => abs($derivedClosingCash - $actualClosingCash) < 0.01,
            'reconciliation_difference' => round($derivedClosingCash - $actualClosingCash, 2),
            'add_back_non_cash' => $addBack,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{label: string, amount: float, rows: array<int, array<string, mixed>>, sign: int}
     */
    private function adjustment(string $label, float $amount, array $rows, int $sign): array
    {
        return ['label' => $label, 'amount' => round($amount, 2), 'rows' => $rows, 'sign' => $sign];
    }

    /**
     * Movement of every account in a section over the period — used for the
     * income-statement-style adjustments (capital gains).
     *
     * @return array{rows: array<int, array<string, mixed>>, total: float}
     */
    private function periodMovement(StatementSection $section, Carbon $from, Carbon $to): array
    {
        $rows = $this->balances->sectionRoots($section)
            ->map(fn (Account $a): array => [
                'account' => $a,
                'label' => $a->name,
                'amount' => $this->balances->rolledUpMovement($a, $from, $to),
            ])
            ->values()
            ->all();

        return ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)];
    }

    /**
     * Closing-to-closing difference of every account in a section — the
     * "رصيد اخر الفترة − رصيد اول الفترة" the slide asks for.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: float}
     */
    private function sectionDelta(StatementSection $section, Carbon $opening, Carbon $to): array
    {
        $rows = $this->balances->sectionRoots($section)
            ->map(function (Account $a) use ($opening, $to): array {
                $before = $this->balances->rolledUpClosing($a, $opening);
                $after = $this->balances->rolledUpClosing($a, $to);

                return [
                    'account' => $a,
                    'label' => $a->name,
                    'opening' => $before,
                    'closing' => $after,
                    'amount' => round($after - $before, 2),
                ];
            })
            ->values()
            ->all();

        return ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)];
    }

    /**
     * The four-possibility rule of سلايد 9, applied per account.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workingCapitalRows(StatementSection $section, Carbon $opening, Carbon $to, bool $isAsset): array
    {
        return $this->balances->sectionRoots($section)
            ->map(function (Account $a) use ($opening, $to, $isAsset): array {
                $before = $this->balances->rolledUpClosing($a, $opening);
                $after = $this->balances->rolledUpClosing($a, $to);
                $delta = round($after - $before, 2);

                // asset  ⇒ cash moves opposite to the balance
                // liability ⇒ cash moves with the balance
                $amount = round($isAsset ? -$delta : $delta, 2);

                return [
                    'account' => $a,
                    'label' => $a->name,
                    'opening' => $before,
                    'closing' => $after,
                    'delta' => $delta,
                    'amount' => $amount,
                    'is_asset' => $isAsset,
                    // Which of the four cases in سلايد 9 this row landed on.
                    'case' => match (true) {
                        $delta > 0 && $isAsset => 1,
                        $delta < 0 && $isAsset => 2,
                        $delta > 0 && ! $isAsset => 3,
                        $delta < 0 && ! $isAsset => 4,
                        default => 0,
                    },
                ];
            })
            // A balance that never moved contributes nothing and would only
            // pad an already long statement.
            ->filter(fn (array $row): bool => abs($row['delta']) >= 0.005)
            ->values()
            ->all();
    }

    /**
     * النقدية وما في حكمها at a date — the sum of every account classified as
     * CashAndBanks. That classification IS the system's definition of cash.
     */
    public function cashBalance(?Carbon $asOf): float
    {
        return round(
            $this->balances->sectionRoots(StatementSection::CashAndBanks)
                ->sum(fn (Account $a): float => $this->balances->rolledUpClosing($a, $asOf)),
            2,
        );
    }
}
