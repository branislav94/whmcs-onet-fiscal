<?php
declare(strict_types=1);
namespace Onet\Fiskal;

/** Loaded only after the WHMCS TCPDF class is available. */
final class PdfEngine extends \TCPDF
{
    public function Header(): void
    {
        $this->SetY(5); $this->SetFont('dejavusansmono', '', 8);
        $this->SetTextColor(129, 65, 24);
        $this->Cell(0, 4, 'ONET FISKAL - SANDBOX', 0, 0, 'C');
    }
    public function Footer(): void
    {
        $this->SetY(-10); $this->SetFont('dejavusansmono', '', 8);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(0, 4, 'SANDBOX - ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
    public function Error($msg): void
    {
        throw new \RuntimeException('PDF nije generisan. Proveri PDF mehanizam, font i privremeni direktorijum servera.', 503);
    }
    public function sourceIdentity(string $fingerprint): void { $this->file_id = substr($fingerprint, 0, 32); }
}
