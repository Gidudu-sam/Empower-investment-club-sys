<?php

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PdfService — Member Statement Emailing stage (PDF attachment).
 *
 * Thin wrapper around Dompdf, the app's first server-side PDF generator.
 * Every existing "downloadable" document in this app (statements/print.php
 * and friends) relies on the browser's own print-to-PDF, which only works
 * when a human is looking at a page -- an unattended email send has no
 * browser to do that, so this is a genuinely new capability, not a reuse
 * of anything. Remote/local file access is left off (the default):
 * callers must inline any image as a base64 data URI rather than pointing
 * at a URL or filesystem path, keeping PDF rendering self-contained and
 * not dependent on this app's own web server being reachable from itself.
 */
class PdfService
{
    public function renderHtml(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
