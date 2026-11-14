<?php
declare(strict_types=1);

namespace Onet\Fiskal;

use RuntimeException;

final class Product
{
    public const VERSION = '0.4.0';

    public static function information(): string
    {
        $installation = substr(hash('sha256', defined('ROOTDIR') ? (string) ROOTDIR : dirname(__DIR__, 4)), 0, 16);
        return '<details><summary>O modulu i identifikacija</summary><p>Proizvod: ONET Fiskal · Proizvođač: UTG / ONET · Verzija: '
            . self::VERSION . '</p><p>Interni ID instalacije: ' . $installation
            . ' (razvojni identifikator, nije IB odobrenja).</p><p>Razvojni sandbox ESIR za sopstvene potrebe. '
            . 'Odobrenje i dodeljeni ESIR IB broj još ne postoje.</p></details>';
    }
}

/** Presentation of a saved response only. Never issues, signs or modifies an invoice. */
final class Receipt
{
    public static function decode(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || !is_string($data['invoiceNumber'] ?? null)
            || !is_string($data['journal'] ?? null) || $data['journal'] === '') {
            throw new RuntimeException('Sačuvan odgovor nema čitljiv račun. Proveri originalnu evidenciju.');
        }
        return $data;
    }

    public static function qr(array $response): string
    {
        $encoded = $response['verificationQRCode'] ?? null;
        if (!is_string($encoded) || strlen($encoded) > 1400000) { return ''; }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) > 1000000
            || !in_array(substr($bytes, 0, 6), ['GIF87a', 'GIF89a'], true)) { return ''; }
        $size = @getimagesizefromstring($bytes);
        if (!$size || ($size['mime'] ?? '') !== 'image/gif' || $size[0] < 1 || $size[1] < 1
            || $size[0] > 2048 || $size[1] > 2048) { return ''; }
        return 'data:image/gif;base64,' . base64_encode($bytes);
    }

    public static function fragment(array $response): string
    {
        $url = Checks::verificationUrl((string) ($response['verificationUrl'] ?? ''));
        $qr = self::qr($response);
        $html = '<section class="onet-receipt"><p><strong>SANDBOX — SAMO ZA TESTIRANJE</strong></p>'
            . '<p>Test račun: ' . Admin::e($response['invoiceNumber'] ?? '') . '</p>'
            . '<pre class="onet-journal">' . Admin::e($response['journal'] ?? '') . '</pre>';
        if ($qr !== '') {
            $html .= '<img class="onet-qr" src="' . $qr . '" alt="QR kod za proveru sandbox računa">';
        } else {
            $html .= '<p class="onet-qr-warning">QR kod nedostaje ili nije ispravan u sačuvanom odgovoru. '
                . 'Prikaz nije kompletan za dostavu kupcu. Otvaranje ovog računa ne šalje novi fiskalni zahtev.</p>';
        }
        if ($url !== '') {
            $html .= '<p><a target="_blank" rel="noopener noreferrer" href="' . Admin::e($url)
                . '">Proveri na sandbox portalu Poreske</a></p>';
        }
        return $html . '</section>';
    }

    public static function styles(): string
    {
        return '.onet-receipt{box-sizing:border-box;background:#fff;color:#111;border:1px solid #ccc;padding:20px;font:13px/1.45 monospace;width:calc(42ch + 42px);max-width:100%}'
            . '.onet-journal{font:inherit;white-space:pre;overflow-x:auto;background:#fff;color:#111;border:0;padding:0}'
            . '.onet-qr{display:block;width:45mm;height:45mm;object-fit:contain;image-rendering:pixelated;margin:12px auto}'
            . '.onet-qr-warning{color:#8a3a00}.onet-actions{margin:12px 0}'
            . '@media print{.onet-no-print{display:none!important}.onet-receipt{border:0;padding:0;width:42ch;max-width:100%;font-size:10pt}'
            . '.onet-journal{font-size:10pt;overflow:visible}.onet-qr{break-inside:avoid}a{color:#111}body{margin:12mm}}';
    }

    public static function document(array $response): string
    {
        return '<!doctype html><html lang="sr-Latn"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'">'
            . '<title>Sandbox račun ' . Admin::e($response['invoiceNumber'] ?? '') . '</title>'
            . '<style>body{font:15px/1.5 sans-serif;margin:24px}' . self::styles() . '</style></head><body>'
            . '<p class="onet-no-print">Štampa / PDF: koristi Ctrl+P (na macOS-u Cmd+P), zatim štampač ili „Sačuvaj kao PDF”. '
            . 'Ovo je prikaz već izdatog test računa.</p>' . self::fragment($response) . '</body></html>';
    }

    public static function links(string $source, int $id): string
    {
        $url = Admin::e(self::endpoint()) . '?source=' . rawurlencode($source) . '&amp;id=' . $id;
        return '<p class="onet-actions"><a class="btn btn-default" target="_blank" rel="noopener noreferrer" href="' . $url
            . '&amp;format=html">Otvori za štampu / PDF</a> <a class="btn btn-default" href="' . $url
            . '&amp;format=html&amp;download=1">Preuzmi HTML sa QR kodom</a> <a class="btn btn-default" href="' . $url
            . '&amp;format=txt">Preuzmi originalni tekst</a></p>';
    }

    public static function endpoint(): string
    {
        // Addon output lives under the admin directory, not under the WHMCS web root.
        global $CONFIG;
        $base = $CONFIG['SystemURL'] ?? '';
        if (is_string($base) && filter_var($base, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)
            && !parse_url($base, PHP_URL_QUERY) && !parse_url($base, PHP_URL_FRAGMENT)) {
            return rtrim($base, '/') . '/modules/addons/onetfiskal/receipt.php';
        }
        return '../modules/addons/onetfiskal/receipt.php';
    }
}

final class ReceiptExport
{
    public static function load(array $input): array
    {
        if (!Admin::allowed()) { throw new RuntimeException('Nemaš pristup modulu.', 403); }
        $source = $input['source'] ?? null;
        $id = $input['id'] ?? null;
        if (!in_array($source, ['invoice', 'synthetic', 'refund'], true) || !is_scalar($id)
            || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) || (int) $id > 2147483647) {
            throw new RuntimeException('Neispravan izvor ili ID računa.', 400);
        }
        $format = $input['format'] ?? 'html';
        if (!in_array($format, ['html', 'txt'], true)) { throw new RuntimeException('Nepoznat format.', 400); }
        $row = (new Store('', $source))->find((int) $id);
        if (!$row || $row['state'] !== 'issued') { throw new RuntimeException('Potvrđen račun nije pronađen.', 404); }
        $response = Receipt::decode($row['response']);
        return ['format' => $format, 'download' => ($input['download'] ?? '') === '1' || $format === 'txt',
            'filename' => 'ONET-sandbox-' . $source . '-' . (int) $id . '.' . $format,
            'body' => $format === 'html' ? Receipt::document($response) : $response['journal']];
    }
}
