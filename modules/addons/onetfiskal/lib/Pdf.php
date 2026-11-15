<?php
declare(strict_types=1);
namespace Onet\Fiskal;

use RuntimeException;

/** Local representation of an already-issued response. No HTML parsing or network I/O. */
final class Pdf
{
    public static function render(array $response, array $notes = []): string
    {
        if (!class_exists('TCPDF')) { class_exists('WHMCS\\PDF'); }
        if (!class_exists('TCPDF')) { throw new RuntimeException('WHMCS TCPDF mehanizam nije dostupan. HTML i TXT ostaju dostupni.', 503); }
        if (!function_exists('imagecreatefromstring')) { throw new RuntimeException('Za originalni GIF QR u PDF-u potrebna je PHP GD ekstenzija.', 503); }
        $font = __DIR__ . '/../assets/fonts/dejavusansmono.php';
        foreach ([$font, dirname($font) . '/dejavusansmono.z', dirname($font) . '/dejavusansmono.ctg.z'] as $file) {
            if (!is_readable($file)) { throw new RuntimeException('Nedostaje PDF font. Prekopiraj ceo assets/fonts direktorijum iz ZIP-a.', 503); }
        }
        $qr = Receipt::qr($response);
        if ($qr === '') { throw new RuntimeException('PDF nije generisan: u sačuvanom odgovoru nema ispravnog originalnog QR koda.', 422); }
        $journal = $response['journal'] ?? null;
        if (!is_string($journal) || $journal === '' || strlen($journal) > 500000 || !mb_check_encoding($journal, 'UTF-8')) {
            throw new RuntimeException('Sačuvani žurnal nije pogodan za PDF prikaz.', 422);
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $journal));
        if (count($lines) > 2000) { throw new RuntimeException('Žurnal je predugačak za PDF prikaz.', 422); }
        if (!is_string($response['sdcDateTime'] ?? null) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}/D', $response['sdcDateTime'])) {
            throw new RuntimeException('Nedostaje ispravno PFR vreme za PDF dokument.', 422);
        }
        try { $timestamp = (new \DateTimeImmutable($response['sdcDateTime']))->getTimestamp(); }
        catch (\Throwable) { throw new RuntimeException('Nedostaje ispravno PFR vreme za PDF dokument.', 422); }
        require_once __DIR__ . '/PdfEngine.php';
        $pdf = new PdfEngine('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->sourceIdentity(hash('sha256', json_encode([$response, $notes, Product::VERSION], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        $pdf->setDocCreationTimestamp($timestamp); $pdf->setDocModificationTimestamp($timestamp);
        $pdf->SetCreator('ONET Fiskal'); $pdf->SetAuthor('ONET / UTG');
        $pdf->SetTitle('SANDBOX - ' . (string) ($response['invoiceNumber'] ?? ''));
        $pdf->setPrintHeader(true); $pdf->setPrintFooter(true);
        $pdf->SetMargins(12, 12, 12); $pdf->SetAutoPageBreak(true, 14);
        $pdf->setFontSubsetting(true); $pdf->SetFont('dejavusansmono', '', 10, $font, true);
        $size = 10.0; $widest = 0.0;
        foreach ($lines as $line) { $widest = max($widest, $pdf->GetStringWidth($line)); }
        if ($widest > 180) { $size = 10 * 180 / $widest; }
        if ($size < 8) { throw new RuntimeException('Žurnal je preširok za čitljiv PDF. Preuzmi originalni TXT ili HTML.', 422); }
        $pdf->SetFontSize($size);
        $width = max(105.0, min(186.0, $widest * $size / 10 + 4)); $x = (210 - $width) / 2;
        $pdf->SetLeftMargin($x); $pdf->SetRightMargin($x); $pdf->AddPage();
        $pdf->SetTextColor(129, 65, 24); $pdf->SetFontSize(10);
        $pdf->MultiCell($width, 5, 'SANDBOX - SAMO ZA TESTIRANJE', 0, 'L');
        $pdf->SetTextColor(40, 48, 59); $pdf->SetFontSize(9);
        $pdf->MultiCell($width, 4, 'Test dokument: ' . (string) ($response['invoiceNumber'] ?? ''), 0, 'L'); $pdf->Ln(3);
        if (in_array($response['invoiceCounterExtension'] ?? '', ['КП', 'КР', 'CS', 'CR'], true)) {
            $pdf->SetFontSize(16); $pdf->MultiCell($width, 8, 'ОВО НИЈЕ ФИСКАЛНИ РАЧУН', 0, 'L'); $pdf->Ln(3);
        }
        $pdf->SetFontSize($size); $pdf->SetTextColor(0, 0, 0);
        foreach ($lines as $line) { $pdf->Cell($width, $size * 0.39, $line, 0, 1, 'L', false, '', 0, false, 'T', 'M'); }
        // Keep QR and its verification action together, using the saved image bytes only.
        $pdf->Ln(4);
        if ($pdf->GetY() + 60 > 283) { $pdf->AddPage(); }
        $y = $pdf->GetY(); $image = base64_decode(substr($qr, strpos($qr, ',') + 1), true);
        $pdf->Image('@' . $image, (210 - 45) / 2, $y, 45, 45, 'GIF', '', '', false, 300);
        $pdf->SetY($y + 49); $pdf->SetFontSize(9);
        $verify = Checks::verificationUrl((string) ($response['verificationUrl'] ?? ''));
        if ($verify !== '') {
            $pdf->SetTextColor(32, 79, 157); $pdf->Cell($width, 6, 'Proveri test dokument na portalu Poreske', 0, 1, 'C', false, $verify);
        }
        if ($notes) {
            $pdf->Ln(5); $pdf->SetTextColor(30, 40, 55); $pdf->SetFontSize(9);
            foreach ($notes as $note) { $pdf->MultiCell($width, 4.5, (string) $note, 0, 'L'); $pdf->Ln(2); }
        }
        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) { throw new RuntimeException('PDF izlaz nije ispravan.', 503); }
        return $bytes;
    }
}
