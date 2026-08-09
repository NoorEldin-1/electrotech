<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BalanceSheetService;
use App\Services\CashFlowStatementService;
use App\Services\IncomeStatementService;
use App\Services\OperatingStatementService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Printable financial statements (ماليات.pptx) — قائمة التشغيل، قائمة الدخل،
 * قائمة المركز المالى، قائمة التدفقات النقدية.
 *
 * One controller for all four rather than four near-identical ones: the mPDF
 * setup, the Arabic RTL handling and the company header are identical, and
 * only the data source and the body partial differ. Each statement keeps its
 * own permission, so the shared entry point grants nothing extra.
 */
class FinancialStatementPdfController extends Controller
{
    /**
     * statement key ⇒ [permission, view partial, filename stem]
     *
     * @var array<string, array{permission: string, view: string, file: string}>
     */
    private const STATEMENTS = [
        'operating' => [
            'permission' => 'operating_statement.view',
            'view' => 'pdf.statements.operating',
            'file' => 'operating-statement',
        ],
        'income' => [
            'permission' => 'income_statement.view',
            'view' => 'pdf.statements.income',
            'file' => 'income-statement',
        ],
        'balance_sheet' => [
            'permission' => 'balance_sheet.view',
            'view' => 'pdf.statements.balance-sheet',
            'file' => 'balance-sheet',
        ],
        'cash_flow' => [
            'permission' => 'cash_flow_statement.view',
            'view' => 'pdf.statements.cash-flow',
            'file' => 'cash-flow-statement',
        ],
    ];

    public function __invoke(Request $request): Response
    {
        $key = (string) $request->string('statement');
        $config = self::STATEMENTS[$key] ?? abort(404);

        abort_unless((bool) $request->user()?->can($config['permission']), 403);

        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : Carbon::today();
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : $to->copy()->startOfYear();

        $logoPath = base_path('electrotech-logo.jpg');

        $html = view($config['view'], [
            'statement' => $this->data($key, $from, $to),
            'from' => $from,
            'to' => $to,
            'logo' => is_file($logoPath)
                ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($logoPath))
                : null,
        ])->render();

        return $this->stream($html, $config['file'] . '-' . $to->format('Y-m-d') . '.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $key, Carbon $from, Carbon $to): array
    {
        return match ($key) {
            'operating' => app(OperatingStatementService::class)->build($from, $to),
            'income' => app(IncomeStatementService::class)->build($from, $to),
            'balance_sheet' => app(BalanceSheetService::class)->build($to, $from),
            'cash_flow' => app(CashFlowStatementService::class)->build($from, $to),
        };
    }

    private function stream(string $html, string $name): Response
    {
        $tempDir = storage_path('app/mpdf');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->WriteHTML($html);

        return response((string) $mpdf->Output($name, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }
}
