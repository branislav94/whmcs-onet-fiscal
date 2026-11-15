<?php
declare(strict_types=1);
namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

/** Administrator-only delivery preview. No transport, queue writes or fiscal requests. */
final class Delivery
{
    public static function url(string $source, int $id): string
    {
        return 'addonmodules.php?module=onetfiskal&view=delivery&source=' . rawurlencode($source) . '&documentid=' . $id;
    }
    private static function context(string $source, int $id, int $depth = 0): array
    {
        if ($depth > 4 || !in_array($source, ['invoice', 'synthetic', 'refund', 'copy', 'advance'], true) || $id < 1) {
            throw new RuntimeException('Veza sa originalnim dokumentom nije ispravna.', 400);
        }
        $row = (new Store('', $source))->find($id);
        if (!$row || $row['state'] !== 'issued') { throw new RuntimeException('Za pregled dostave potreban je potvrđeno izdat dokument.', 404); }
        if ($source === 'advance' && $row['kind'] === 'close') {
            throw new RuntimeException('Interno zatvaranje avansa ne dostavlja se kupcu, ni kroz kopiju.', 403);
        }
        if ($source === 'invoice') { return ['invoice_id' => $id, 'row' => $row]; }
        if (in_array($source, ['refund', 'copy'], true)) {
            $parent = self::context((string) $row['parent_source'], (int) $row['parent_id'], $depth + 1);
            return ['invoice_id' => $parent['invoice_id'], 'row' => $row];
        }
        return ['invoice_id' => null, 'row' => $row];
    }
    public static function link(string $source, int $id): string
    {
        try { self::context($source, $id); }
        catch (RuntimeException) { return ''; }
        return Ui::button(self::url($source, $id), 'Pregled dostave →');
    }
    private static function line(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text));
    }
    public static function load(array $input): array
    {
        if (!Admin::allowed()) { throw new RuntimeException('Nemaš pristup modulu.', 403); }
        $source = $input['source'] ?? null; $id = $input['documentid'] ?? null;
        if (!is_string($source) || !is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) || (int) $id > 2147483647) {
            throw new RuntimeException('Neispravan izvor ili ID dokumenta.', 400);
        }
        $context = self::context($source, (int) $id); $row = $context['row'];
        $response = Receipt::decode($row['response']); $notes = Advance::notes($source, $row);
        $recipient = 'test@example.invalid'; $number = ''; $clientId = null; $recipientValid = false; $warnings = [];
        if ($context['invoice_id'] !== null) {
            $recipient = '';
            try {
                $invoice = Capsule::table('tblinvoices as i')->leftJoin('tblclients as c', 'c.id', '=', 'i.userid')
                    ->where('i.id', $context['invoice_id'])->first(['i.invoicenum', 'i.userid', 'c.id as client_id', 'c.email']);
                if ($invoice) {
                    $number = trim((string) ($invoice->invoicenum ?? '')); $clientId = $invoice->client_id === null ? null : (int) $invoice->client_id;
                    $recipient = trim((string) ($invoice->email ?? ''));
                    $recipientValid = $clientId !== null && !preg_match('/[\r\n]/', $recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;
                }
            } catch (\Throwable) { $warnings[] = 'WHMCS podaci o primaocu trenutno nisu dostupni.'; }
            if (!$recipientValid) { $warnings[] = 'Nije pronađena važeća email adresa klijenta povezane WHMCS fakture.'; }
        } else { $warnings[] = 'Zaseban test nema vezu sa WHMCS kupcem. Prikazana je demonstraciona .invalid adresa.'; }
        $qrValid = Receipt::qr($response) !== '';
        $verify = Checks::verificationUrl((string) ($response['verificationUrl'] ?? ''));
        if (!$qrValid) { $warnings[] = 'U sačuvanom odgovoru nedostaje ispravan QR. Dokument nije kompletan za dostavu.'; }
        if ($verify === '') { $warnings[] = 'U sačuvanom odgovoru nedostaje dozvoljen link za proveru.'; }
        $type = match ($source) { 'refund' => 'Dokument refundacije', 'copy' => 'Kopija računa',
            'advance' => $row['kind'] === 'return' ? 'Povraćaj avansa' : ($row['kind'] === 'payment' ? 'Avansni račun' : 'Konačni račun'), default => 'Fiskalni račun' };
        $subject = self::line('[SANDBOX] ' . $type . ' · ' . ($number !== '' ? $number : $response['invoiceNumber']));
        $lines = ['Poštovani,', 'Ovo je test poruka. Dokument nije za stvarni promet.',
            'U prilogu je ' . mb_strtolower($type) . ' iz razvojnog okruženja ONET Fiskal.',
            'PFR broj: ' . self::line($response['invoiceNumber'])];
        if ($number !== '') { $lines[] = 'WHMCS račun: ' . self::line($number); }
        if ($verify !== '') { $lines[] = 'Provera test dokumenta: ' . $verify; }
        if (in_array($source, ['refund', 'advance'], true) && ($source === 'refund' || $row['kind'] === 'return')) {
            $lines[] = 'Dokument refundacije sam po sebi nije potvrda bankarske isplate.';
        }
        $lines[] = 'ONET / United Tech Group DOO';
        $text = implode("\n\n", $lines); $html = '<div class="of-mail-message">';
        foreach ($lines as $line) { $html .= '<p>' . Admin::e($line) . '</p>'; }
        $html .= '</div>';
        $attachments = [
            ['name' => 'ONET-sandbox-' . $source . '-' . (int) $id . '.html', 'type' => 'text/html; charset=UTF-8', 'body' => Receipt::document($response, $notes)],
            ['name' => 'ONET-sandbox-' . $source . '-' . (int) $id . '.txt', 'type' => 'text/plain; charset=UTF-8', 'body' => $response['journal']],
        ];
        foreach ($attachments as &$attachment) { $attachment['sha256'] = hash('sha256', $attachment['body']); $attachment['bytes'] = strlen($attachment['body']); } unset($attachment);
        return ['source' => $source, 'id' => (int) $id, 'invoice_id' => $context['invoice_id'], 'invoice_number' => $number,
            'client_id' => $clientId, 'recipient' => $recipient, 'recipient_valid' => $recipientValid,
            'demo_recipient' => $context['invoice_id'] === null, 'send_enabled' => false, 'subject' => $subject,
            'html' => $html, 'text' => $text, 'response' => $response, 'qr_valid' => $qrValid, 'warnings' => $warnings, 'attachments' => $attachments];
    }
    public static function render(array $input): void
    {
        echo Ui::heading('Pregled dostave', 'Proveri primaoca, sadržaj poruke i sačuvani dokument pre budućeg uključivanja slanja.');
        echo Ui::note('Slanje je isključeno', 'Ovo je administratorski pregled. Ne šalje mejl, ne dodaje zadatak za slanje i ne izdaje novi fiskalni dokument.', 'sandbox');
        if (!isset($input['documentid'])) {
            echo Ui::card('Izaberi već izdat dokument', '<p>U žurnalu otvori izdat dokument i izaberi „Pregled dostave”. Interna zatvaranja avansa ne dostavljaju se kupcu.</p>'
                . Ui::button('addonmodules.php?module=onetfiskal&view=journal&state=issued', 'Otvori žurnal izdatih dokumenata →', 'primary')); return;
        }
        try {
            $p = self::load($input);
            foreach ($p['warnings'] as $warning) { echo Ui::note('Potrebna pažnja', $warning, 'sandbox'); }
            $meta = '<dl class="of-mail-meta"><dt>Primalac</dt><dd>' . Admin::e($p['recipient'] ?: 'Nije utvrđen') . '</dd><dt>Naslov</dt><dd>' . Admin::e($p['subject'])
                . '</dd><dt>WHMCS veza</dt><dd>' . ($p['invoice_id'] ? Ui::invoiceIdentity($p['invoice_id'], $p['invoice_number']) : 'Zaseban sandbox test')
                . '</dd><dt>Pošiljalac</dt><dd>Slanje nije podešeno u ovoj verziji</dd></dl>';
            echo Ui::card('Poruka kupcu · pregled', $meta . $p['html'] . Ui::technical('Tekstualna verzija poruke', $p['text']));
            $rows = ''; $checksums = [];
            foreach ($p['attachments'] as $a) { $rows .= '<tr><td>' . Admin::e($a['name']) . '<small>' . Admin::e($a['type']) . '</small></td><td class="of-num">' . $a['bytes'] . ' B</td></tr>'; $checksums[$a['name']] = $a['sha256']; }
            echo Ui::card('Prilozi iz sačuvanog fiskalnog odgovora', '<div class="of-table-wrap"><table class="of-table"><thead><tr><th>Datoteka</th><th class="of-num">Veličina</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
                . Receipt::links($p['source'], $p['id']) . Ui::technical('Kontrolni podaci priloga (SHA-256)', $checksums), 'HTML sadrži sačuvani QR; TXT čuva originalni žurnal. PDF se za sada čuva preko prikaza za štampu — nije serverski generisan prilog.');
            echo '<div class="of-actions">' . Ui::button($p['source'] === 'copy' ? CopyAdmin::url($p['id']) : CopyAdmin::originalUrl($p['source'], $p['id']), '← Vrati se na dokument') . '</div>';
        } catch (RuntimeException $e) { echo Ui::note('Pregled dostave nije dostupan', $e->getMessage(), 'danger'); }
    }
}
