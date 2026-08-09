<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatementSection;
use App\Models\Account;
use Illuminate\Support\Carbon;

/**
 * قائمة التشغيل — ماليات.pptx سلايد 2:
 *
 *     حساب تكلفة البضاعة المباعة
 *   + مصروفات التشغيل
 *   + مصروفات تركيب
 *   + م. تصدير
 *   + مصروفات واهلاكات صناعية
 *   ─────────────────────────
 *   = تكلفة المبيعات
 *
 * The slide closes with "لاحظ كله حسابات مسجلة بشجرة الحسابات" — every line is
 * a real account, not a hard-coded label. So the rows here are simply the
 * accounts classified as StatementSection::CostOfSales, and the accountant
 * adds a line to the statement by adding an account, never by asking for code.
 *
 * The result feeds قائمة الدخل as the single "( - ) تكلفة المبيعات" figure.
 */
class OperatingStatementService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
    ) {}

    /**
     * @return array{
     *     rows: array<int, array{account: Account, amount: float}>,
     *     total: float,
     *     from: ?Carbon,
     *     to: ?Carbon
     * }
     */
    public function build(?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = $this->balances
            ->sectionRoots(StatementSection::CostOfSales)
            ->map(fn (Account $account): array => [
                'account' => $account,
                'amount' => $this->balances->rolledUpMovement($account, $from, $to),
            ])
            // An account with no activity in the period is noise on a
            // statement — but a line the accountant expects to see and that
            // came out zero is information, so zero rows are kept and only
            // accounts that never moved AND have nothing under them drop out.
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'total' => round(array_sum(array_column($rows, 'amount')), 2),
            'from' => $from,
            'to' => $to,
        ];
    }

    /** The single figure قائمة الدخل subtracts from net sales. */
    public function costOfSales(?Carbon $from = null, ?Carbon $to = null): float
    {
        return $this->build($from, $to)['total'];
    }
}
