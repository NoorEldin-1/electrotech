<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\WorkOrderMaterialVarianceService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Printable planned-vs-issued material comparison for a work order
 * (قائمة المواد.pptx سلايد 1) — the sheet the office uses to read the material
 * loss of an operation.
 */
class WorkOrderMaterialVariancePdfController extends Controller
{
    public function __invoke(WorkOrder $workOrder, WorkOrderMaterialVarianceService $service): Response
    {
        Gate::authorize('view', $workOrder);

        $workOrder->load(['project', 'outputItem']);
        $logoPath = base_path('electrotech-logo.jpg');

        $html = view('pdf.work-order-material-variance', [
            'workOrder' => $workOrder,
            'variance' => $service->for($workOrder),
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

        $name = str_replace(['/', '\\', ' '], '-', 'materials-' . $workOrder->wo_number . '.pdf');

        return response((string) $mpdf->Output($name, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }
}
