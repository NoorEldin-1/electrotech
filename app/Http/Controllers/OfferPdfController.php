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

        // Slide 9: the offer can be printed in Arabic or English regardless of
        // the UI language. From 2026 offers default to English, so an unknown /
        // missing ?lang falls back to 'en'.
        $lang = in_array(request('lang'), ['ar', 'en'], true) ? request('lang') : 'en';
        app()->setLocale($lang);

        $offer->load(['project.customer', 'groups.items', 'submittedBy']);

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

        // Slide 2: stamp the company logo as a faint watermark behind every
        // page, like the reference quotation. A dedicated low-contrast asset is
        // preferred (it is already softened, so it can carry a touch more
        // opacity); fall back to the main full-colour logo at a lower opacity
        // so its dark edges never fight the text.
        $dedicatedWatermark = public_path('images/offer-watermark.png');
        $hasDedicated = is_file($dedicatedWatermark);
        $watermark = $hasDedicated ? $dedicatedWatermark : $logoPath;
        if (is_file($watermark)) {
            $mpdf->SetWatermarkImage($watermark, $hasDedicated ? 0.12 : 0.08, 'D', 'P');
            $mpdf->showWatermarkImage = true;
        }

        $mpdf->WriteHTML($html);

        $name = 'offer-'.($offer->quotation_number ?: $offer->id).'.pdf';
        $name = str_replace(['/', '\\', ' '], '-', $name);

        return response((string) $mpdf->Output($name, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }
}
