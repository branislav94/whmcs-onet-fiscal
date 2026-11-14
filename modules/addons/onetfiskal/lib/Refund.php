<?php
declare(strict_types=1);
namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

final class Refund
{
    public static function parent(string $source, int $id): array
    {
        if (!in_array($source, ['invoice', 'synthetic'], true) || $id < 1) {
            throw new RuntimeException('Refundacija je dozvoljena samo za originalnu prodaju iz ovog modula.');
        }
        $row = (new Store('', $source))->find($id);
        if (!$row || $row['state'] !== 'issued') { throw new RuntimeException('Original nije potvrđeno izdat.'); }
        $payload = json_decode((string) $row['payload'], true);
        $response = Receipt::decode($row['response']);
        if (!is_array($payload) || ($payload['invoiceType'] ?? null) !== 0 || ($payload['transactionType'] ?? null) !== 0
            || empty($payload['items']) || empty($response['sdcDateTime']) || empty($row['credential'])) {
            throw new RuntimeException('Original nema kompletnu evidenciju Promet–Prodaje za refundaciju.');
        }
        foreach ($payload['items'] as $item) {
            if ((float) ($item['quantity'] ?? 0) !== 1.0 || Money::cents($item['unitPrice']) !== Money::cents($item['totalAmount'])) {
                throw new RuntimeException('Ova verzija podržava samo originalne usluge sa količinom 1.');
            }
        }
        return ['row' => $row, 'payload' => $payload, 'response' => $response];
    }

    public static function quantity(mixed $value): int
    {
        if (!is_scalar($value) || !preg_match('/^(?:0|1)(?:\.[0-9]{1,3})?$/D', (string) $value)) {
            throw new RuntimeException('Količina mora biti između 0 i 1, sa najviše tri decimale i tačkom.');
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
        $n = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
        if ($n > 1000) { throw new RuntimeException('Količina ne može biti veća od originalne.'); }
        return $n;
    }

    public static function used(string $source, int $id, bool $locked = false): array
    {
        $used = [];
        $query = Capsule::table(Store::REFUNDS)->where('parent_source', $source)->where('parent_id', $id)
            ->whereIn('state', ['sending', 'unknown', 'issued']);
        // Locking reads see committed reservations after waiting for the shared guard, even under REPEATABLE READ.
        if ($locked) { $query->lockForUpdate(); }
        foreach ($query->get(['selection', 'payload']) as $r) {
            $selection = json_decode($r->selection, true, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($r->payload, true, 512, JSON_THROW_ON_ERROR);
            $index = 0;
            foreach ($selection as $key => $units) {
                if ($units === 0) { continue; }
                $used[$key]['quantity'] = ($used[$key]['quantity'] ?? 0) + $units;
                $used[$key]['cents'] = ($used[$key]['cents'] ?? 0) + Money::cents($payload['items'][$index++]['totalAmount']);
            }
        }
        return $used;
    }

    public static function build(array $parent, array $selection, string $buyer, int $method, int $adminId): array
    {
        if (!preg_match('/^20:[0-9]{9}$/D', $buyer)) {
            throw new RuntimeException('Za domaće fizičko lice unesi test ID 20: i devet cifara broja lične karte.');
        }
        if (!in_array($method, [2, 4], true)) {
            throw new RuntimeException('Podržani su samo povraćaj na karticu (2) ili prenos na račun (4).');
        }
        if (array_keys($selection) !== array_keys($parent['payload']['items'])) {
            throw new RuntimeException('Izbor stavki se ne slaže sa originalom.');
        }
        $total = 0; $items = [];
        foreach ($parent['payload']['items'] as $key => $item) {
            $units = $selection[$key];
            if (!is_int($units) || $units < 0 || $units > 1000) { throw new RuntimeException('Neispravna količina.'); }
            if ($units === 0) { continue; }
            $cents = intdiv(Money::cents($item['unitPrice']) * $units + 500, 1000);
            if ($cents < 1) { throw new RuntimeException('Izabrana stavka mora imati pozitivan iznos.'); }
            $item['quantity'] = $units / 1000;
            $item['totalAmount'] = Money::number($cents);
            $items[] = $item; $total += $cents;
        }
        if ($total < 1) { throw new RuntimeException('Izaberi najmanje jednu stavku za povraćaj.'); }
        return ['dateAndTimeOfIssue' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))->format('c'),
            'cashier' => 'WHMCS admin ' . $adminId, 'invoiceType' => 0, 'transactionType' => 1,
            'buyerId' => $buyer, 'referentDocumentNumber' => $parent['response']['invoiceNumber'],
            'referentDocumentDT' => $parent['response']['sdcDateTime'],
            'payment' => [['amount' => Money::number($total), 'paymentType' => $method]], 'items' => $items,
            'options' => ['omitQRCodeGen' => '0', 'omitTextualRepresentation' => '0']];
    }

    public static function checkBalance(array $parent, array $selection, array $used): void
    {
        foreach ($parent['payload']['items'] as $i => $item) {
            $units = ($used[$i]['quantity'] ?? 0) + $selection[$i];
            $cents = ($used[$i]['cents'] ?? 0) + intdiv(Money::cents($item['unitPrice']) * $selection[$i] + 500, 1000);
            if ($units > 1000 || $cents > Money::cents($item['totalAmount'])) {
                throw new RuntimeException('Povraćaj prelazi preostalu količinu ili iznos originalne stavke. Osveži pregled.');
            }
        }
    }
}

final class RefundStore implements Repository
{
    private Store $store;
    public function __construct(private array $config)
    {
        $this->store = new Store(hash_file('sha256', $config['pfx_path']), 'refund');
    }
    public function find(int $id): ?array { return $this->store->find($id); }
    public function queue(int $id): void { $this->store->queue($id); }
    public function finish(int $id, string $state, mixed $response, string $message, bool $release): void
    {
        $this->store->finish($id, $state, $response, $message, $release);
    }
    public function draft(string $source, int $parentId, array $input, int $adminId): array
    {
        $key = $input['draft_key'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) { throw new RuntimeException('Neispravan nacrt.'); }
        $existing = Capsule::table(Store::REFUNDS)->where('draft_key', $key)->first();
        if ($existing) {
            if ($existing->parent_source !== $source || (int) $existing->parent_id !== $parentId || (int) $existing->admin_id !== $adminId) {
                throw new RuntimeException('Nacrt pripada drugom originalu ili administratoru.');
            }
            return (array) $existing;
        }
        $parent = Refund::parent($source, $parentId);
        $raw = $input['quantities'] ?? null;
        if (!is_array($raw) || array_keys($raw) !== array_keys($parent['payload']['items'])) { throw new RuntimeException('Unesi količine svih stavki.'); }
        $selection = array_map([Refund::class, 'quantity'], $raw);
        $reason = trim(is_string($input['reason'] ?? null) ? $input['reason'] : '');
        if ($reason === '' || mb_strlen($reason) > 300 || preg_match('/[\x00-\x1F\x7F]/u', $reason)) { throw new RuntimeException('Unesi razlog do 300 znakova bez kontrolnih znakova.'); }
        $buyer = is_string($input['buyer_id'] ?? null) ? trim($input['buyer_id']) : '';
        $method = $input['payment_type'] ?? null;
        if (!in_array($method, ['2', '4', 2, 4], true)) { throw new RuntimeException('Izaberi način povraćaja.'); }
        $payload = Refund::build($parent, $selection, $buyer, (int) $method, $adminId);
        Refund::checkBalance($parent, $selection, Refund::used($source, $parentId));
        $this->compatible($parent, $payload);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            Capsule::table(Store::REFUNDS)->insertOrIgnore(['invoice_id' => random_int(1, 2147483647),
                'parent_source' => $source, 'parent_id' => $parentId, 'draft_key' => $key,
                'selection' => json_encode($selection, JSON_THROW_ON_ERROR), 'reason' => $reason,
                'state' => 'review', 'admin_id' => $adminId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $row = Capsule::table(Store::REFUNDS)->where('draft_key', $key)->first();
            if ($row) {
                if ($row->parent_source !== $source || (int) $row->parent_id !== $parentId || (int) $row->admin_id !== $adminId) {
                    throw new RuntimeException('Nacrt pripada drugom originalu ili administratoru.');
                }
                return (array) $row;
            }
        }
        throw new RuntimeException('Nacrt nije sačuvan.');
    }
    private function compatible(array $parent, array $payload): void
    {
        if (($this->config['environment'] ?? '') !== 'sandbox'
            || ($this->config['vsdc_url'] ?? '') !== 'https://vsdc.sandbox.suf.purs.gov.rs'
            || $parent['row']['credential'] !== hash_file('sha256', $this->config['pfx_path'])
            || ($parent['response']['requestedBy'] ?? '') !== $this->config['expected_uid']) {
            throw new RuntimeException('Za refundaciju koristi isti originalni sandbox sertifikat i JID.');
        }
        foreach ($payload['items'] as $item) {
            if ($item['labels'] !== [$this->config['tax_label']]) { throw new RuntimeException('Poreska oznaka mora odgovarati originalnoj prodaji.'); }
        }
    }
    public function claim(int $id, array $payload, int $adminId): array
    {
        return Capsule::connection()->transaction(function () use ($id, $payload, $adminId): array {
            // All fiscal sources take the same guard first; concurrent refunds recheck balance under this lock.
            Capsule::table(Store::GUARD)->where('id', 1)->lockForUpdate()->first();
            $row = Capsule::table(Store::REFUNDS)->where('invoice_id', $id)->lockForUpdate()->first();
            if (!$row || $row->state !== 'review' || (int) $row->admin_id !== $adminId) { throw new RuntimeException('Nacrt nije dostupan za novo izdavanje.'); }
            $table = $row->parent_source === 'invoice' ? Store::TABLE : Store::TESTS;
            Capsule::table($table)->where('invoice_id', $row->parent_id)->lockForUpdate()->first();
            $parent = Refund::parent($row->parent_source, (int) $row->parent_id);
            $selection = json_decode($row->selection, true, 512, JSON_THROW_ON_ERROR);
            $saved = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($saved !== $payload) { throw new RuntimeException('Pregled refundacije se promenio.'); }
            $expected = Refund::build($parent, $selection, $saved['buyerId'], $saved['payment'][0]['paymentType'], $adminId);
            $expected['dateAndTimeOfIssue'] = $saved['dateAndTimeOfIssue'];
            if (json_encode($expected) !== json_encode($saved) || strtotime($row->created_at . ' UTC') < time() - 3600) {
                throw new RuntimeException('Nacrt je zastareo ili se ne slaže sa originalom. Napravi novi pregled.');
            }
            $this->compatible($parent, $payload);
            Refund::checkBalance($parent, $selection, Refund::used($row->parent_source, (int) $row->parent_id, true));
            return $this->store->claim($id, $payload, $adminId);
        });
    }
    public static function recordMoney(int $id, string $reference, int $adminId): void
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $reference)) {
            throw new RuntimeException('Unesi referencu test povraćaja, do 120 znakova.');
        }
        $changed = Capsule::table(Store::REFUNDS)->where('invoice_id', $id)->where('state', 'issued')->whereNull('money_recorded_at')
            ->update(['money_reference' => $reference, 'money_admin' => $adminId, 'money_recorded_at' => gmdate('Y-m-d H:i:s')]);
        if (!$changed) { throw new RuntimeException('Povraćaj već ima evidenciju ili fiskalni dokument nije potvrđen.'); }
    }
}
