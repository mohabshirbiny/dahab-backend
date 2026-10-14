<?php

namespace App\Support\Documents;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * One bilingual A4 PDF from HTML through mPDF (spec 016 research R9): Arabic is shaped and set
 * right to left by mPDF's script detection, with the bundled DejaVu font. Shared by the tax
 * documents and the customer's wallet statement, so both look and render the same.
 */
final class MpdfDocument
{
    public static function render(string $html, string $title): string
    {
        $tmp = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tmp);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tmp,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('Dahab');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
