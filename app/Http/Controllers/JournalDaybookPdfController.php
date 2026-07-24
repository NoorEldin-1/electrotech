<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\JournalDaybookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Printable analytical daybook (قائمة المواد.pptx سلايد 2) — the same table
 * the JournalDaybook page shows, streamed as a landscape A4 PDF through mPDF
 * so Arabic shaping is right on any host.
 */
class JournalDaybookPdfController extends Controller
{
    public function __invoke(Request $request, JournalDaybookService $daybookService): Response
    {
        abort_unless((bool) $request->user()?->can('journal_daybook.view'), 403);

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;
        $currency = $request->filled('currency') ? $request->string('currency')->toString() : null;

        $accountIds = collect(explode(',', (string) $request->query('accounts', '')))
            ->filter(fn (string $id) => is_numeric($id))
            ->map(fn (string $id) => (int) $id)
            ->all();

        $daybook = $daybookService->build($from, $to, $accountIds, $currency);

        $html = view('pdf.journal-daybook', [
            'daybook' => $daybook,
            'from' => $from,
            'to' => $to,
            'currency' => $currency,
            'logo' => $this->logo(),
        ])->render();

        return $this->stream($html, 'daybook-' . ($from?->format('Y-m') ?? 'all') . '.pdf');
    }

    /**
     * The company logo inlined as a data URI, or null when it is missing.
     */
    private function logo(): ?string
    {
        $logoPath = base_path('electrotech-logo.jpg');

        return is_file($logoPath)
            ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($logoPath))
            : null;
    }

    private function stream(string $html, string $name): Response
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
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
