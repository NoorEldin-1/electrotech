<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProjectOffer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a project offer as a printable PDF quotation (Slides 7 & 8).
 *
 * Streamed through PHP (not the /storage symlink) so it is permission-checked
 * via ProjectOfferPolicy::print and works on any host. mpdf is used for its
 * solid Arabic / RTL shaping.
 */
class OfferPdfController extends Controller
{
    public function show(ProjectOffer $offer): Response
    {
        Gate::authorize('print', $offer);

        $offer->load(['project', 'groups.items', 'submittedBy']);

        $logoPath = base_path('electrotech-logo.jpg');
        $logo = is_file($logoPath)
            ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $html = view('pdf.offer', ['offer' => $offer, 'logo' => $logo])->render();

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

        $name = 'offer-'.($offer->quotation_number ?: $offer->id).'.pdf';
        $name = str_replace(['/', '\\', ' '], '-', $name);

        return response((string) $mpdf->Output($name, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }
}
