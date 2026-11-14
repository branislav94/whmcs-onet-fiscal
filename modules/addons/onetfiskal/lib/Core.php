<?php
declare(strict_types=1);

namespace Onet\Fiskal;

use RuntimeException;

final class Money
{
    public static function cents(mixed $value): int
    {
        $text = (string) $value;
        if (!preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/D', $text, $m)) {
            throw new RuntimeException('Neispravan iznos; potrebne su najviše dve decimale.');
        }
        $amount = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        return ($m[1] === '-') ? -$amount : $amount;
    }

    public static function number(int $cents): float
    {
        return $cents / 100;
    }
}

final class Builder
{
    /** One WHMCS invoice row is one billed service; WHMCS amounts are line totals. */
    public static function build(array $s, array $config, string $cashier): array
    {
        $i = $s['invoice'];
        $c = $s['client'];
        $vatRate = Checks::vatRate($config);
        if ($s['currency'] !== 'RSD' || $s['tax_type'] !== 'Exclusive') {
            throw new RuntimeException('v0.1 podržava RSD i WHMCS Tax Type: Exclusive.');
        }
        if ($i['status'] !== 'Paid' || Money::cents($i['credit']) !== 0) {
            throw new RuntimeException('Potrebna je plaćena faktura bez korišćenja kredita sa naloga.');
        }
        if (($c['country'] ?? '') !== 'RS' || trim((string) ($c['companyname'] ?? '')) !== ''
            || trim((string) ($c['tax_id'] ?? '')) !== '') {
            throw new RuntimeException('v0.1 je ograničen na domaća fizička lica bez PIB-a. B2B/ino promet zahteva drugi tok.');
        }
        if (!is_numeric($i['taxrate']) || (float) $i['taxrate'] !== (float) $vatRate
            || !is_numeric($i['taxrate2']) || (float) $i['taxrate2'] !== 0.0
            || Money::cents($i['tax2']) !== 0) {
            throw new RuntimeException('Faktura ima stopu ' . (string) $i['taxrate'] . '%, a podešena sandbox VAT stopa je '
                . $vatRate . '%. Za nezavisan test otvori Zaseban sandbox test; ne menjaj postojeću fakturu.');
        }
        $total = Money::cents($i['total']);
        $net = Money::cents($i['subtotal']);
        $tax = Money::cents($i['tax']);
        if ($net <= 0 || $total !== $net + $tax || $tax !== intdiv($net * $vatRate + 50, 100)) {
            throw new RuntimeException('Osnovica, PDV i ukupan iznos fakture nisu usaglašeni.');
        }
        $txs = $s['transactions'];
        if (count($txs) !== 1 || Money::cents($txs[0]['amountin']) !== $total
            || Money::cents($txs[0]['amountout']) !== 0) {
            throw new RuntimeException('v0.1 zahteva tačno jednu punu uplatu, bez refundacije ili delimičnih uplata.');
        }
        $gateway = (string) $txs[0]['gateway'];
        $method = $config['payment_map'][$gateway] ?? null;
        if (!is_int($method) || !in_array($method, [0, 1, 2, 3, 4, 5, 6], true)) {
            throw new RuntimeException('Gateway "' . $gateway . '" nema celobrojno mapiranje u payment_map. '
                . 'Primeri: prenos na račun 4, kartica 2. Broj mora biti bez navodnika.');
        }
        if (count($s['items']) < 1 || count($s['items']) > 250) {
            throw new RuntimeException('Podržano je od 1 do 250 stavki.');
        }
        $sum = 0;
        $allocated = 0;
        $lines = [];
        foreach ($s['items'] as $row) {
            $amount = Money::cents($row['amount']);
            $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $row['description'])) ?? '');
            if ($amount <= 0 || (int) $row['taxed'] !== 1 || $name === '' || mb_strlen($name) > 2048) {
                throw new RuntimeException('Sve stavke moraju biti pozitivne, oporezive i imati važeći opis. Popusti kao negativne stavke nisu podržani.');
            }
            $lineTax = intdiv($amount * $vatRate, 100);
            $lines[] = ['name' => $name, 'net' => $amount, 'tax' => $lineTax, 'remainder' => ($amount * $vatRate) % 100];
            $sum += $amount;
            $allocated += $lineTax;
        }
        if ($sum !== $net) {
            throw new RuntimeException('Zbir stavki se ne slaže sa subtotal iznosom; proveri količine i popuste.');
        }
        // Allocate aggregate VAT cents by largest remainder; never alter the invoice total.
        $indexes = array_keys($lines);
        usort($indexes, fn(int $a, int $b): int => ($lines[$b]['remainder'] <=> $lines[$a]['remainder']) ?: ($a <=> $b));
        $left = $tax - $allocated;
        if ($left < 0 || $left > count($lines)) {
            throw new RuntimeException('PDV nije moguće raspodeliti po stavkama.');
        }
        for ($n = 0; $n < $left; $n++) {
            $lines[$indexes[$n]]['tax']++;
        }
        $items = [];
        foreach ($lines as $line) {
            $gross = Money::number($line['net'] + $line['tax']);
            $items[] = ['name' => $line['name'], 'quantity' => 1, 'unitPrice' => $gross,
                'labels' => [$config['tax_label']], 'totalAmount' => $gross];
        }
        return [
            'dateAndTimeOfIssue' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))->format('c'),
            'cashier' => mb_substr($cashier, 0, 50), 'invoiceType' => 0, 'transactionType' => 0,
            'payment' => [['amount' => Money::number($total), 'paymentType' => $method]],
            'items' => $items,
            'options' => ['omitQRCodeGen' => '0', 'omitTextualRepresentation' => '0'],
        ];
    }
}

final class Checks
{
    public static function reviewHash(array $snapshot, array $config): string
    {
        return hash('sha256', json_encode([$snapshot, self::vatRate($config), $config['tax_label'],
            $config['payment_map'], $config['expected_uid']], JSON_THROW_ON_ERROR));
    }

    public static function vatRate(array $config): int
    {
        $rate = $config['vat_rate'] ?? 20;
        if (!is_int($rate) || !in_array($rate, [19, 20], true)) {
            throw new RuntimeException('vat_rate mora biti ceo broj 19 ili 20, prema sandbox VAT podacima.');
        }
        return $rate;
    }

    public static function status(array $status, array $config): void
    {
        $vatRate = self::vatRate($config);
        if (($status['uid'] ?? '') !== $config['expected_uid']) {
            throw new RuntimeException('JID odgovora ne odgovara expected_uid iz konfiguracije.');
        }
        $groups = $status['currentTaxRates'] ?? [];
        if (isset($groups['taxCategories'])) {
            $groups = [$groups];
        }
        $available = [];
        foreach ($groups as $group) {
            foreach ($group['taxCategories'] ?? [] as $cat) {
                if (strtoupper(trim((string) ($cat['name'] ?? ''))) !== 'VAT'
                    || (int) ($cat['categoryType'] ?? -1) !== 0) {
                    continue;
                }
                foreach ($cat['taxRates'] ?? [] as $rate) {
                    $available[] = (string) ($rate['label'] ?? '') . '=' . (string) ($rate['rate'] ?? '?') . '%';
                    if (($rate['label'] ?? '') === $config['tax_label'] && (float) ($rate['rate'] ?? -1) === (float) $vatRate) {
                        return;
                    }
                }
            }
        }
        throw new RuntimeException('Podešena oznaka nije aktivna sandbox VAT stopa ' . $vatRate . '%. Aktivne VAT oznake: '
            . implode(', ', array_unique($available)) . '. Uskladi vat_rate i tax_label sa sandbox podacima.');
    }

    public static function signed(array $result, array $payload, array $config): bool
    {
        if (!isset($result['totalAmount']) || !is_numeric($result['totalAmount'])) {
            return false;
        }
        $sum = array_sum(array_map(fn(array $x): int => Money::cents($x['totalAmount']), $payload['items']));
        try {
            $matches = Money::cents($result['totalAmount']) === $sum;
        } catch (\Throwable) {
            return false;
        }
        return $matches && ($result['requestedBy'] ?? '') === $config['expected_uid']
            && preg_match('/^[A-Za-z0-9]{8}-[A-Za-z0-9]{8}-\d+$/D', (string) ($result['invoiceNumber'] ?? '')) === 1
            && !empty($result['journal']) && !empty($result['sdcDateTime'])
            && !empty($result['signature']) && self::verificationUrl((string) ($result['verificationUrl'] ?? '')) !== '';
    }

    public static function verificationUrl(string $url): string
    {
        $p = parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https'
            && ($p['host'] ?? '') === 'sandbox.suf.purs.gov.rs' && ($p['path'] ?? '') === '/v/'
            && (!isset($p['port']) || $p['port'] === 443)
            && !isset($p['user']) && !isset($p['pass']) ? $url : '';
    }
}

interface Transport
{
    /** @return array{code:int,data:mixed} */
    public function request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array;
}

interface Repository
{
    public function find(int $invoiceId): ?array;
    public function queue(int $invoiceId): void;
    public function claim(int $invoiceId, array $payload, int $adminId): array;
    public function finish(int $id, string $state, mixed $response, string $message, bool $release): void;
}

final class Service
{
    public function __construct(private Repository $repo, private Transport $api, private array $config) {}

    public function preflight(): array
    {
        $r = $this->api->request('GET', '/api/v3/status');
        if ($r['code'] !== 200 || !is_array($r['data'])) {
            $hint = in_array($r['code'], [401, 403], true)
                ? ' Proveri da li isti sandbox PFX, njegova lozinka i PAK imaju pristup V-PFR-u.'
                : ' Očekivan je JSON status. Proveri dostupnost zvaničnog sandbox endpointa.';
            throw new RuntimeException('Provera veze nije uspela. HTTP ' . $r['code'] . '.' . $hint);
        }
        Checks::status($r['data'], $this->config);
        return $r['data'];
    }

    public function issue(int $invoiceId, array $snapshot, int $adminId): array
    {
        $row = $this->repo->find($invoiceId);
        if ($row && $row['state'] === 'issued') {
            return $row;
        }
        if ($row && $row['state'] !== 'review') {
            throw new RuntimeException('Zahtev već postoji. Koristi proveru ishoda; ne šalji novi POST.');
        }
        $payload = Builder::build($snapshot, $this->config, 'WHMCS admin ' . $adminId);
        $this->preflight();
        $row = $this->repo->claim($invoiceId, $payload, $adminId);
        try {
            $r = $this->api->request('POST', '/api/v3/invoices', $payload, $row['request_id']);
        } catch (\Throwable) {
            $this->repo->finish((int) $row['id'], 'unknown', null,
                'Prekid komunikacije. Ishod nije poznat; proveri zahtev pre bilo kakvog novog izdavanja.', false);
            return $this->repo->find($invoiceId);
        }
        return $this->consume($row, $payload, $r, true);
    }

    public function reconcile(int $invoiceId): array
    {
        $row = $this->repo->find($invoiceId);
        if (!$row || !in_array($row['state'], ['sending', 'unknown'], true)) {
            throw new RuntimeException('Nema zahteva sa nepoznatim ishodom.');
        }
        if (isset($row['credential']) && $row['credential'] !== hash_file('sha256', $this->config['pfx_path'])) {
            throw new RuntimeException('Vrati isti PFX fajl koji je korišćen za prvobitni zahtev pre provere ishoda.');
        }
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        // A valid original TLS response may already be stored after an older validator rejected its format.
        // Recover that evidence before querying a last-invoice endpoint; never create another invoice.
        $saved = $row['response'] ?? null;
        if (is_string($saved)) {
            $saved = json_decode($saved, true);
        }
        if (is_array($saved) && Checks::signed($saved, $payload, $this->config)) {
            return $this->consume($row, $payload, ['code' => 200, 'data' => $saved], false);
        }
        // Same certificate must be retained until the uncertain request is resolved.
        $this->preflight();
        $r = $this->api->request('GET', '/api/v3/invoices/' . rawurlencode($row['request_id']));
        return $this->consume($row, $payload, $r, false);
    }

    private function consume(array $row, array $payload, array $r, bool $post): array
    {
        if ($r['code'] === 200 && is_array($r['data']) && Checks::signed($r['data'], $payload, $this->config)) {
            $this->repo->finish((int) $row['id'], 'issued', $r['data'], 'Sandbox račun je izdat.', true);
        } elseif ($post && in_array($r['code'], [400, 401, 403, 422], true)
            && is_array($r['data']) && isset($r['data']['message']) && empty($r['data']['invoiceNumber'])) {
            $this->repo->finish((int) $row['id'], 'rejected', $r['data'], 'V-PFR je odbio zahtev. HTTP ' . $r['code'], true);
        } else {
            $this->repo->finish((int) $row['id'], 'unknown', $r['data'],
                'Ishod nije potvrđen. HTTP ' . $r['code'] . '. Ne ponavljati POST. Potrebna provera u sandbox portalu.', false);
        }
        return $this->repo->find((int) $row['invoice_id']);
    }
}
