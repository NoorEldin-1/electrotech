<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\GeneralLedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Printable account statement (قائمة المواد.pptx سلايد 3): "حساب الخزينة —
 * شهر يونيو" with the opening balance, every posted movement and a running
 * balance. Gated by the same permission as the on-screen ledger.
 */
class GeneralLedgerPdfController extends Controller
{
    public function __invoke(Request $request, GeneralLedgerService $ledger): Response
    {
        abort_unless((bool) $request->user()?->can('general_ledger.view'), 403);

        $account = Account::findOrFail($request->integer('account'));

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;

        $logoPath = base_path('electrotech-logo.jpg');

        $html = view('pdf.general-ledger', [
            'account' => $account,
            'opening' => $ledger->openingBalance($account, $from),
            'rows' => $ledger->for($account, $from, $to),
            'totals' => $ledger->totals($account, $from, $to),
            'closing' => $ledger->closingBalance($account, $to),
            'from' => $from,
            'to' => $to,
            'logo' => is_file($logoPath)
                ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($logoPath))
                : null,
        ])->render();

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

        $name = 'ledger-' . $account->id . '-' . ($from?->format('Y-m') ?? 'all') . '.pdf';

        return response((string) $mpdf->Output($name, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }
}
