<?php
declare(strict_types=1);
namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

/** One fictitious order / one received advance; returns and settlement are different operations. */
final class Advance
{
    public const ACTIVE = ['sending', 'unknown', 'issued'];
    public static function amount(mixed $value): int
    {
        $cents = Money::cents($value);
        if ($cents < 1 || $cents > 9999999900) { throw new RuntimeException('Unesi pozitivan iznos do 99.999.999,00 RSD.'); }
        return $cents;
    }
    public static function method(mixed $value): int
    {
        if (!in_array($value, [2, 4, '2', '4'], true)) { throw new RuntimeException('Podržani su kartica (2) i prenos na račun (4).'); }
        return (int) $value;
    }
    public static function received(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $value)) {
            throw new RuntimeException('Unesi datum i vreme test uplate.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone('Europe/Belgrade'));
        if (!$date || $date->format('Y-m-d\TH:i') !== $value || $date->getTimestamp() > time()
            || $date->getTimestamp() < time() - 31 * 86400) {
            throw new RuntimeException('Datum test uplate mora biti u prethodnih 31 dan, bez budućeg vremena.');
        }
        return $date->format('c');
    }
    public static function documents(int $group, bool $locked = false): array
    {
        $q = Capsule::table(Store::ADVANCES)->where('group_id', $group)->orderBy('id');
        if ($locked) { $q->lockForUpdate(); }
        return array_map(static fn($x) => (array) $x, $q->get()->all());
    }
    public static function ledger(array $docs): array
    {
        $payment = null; $close = null; $final = null; $returned = 0;
        foreach ($docs as $row) {
            if (!in_array($row['state'], self::ACTIVE, true)) { continue; }
            if ($row['kind'] === 'payment') { $payment = $row; }
            elseif ($row['kind'] === 'return') { $returned += (int) $row['amount_cents']; }
            elseif ($row['kind'] === 'close') { $close = $row; }
            elseif ($row['kind'] === 'final') { $final = $row; }
        }
        return ['payment' => $payment, 'close' => $close, 'final' => $final, 'returned' => $returned,
            'remaining' => max(0, ($payment && $payment['state'] === 'issued' ? (int) $payment['amount_cents'] : 0)
                - $returned - ($close ? (int) $close['amount_cents'] : 0))];
    }
    private static function reference(array $row): array
    {
        $r = Receipt::decode($row['response']);
        if ($row['state'] !== 'issued' || !is_string($r['sdcDateTime'] ?? null)) { throw new RuntimeException('Referentni dokument nije potvrđen.'); }
        return ['referentDocumentNumber' => $r['invoiceNumber'], 'referentDocumentDT' => $r['sdcDateTime']];
    }
    public static function plan(string $kind, array $root, array $docs, array $input, int $admin): array
    {
        $ledger = self::ledger($docs); $payment = $ledger['payment'];
        $payload = ['dateAndTimeOfIssue' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))->format('c'),
            'cashier' => 'WHMCS admin ' . $admin, 'buyerId' => $root['buyer_id'], 'invoiceType' => 4, 'transactionType' => 0,
            'options' => ['omitQRCodeGen' => '0', 'omitTextualRepresentation' => '0']];
        $meta = []; $method = $root['method'];
        if ($kind === 'payment') {
            if ($payment) { throw new RuntimeException('Za ovu test porudžbinu avans je već poslat.'); }
            $amount = $root['amount_cents']; $payload['dateAndTimeOfIssue'] = $root['received'];
            $meta['notes'] = ['Namena test avansa: ' . $root['description']];
        } else {
            if (!$payment || $payment['state'] !== 'issued') { throw new RuntimeException('Prvo potvrdi izdavanje avansa.'); }
            if ($kind === 'final') {
                $close = $ledger['close'];
                if (!$close || $close['state'] !== 'issued' || $ledger['final']) { throw new RuntimeException('Potreban je potvrđen interni dokument zatvaranja, bez već poslatog konačnog računa.'); }
                $closeMeta = json_decode($close['meta'], true, 512, JSON_THROW_ON_ERROR);
                $closeResponse = Receipt::decode($close['response']);
                $tax = 0;
                if (!is_array($closeResponse['taxItems'] ?? null)) { throw new RuntimeException('Interni odgovor nema PDV podatke; proveri sačuvan odgovor pre konačnog računa.'); }
                foreach ($closeResponse['taxItems'] as $item) { $tax += Money::cents($item['amount']); }
                $amount = $closeMeta['final_cents']; $method = $closeMeta['topup_method'];
                $payload['invoiceType'] = 0;
                $payload += self::reference($close);
                $meta = $closeMeta; $meta['advance_tax_cents'] = $tax;
                $meta['notes'] = ['Plaćeno avansno: ' . self::money($meta['advance_cents']) . ' RSD',
                    'PDV na zatvoreni avans: ' . self::money($tax) . ' RSD',
                    'Doplata uz ovaj konačni račun: ' . self::money($meta['topup_cents']) . ' RSD',
                    'Preostalo za plaćanje posle potvrđene test doplate: 0,00 RSD',
                    'Poslednji avansni račun: ' . $meta['last_advance_number'] . ' · ' . $meta['last_advance_time']];
            } elseif (in_array($kind, ['return', 'close'], true)) {
                if ($ledger['close'] || $ledger['final'] || $ledger['remaining'] < 1) {
                    throw new RuntimeException('Avans je zatvoren, potrošen ili čeka potvrdu drugog postupka.');
                }
                $payload['transactionType'] = 1; $payload += self::reference($payment);
                if ($kind === 'return') {
                    $amount = self::amount($input['amount'] ?? ''); $method = self::method($input['method'] ?? '');
                    $reason = trim(is_string($input['reason'] ?? null) ? $input['reason'] : '');
                    if ($reason === '' || mb_strlen($reason) > 300 || preg_match('/[\x00-\x1F\x7F]/u', $reason)) { throw new RuntimeException('Unesi razlog test povraćaja do 300 znakova.'); }
                    if ($amount > $ledger['remaining']) { throw new RuntimeException('Povraćaj prelazi preostali iznos avansa.'); }
                    $meta = ['reason' => $reason, 'notes' => ['Stvarni tok povraćaja kupcu — ovde samo sandbox test. Banka nije pozvana.']];
                } else {
                    $amount = $ledger['remaining']; $final = self::amount($input['final_amount'] ?? '');
                    if ($final < $amount) { throw new RuntimeException('Konačni iznos je manji od preostalog avansa. Prvo zasebno obradi višak kao povraćaj.'); }
                    $ref = self::reference($payment);
                    $meta = ['final_cents' => $final, 'advance_cents' => $amount, 'topup_cents' => $final - $amount,
                        'topup_method' => self::method($input['topup_method'] ?? ''),
                        'last_advance_number' => $ref['referentDocumentNumber'], 'last_advance_time' => $ref['referentDocumentDT'],
                        'notes' => ['INTERNI DOKUMENT ZATVARANJA AVANSA — ne dostavljati kupcu.',
                            'Ovo nije bankarski povraćaj. Nastavi izdavanjem konačnog računa.']];
                }
            } else { throw new RuntimeException('Nepoznat avansni postupak.'); }
        }
        $price = Money::number($amount);
        $payload['items'] = [['name' => $kind === 'final' ? $root['description'] : '10: Аванс (' . $root['tax_label'] . ')',
            'quantity' => 1, 'unitPrice' => $price, 'labels' => [$root['tax_label']], 'totalAmount' => $price]];
        $payload['payment'] = [['amount' => $kind === 'final' ? Money::number($meta['topup_cents']) : $price, 'paymentType' => $method]];
        return ['payload' => $payload, 'meta' => $meta, 'amount_cents' => $amount];
    }
    public static function money(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
    public static function notes(string $source, array $row): array
    {
        if ($source === 'advance') { return json_decode($row['meta'] ?? '{}', true)['notes'] ?? []; }
        if ($source === 'copy' && ($row['parent_source'] ?? '') === 'advance') {
            $p = (new Store('', 'advance'))->find((int) $row['parent_id']);
            return $p ? self::notes('advance', $p) : [];
        }
        return [];
    }
}

final class AdvanceStore implements Repository
{
    private Store $store;
    public function __construct(private array $config) { $this->store = new Store(hash_file('sha256', $config['pfx_path']), 'advance'); }
    public function find(int $id): ?array { return $this->store->find($id); }
    public function queue(int $id): void { $this->store->queue($id); }
    public function finish(int $id, string $state, mixed $response, string $message, bool $release): void { $this->store->finish($id, $state, $response, $message, $release); }
    private static function key(string $key): void { if (!preg_match('/^[a-f0-9]{32}$/D', $key)) { throw new RuntimeException('Neispravan nacrt.'); } }
    private function compatible(array $root): void
    {
        if (($this->config['environment'] ?? '') !== 'sandbox' || ($this->config['vsdc_url'] ?? '') !== 'https://vsdc.sandbox.suf.purs.gov.rs'
            || $root['credential'] !== hash_file('sha256', $this->config['pfx_path']) || $root['uid'] !== $this->config['expected_uid']
            || $root['tax_label'] !== $this->config['tax_label'] || $root['vat_rate'] !== Checks::vatRate($this->config)) {
            throw new RuntimeException('Vrati originalni sandbox PFX, JID i poresku konfiguraciju ove test porudžbine.');
        }
    }
    public function create(array $input, int $admin): array
    {
        $key = (string) ($input['draft_key'] ?? ''); self::key($key);
        return Capsule::connection()->transaction(function () use ($input, $admin, $key): array {
            $group = Capsule::table(Store::ADVANCE_GROUPS)->where('root_key', $key)->first();
            if (!$group) {
                $scenario = $input['scenario'] ?? '';
                if (!in_array($scenario, ['hosting', 'domain'], true)) { throw new RuntimeException('Izaberi test hosting ili domen.'); }
                $buyer = $input['buyer_id'] ?? '';
                if (!is_string($buyer) || !preg_match('/^20:[0-9]{9}$/D', $buyer)) { throw new RuntimeException('Koristi izmišljeni test ID 20: i devet cifara.'); }
                $root = ['amount_cents' => Advance::amount($input['amount'] ?? ''), 'buyer_id' => $buyer,
                    'method' => Advance::method($input['method'] ?? ''), 'received' => Advance::received($input['received'] ?? ''),
                    'description' => 'SANDBOX TEST — ' . ($scenario === 'hosting' ? 'hosting' : 'domen') . ' — izvršen promet iz avansa',
                    'credential' => hash_file('sha256', $this->config['pfx_path']), 'uid' => $this->config['expected_uid'],
                    'tax_label' => $this->config['tax_label'], 'vat_rate' => Checks::vatRate($this->config)];
                $this->compatible($root);
                Capsule::table(Store::ADVANCE_GROUPS)->insertOrIgnore(['root_key' => $key, 'root' => json_encode($root, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'admin_id' => $admin, 'created_at' => gmdate('Y-m-d H:i:s')]);
                $group = Capsule::table(Store::ADVANCE_GROUPS)->where('root_key', $key)->first();
            }
            if (!$group || (int) $group->admin_id !== $admin) { throw new RuntimeException('Test porudžbina pripada drugom administratoru.'); }
            return $this->draft((int) $group->id, 'payment', $key, [], $admin);
        });
    }
    public function draft(int $groupId, string $kind, string $key, array $input, int $admin): array
    {
        self::key($key);
        $allowed = match ($kind) { 'return' => ['amount', 'method', 'reason'], 'close' => ['final_amount', 'topup_method'], default => [] };
        $input = array_intersect_key($input, array_flip($allowed));
        return Capsule::connection()->transaction(function () use ($groupId, $kind, $key, $input, $admin): array {
            $group = Capsule::table(Store::ADVANCE_GROUPS)->where('id', $groupId)->lockForUpdate()->first();
            if (!$group) { throw new RuntimeException('Test porudžbina ne postoji.'); }
            $existing = Capsule::table(Store::ADVANCES)->where('draft_key', $key)->first();
            if ($existing) {
                if ((int) $existing->group_id !== $groupId || $existing->kind !== $kind || (int) $existing->admin_id !== $admin) { throw new RuntimeException('Nacrt pripada drugom postupku ili administratoru.'); }
                return (array) $existing;
            }
            $root = json_decode($group->root, true, 512, JSON_THROW_ON_ERROR); $this->compatible($root);
            $docs = Advance::documents($groupId, true);
            if ($kind === 'payment' && array_filter($docs, static fn($d) => $d['kind'] === 'payment')) { throw new RuntimeException('Jedna test porudžbina podržava jednu avansnu uplatu.'); }
            $plan = Advance::plan($kind, $root, $docs, $input, $admin);
            for ($attempt = 0; $attempt < 5; $attempt++) {
                Capsule::table(Store::ADVANCES)->insertOrIgnore(['invoice_id' => random_int(1, 2147483647), 'group_id' => $groupId,
                    'kind' => $kind, 'draft_key' => $key, 'state' => 'review', 'admin_id' => $admin, 'amount_cents' => $plan['amount_cents'],
                    'request_input' => json_encode($input, JSON_THROW_ON_ERROR), 'meta' => json_encode($plan['meta'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'payload' => json_encode($plan['payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
                $row = Capsule::table(Store::ADVANCES)->where('draft_key', $key)->first();
                if ($row) { return (array) $row; }
            }
            throw new RuntimeException('Nacrt avansnog dokumenta nije sačuvan.');
        });
    }
    public function claim(int $id, array $payload, int $admin): array
    {
        return Capsule::connection()->transaction(function () use ($id, $payload, $admin): array {
            Capsule::table(Store::GUARD)->where('id', 1)->lockForUpdate()->first();
            $peek = Capsule::table(Store::ADVANCES)->where('invoice_id', $id)->first();
            if (!$peek) { throw new RuntimeException('Nacrt ne postoji.'); }
            $group = Capsule::table(Store::ADVANCE_GROUPS)->where('id', $peek->group_id)->lockForUpdate()->first();
            $docs = Advance::documents((int) $peek->group_id, true);
            $found = array_values(array_filter($docs, static fn($d) => (int) $d['invoice_id'] === $id));
            $row = (object) ($found[0] ?? []);
            if (($row->state ?? '') !== 'review' || (int) $row->admin_id !== $admin) { throw new RuntimeException('Nacrt nije dostupan za novo slanje.'); }
            $root = json_decode($group->root, true, 512, JSON_THROW_ON_ERROR); $this->compatible($root);
            $plan = Advance::plan($row->kind, $root, $docs, json_decode($row->request_input, true, 512, JSON_THROW_ON_ERROR), $admin);
            $plan['payload']['dateAndTimeOfIssue'] = $payload['dateAndTimeOfIssue'] ?? '';
            if (json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR) !== $payload || json_encode($plan['payload']) !== json_encode($payload)
                || json_decode($row->meta, true, 512, JSON_THROW_ON_ERROR) !== $plan['meta'] || (int) $row->amount_cents !== $plan['amount_cents']
                || strtotime($row->created_at . ' UTC') < time() - 3600) {
                throw new RuntimeException('Pregled je promenjen, zastareo ili saldo više ne odgovara. Napravi novi pregled bez ponavljanja poslatog zahteva.');
            }
            return $this->store->claim($id, $payload, $admin);
        });
    }
    public static function recordMoney(int $id, string $reference, int $admin): void
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $reference)) { throw new RuntimeException('Unesi test referencu do 120 znakova.'); }
        $n = Capsule::table(Store::ADVANCES)->where('invoice_id', $id)->where('kind', 'return')->where('state', 'issued')->whereNull('money_recorded_at')
            ->update(['money_reference' => $reference, 'money_admin' => $admin, 'money_recorded_at' => gmdate('Y-m-d H:i:s')]);
        if (!$n) { throw new RuntimeException('Evidencija već postoji ili ovo nije potvrđen povraćaj novca kupcu.'); }
    }
}
