<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QualitySheet;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a quality sheet as the printable paper form (التصنيع سلايد 3): the
 * operation header data, the inspection test grid, and the QA-inspector /
 * factory-manager signatures. Mirrors PurchaseOrderPdfController — streamed
 * through PHP and gated by QualitySheetPolicy::print so it works on any host
 * with solid Arabic shaping.
 */
class QualitySheetPdfController extends Controller
{
    public function show(QualitySheet $qualitySheet): Response
    {
        Gate::authorize('print', $qualitySheet);

        // Printable in Arabic or English regardless of the UI language; an
        // unknown / missing ?lang falls back to the current locale.
        $lang = in_array(request('lang'), ['ar', 'en'], true) ? request('lang') : app()->getLocale();
        app()->setLocale($lang);

        $qualitySheet->load(['workOrder.project', 'lines', 'qaFilledBy', 'factoryApprovedBy']);

        $logoPath = base_path('electrotech-logo.jpg');
        $logo = is_file($logoPath)
            ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($logoPath))
            : null;

        $html = view('pdf.quality-sheet', ['sheet' => $qualitySheet, 'logo' => $logo])->render();

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

        $dedicatedWatermark = public_path('images/offer-watermark.png');
        $hasDedicated = is_file($dedicatedWatermark);
        $watermark = $hasDedicated ? $dedicatedWatermark : $logoPath;
        if (is_file($watermark)) {
            $mpdf->SetWatermarkImage($watermark, $hasDedicated ? 0.12 : 0.08, 'D', 'P');
            $mpdf->showWatermarkImage = true;
        }

        $mpdf->WriteHTML($html);

        $name = 'qs-' . $qualitySheet->sheet_number . '.pdf';
        $name = str_replace(['/', '\\', ' '], '-', $name);

        return response((string) $mpdf->Output($name, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }
}
