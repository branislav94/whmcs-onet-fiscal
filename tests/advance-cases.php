<?php
declare(strict_types=1);
use WHMCS\Database\Capsule;
use Onet\Fiskal\Store;
use Onet\Fiskal\Service;
use Onet\Fiskal\Transport;
use Onet\Fiskal\Advance;
use Onet\Fiskal\AdvanceStore;
use Onet\Fiskal\AdvanceAdmin;

function advanceInput(array $overrides = []): array {
    return array_merge(['draft_key' => bin2hex(random_bytes(16)), 'scenario' => 'hosting', 'amount' => '1190.00',
        'method' => '4', 'buyer_id' => '20:000000000',
        'received' => (new DateTimeImmutable('-1 minute', new DateTimeZone('Europe/Belgrade')))->format('Y-m-d\TH:i')], $overrides);
}
final class AdvanceApi implements Transport {
    public static int $serial = 300; public int $posts = 0; public int $gets = 0;
    public bool $timeout = false; public bool $reject = false; public bool $wrong = false;
    public ?string $requestId = null; public array $response = []; public array $payload = [];
    public function request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array {
        if ($path === '/api/v3/status') { return (new DbApi())->request($method, $path); }
        if ($method === 'POST') {
            $this->posts++; $this->payload = $payload; $this->requestId = $requestId;
            if ($this->reject) { return ['code' => 400, 'data' => ['message' => 'Test rejection']]; }
            $extension = $payload['invoiceType'] === 4 ? ($payload['transactionType'] === 1 ? 'АР' : 'АП') : 'ПП';
            $total = array_sum(array_map(static fn($i) => \Onet\Fiskal\Money::cents($i['totalAmount']), $payload['items']));
            $this->response = ['requestedBy' => 'ABCD1234', 'invoiceNumber' => 'ABCD1234-Dt1Ov2o0-' . (++self::$serial),
                'invoiceCounterExtension' => $this->wrong ? 'КП' : $extension, 'totalAmount' => \Onet\Fiskal\Money::number($total),
                'journal' => 'TEST ' . $extension . ' ' . $payload['buyerId'] . ' ' . ($payload['referentDocumentNumber'] ?? ''),
                'sdcDateTime' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Belgrade')))->format('c'),
                'taxItems' => [['amount' => \Onet\Fiskal\Money::number(intdiv($total * 19 + 59, 119))]],
                'signature' => 'mock-signature', 'verificationUrl' => 'https://sandbox.suf.purs.gov.rs/v/?vl=advance', 'verificationQRCode' => tinyGif()];
            if ($this->timeout) { throw new RuntimeException('Test interruption'); }
        } else { $this->gets++; equal('/api/v3/invoices/' . $this->requestId, $path); }
        return ['code' => 200, 'data' => $this->response];
    }
}
function advanceIssue(AdvanceStore $store, AdvanceApi $api, array $config, array $row): array {
    return (new Service($store, $api, $config))->submit((int) $row['invoice_id'], json_decode($row['payload'], true), 1);
}
function openAdvance(array $config, AdvanceApi $api, array $overrides = []): array {
    $store = new AdvanceStore($config); $row = $store->create(advanceInput($overrides), 1);
    return advanceIssue($store, $api, $config, $row);
}
$tests['advance received-date validation preserves older bank payment time and rejects future invalid values'] = function (): void {
    $old = (new DateTimeImmutable('-1 day', new DateTimeZone('Europe/Belgrade')))->format('Y-m-d\TH:i');
    equal(substr($old, 0, 16), substr(Advance::received($old), 0, 16));
    foreach (['', '2026-02-30T12:00', (new DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
        (new DateTimeImmutable('-40 days'))->format('Y-m-d\TH:i'), ['bad']] as $bad) { rejected(fn() => Advance::received($bad)); }
};
$tests['advance creation deduplicates the order and payment without storing CSRF or mutating WHMCS records'] = function () use ($config): void {
    resetDatabase(); $store = new AdvanceStore($config); $input = advanceInput(['onet_token' => 'secret-session-token']);
    $a = $store->create($input, 1); $b = $store->create($input, 1);
    equal($a['invoice_id'], $b['invoice_id']); equal(1, Capsule::table(Store::ADVANCE_GROUPS)->count()); equal(1, Capsule::table(Store::ADVANCES)->count());
    rejected(fn() => $store->create($input, 2));
    $p = json_decode($a['payload'], true); equal(4, $p['invoiceType']); equal(0, $p['transactionType']);
    equal('10: Аванс (Ж)', $p['items'][0]['name']); equal(1, $p['items'][0]['quantity']); equal(1190, $p['items'][0]['unitPrice']);
    equal(false, str_contains($a['request_input'], 'secret-session-token'));
    equal(0, Capsule::table(Store::TABLE)->count()); equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
$tests['advance accepts only fictitious domestic noncash sandbox inputs'] = function () use ($config): void {
    resetDatabase(); $store = new AdvanceStore($config);
    foreach ([['buyer_id' => ''], ['buyer_id' => '10:123456789'], ['amount' => '-1'], ['amount' => '0'],
        ['method' => 1], ['scenario' => 'real-client'], ['received' => 'bad']] as $bad) { rejected(fn() => $store->create(advanceInput($bad), 1)); }
    $bad = $config; $bad['environment'] = 'production'; rejected(fn() => (new AdvanceStore($bad))->create(advanceInput(), 1));
    equal(0, Capsule::table(Store::ADVANCES)->count());
};
$tests['advance full settlement emits AP then internal AR then PP with zero second payment'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api);
    $group = (int) $payment['group_id']; $ap = json_decode($payment['response'], true);
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190.00', 'topup_method' => '4'], 1);
    $cp = json_decode($close['payload'], true); equal(4, $cp['invoiceType']); equal(1, $cp['transactionType']);
    equal($ap['invoiceNumber'], $cp['referentDocumentNumber']); equal(1190, $cp['payment'][0]['amount']);
    $close = advanceIssue($store, $api, $config, $close); equal('issued', $close['state']);
    rejected(fn() => AdvanceStore::recordMoney((int) $close['invoice_id'], 'not-a-refund', 1));
    $final = $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1); $fp = json_decode($final['payload'], true);
    equal(0, $fp['invoiceType']); equal(0, $fp['transactionType']); equal(0, $fp['payment'][0]['amount']);
    equal(1190, $fp['items'][0]['totalAmount']); equal(json_decode($close['response'], true)['invoiceNumber'], $fp['referentDocumentNumber']);
    $meta = json_decode($final['meta'], true); equal(19000, $meta['advance_tax_cents']); equal($ap['invoiceNumber'], $meta['last_advance_number']);
    $final = advanceIssue($store, $api, $config, $final); equal('issued', $final['state']); equal(3, $api->posts);
    equal(0, Advance::ledger(Advance::documents($group))['remaining']);
    rejected(fn() => $store->draft($group, 'return', bin2hex(random_bytes(16)), ['amount' => '1.00', 'method' => '4', 'reason' => 'test'], 1));
    rejected(fn() => $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1));
};
$tests['advance partial settlement applies only topup and retains independent prior returns'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api); $group = (int) $payment['group_id'];
    $return = $store->draft($group, 'return', bin2hex(random_bytes(16)), ['amount' => '595.00', 'method' => '4', 'reason' => 'Test partial return', 'onet_token' => 'secret'], 1);
    equal(595, json_decode($return['payload'], true)['items'][0]['unitPrice']); equal(1, json_decode($return['payload'], true)['items'][0]['quantity']);
    equal(false, str_contains($return['request_input'], 'secret'));
    $return = advanceIssue($store, $api, $config, $return); equal('issued', $return['state']);
    AdvanceStore::recordMoney((int) $return['invoice_id'], 'TEST-REF-ONLY', 1); rejected(fn() => AdvanceStore::recordMoney((int) $return['invoice_id'], 'overwrite', 1));
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190.00', 'topup_method' => '2'], 1);
    equal(595, json_decode($close['payload'], true)['payment'][0]['amount']); advanceIssue($store, $api, $config, $close);
    $final = $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1); $p = json_decode($final['payload'], true);
    equal(595, $p['payment'][0]['amount']); equal(2, $p['payment'][0]['paymentType']); equal(1190, $p['items'][0]['totalAmount']);
    equal('issued', advanceIssue($store, $api, $config, $final)['state']);
    equal(59500, Advance::ledger(Advance::documents($group))['returned']); equal(0, Capsule::table(Store::TABLE)->count());
};
$tests['advance customer full return forbids settlement and duplicate return drafts cannot exceed the balance'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api); $group = (int) $payment['group_id'];
    $input = ['amount' => '1190.00', 'method' => '4', 'reason' => 'Full test return'];
    $a = $store->draft($group, 'return', bin2hex(random_bytes(16)), $input, 1); $b = $store->draft($group, 'return', bin2hex(random_bytes(16)), $input, 1);
    equal('issued', advanceIssue($store, $api, $config, $a)['state']); rejected(fn() => advanceIssue($store, $api, $config, $b));
    equal(2, $api->posts); rejected(fn() => $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190', 'topup_method' => '4'], 1));
};
$tests['unknown advance blocks every source and reconciles without a second payment POST'] = function () use ($config, $snapshot): void {
    resetDatabase(); $api = new AdvanceApi(); $api->timeout = true; $store = new AdvanceStore($config); $draft = $store->create(advanceInput(), 1); $id = (int) $draft['invoice_id'];
    equal('unknown', advanceIssue($store, $api, $config, $draft)['state']); equal('advance', Capsule::table(Store::GUARD)->value('source'));
    rejected(fn() => (new Service(new Store(hash_file('sha256', $config['pfx_path'])), new DbApi(), $config))->issue(48, $snapshot, 1));
    equal('issued', (new Service($store, $api, $config))->reconcile($id)['state']); equal(1, $api->posts); equal(1, $api->gets);
};
$tests['settlement survives process restart between AR and PP and unknown PP cannot repeat AR'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api); $group = (int) $payment['group_id'];
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '2380', 'topup_method' => '4'], 1);
    advanceIssue($store, $api, $config, $close);
    $fresh = new AdvanceStore($config); $final = $fresh->draft($group, 'final', bin2hex(random_bytes(16)), [], 1); $api->timeout = true;
    equal('unknown', advanceIssue($fresh, $api, $config, $final)['state']);
    rejected(fn() => $fresh->draft($group, 'final', bin2hex(random_bytes(16)), [], 1));
    rejected(fn() => $fresh->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '2380', 'topup_method' => '4'], 1));
    equal('issued', (new Service($fresh, $api, $config))->reconcile((int) $final['invoice_id'])['state']); equal(3, $api->posts);
    equal(1, Capsule::table(Store::ADVANCES)->where('kind', 'close')->where('state', 'issued')->count());
};
$tests['rejected final can get a fresh draft from the same confirmed AR without repeating settlement'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api); $group = (int) $payment['group_id'];
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190', 'topup_method' => '4'], 1); advanceIssue($store, $api, $config, $close);
    $final = $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1); $api->reject = true;
    equal('rejected', advanceIssue($store, $api, $config, $final)['state']);
    rejected(fn() => advanceIssue($store, $api, $config, $final));
    $second = $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1); $api->reject = false;
    equal(json_decode($final['payload'], true)['referentDocumentNumber'], json_decode($second['payload'], true)['referentDocumentNumber']);
    equal('issued', advanceIssue($store, $api, $config, $second)['state']); equal(1, Capsule::table(Store::ADVANCES)->where('kind', 'close')->count());
};
$tests['advance claim rejects stale changed owner payload and fiscal configuration'] = function () use ($config): void {
    resetDatabase(); $store = new AdvanceStore($config); $draft = $store->create(advanceInput(), 1); $p = json_decode($draft['payload'], true); $id = (int) $draft['invoice_id'];
    $bad = $p; $bad['items'][0]['totalAmount'] = 1; rejected(fn() => $store->claim($id, $bad, 1)); rejected(fn() => $store->claim($id, $p, 2));
    $badConfig = $config; $badConfig['tax_label'] = 'A'; rejected(fn() => (new AdvanceStore($badConfig))->claim($id, $p, 1));
    Capsule::table(Store::ADVANCES)->where('invoice_id', $id)->update(['created_at' => '2020-01-01 00:00:00']);
    rejected(fn() => $store->claim($id, $p, 1)); equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
$tests['advance wrong response type is unknown and cannot open refund or final processing'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $api->wrong = true; $store = new AdvanceStore($config); $draft = $store->create(advanceInput(), 1);
    equal('unknown', advanceIssue($store, $api, $config, $draft)['state']);
    rejected(fn() => $store->draft((int) $draft['group_id'], 'return', bin2hex(random_bytes(16)), ['amount' => '1', 'method' => '4', 'reason' => 'test'], 1));
};
$tests['advance protected final export carries payment offset and advertising reference without altering journal'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api); $group = (int) $payment['group_id'];
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190', 'topup_method' => '4'], 1); $close = advanceIssue($store, $api, $config, $close);
    $final = $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1); $final = advanceIssue($store, $api, $config, $final);
    accessFixtures(); $_SESSION = ['adminid' => 1];
    $html = \Onet\Fiskal\ReceiptExport::load(['source' => 'advance', 'id' => $final['invoice_id']])['body'];
    equal(true, str_contains($html, 'Plaćeno avansno: 1190.00')); equal(true, str_contains($html, 'Poslednji avansni račun:'));
    $txt = \Onet\Fiskal\ReceiptExport::load(['source' => 'advance', 'id' => $final['invoice_id'], 'format' => 'txt'])['body'];
    equal(json_decode($final['response'], true)['journal'], $txt);
    $internal = \Onet\Fiskal\ReceiptExport::load(['source' => 'advance', 'id' => $close['invoice_id']])['body']; equal(true, str_contains($internal, 'ne dostavljati kupcu'));
    equal(3, \Onet\Fiskal\Journal::search(['source' => 'advance'])['total']);
    $_SESSION = []; rejected(fn() => \Onet\Fiskal\ReceiptExport::load(['source' => 'advance', 'id' => $final['invoice_id']]));
};
$tests['advance receipts support copies and final sale refunds without reusing internal close as a money return'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api); $group = (int) $payment['group_id'];
    $copyStore = new \Onet\Fiskal\CopyStore($config); $copy = $copyStore->draft('advance', (int) $payment['invoice_id'], bin2hex(random_bytes(16)), 1);
    equal(2, json_decode($copy['payload'], true)['invoiceType']);
    equal('issued', (new Service($copyStore, new CopyApi(), $config))->submit((int) $copy['invoice_id'], json_decode($copy['payload'], true), 1)['state']);
    $refundStore = new \Onet\Fiskal\RefundStore($config); rejected(fn() => $refundStore->draft('advance', (int) $payment['invoice_id'], refundInput(), 1));
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190', 'topup_method' => '4'], 1); advanceIssue($store, $api, $config, $close);
    $final = $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1); $final = advanceIssue($store, $api, $config, $final);
    $refund = $refundStore->draft('advance', (int) $final['invoice_id'], refundInput(), 1);
    equal('issued', (new Service($refundStore, new RefundApi(), $config))->submit((int) $refund['invoice_id'], json_decode($refund['payload'], true), 1)['state']);
};
$tests['advance UI previews are CSRF bound and topup requires a separate explicit confirmation'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api); $group = (int) $payment['group_id'];
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '2380', 'topup_method' => '4'], 1); advanceIssue($store, $api, $config, $close);
    $final = $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1); $_SESSION = ['adminid' => 1, 'onetfiskal_csrf' => str_repeat('a', 64)];
    ob_start(); try { AdvanceAdmin::render($config, (int) $final['invoice_id'], 0); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'confirmed_topup')); equal(true, str_contains($html, 'advance_hash'));
    equal(null, Capsule::table(Store::GUARD)->value('attempt_id')); equal(2, $api->posts);
};
$tests['advance migration preserves old source locks and all document evidence'] = function () use ($config): void {
    resetDatabase(); (new Store())->queue(48); Capsule::table(Store::GUARD)->where('id', 1)->update(['source' => 'copy', 'attempt_id' => 99]);
    Capsule::schema()->drop(Store::ADVANCES); Capsule::schema()->drop(Store::ADVANCE_GROUPS);
    Store::install(); Store::install();
    equal('copy', Capsule::table(Store::GUARD)->value('source')); equal(99, (int) Capsule::table(Store::GUARD)->value('attempt_id'));
    equal('review', (new Store())->find(48)['state']); equal(true, Capsule::schema()->hasTable(Store::ADVANCES));
};
$tests['copy of an advance final preserves zero new payment and saved advance annotations'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api); $group = (int) $payment['group_id'];
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190', 'topup_method' => '4'], 1); advanceIssue($store, $api, $config, $close);
    $final = $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1); $final = advanceIssue($store, $api, $config, $final);
    $cs = new \Onet\Fiskal\CopyStore($config); $copy = $cs->draft('advance', (int) $final['invoice_id'], bin2hex(random_bytes(16)), 1);
    equal(0, json_decode($copy['payload'], true)['payment'][0]['amount']);
    equal('issued', (new Service($cs, new CopyApi(), $config))->submit((int) $copy['invoice_id'], json_decode($copy['payload'], true), 1)['state']);
    accessFixtures(); $_SESSION = ['adminid' => 1];
    $html = \Onet\Fiskal\ReceiptExport::load(['source' => 'copy', 'id' => $copy['invoice_id']])['body'];
    equal(true, str_contains($html, 'Plaćeno avansno: 1190.00'));
};
if ($driver === 'mysql') {
    $tests['concurrent MySQL advance return and settlement cannot consume the same advance twice'] = function () use ($config): void {
        if (!function_exists('pcntl_fork')) { throw new RuntimeException('pcntl is required'); }
        resetDatabase(); $api = new AdvanceApi(); $store = new AdvanceStore($config); $payment = openAdvance($config, $api); $group = (int) $payment['group_id'];
        $drafts = [$store->draft($group, 'return', bin2hex(random_bytes(16)), ['amount' => '1190', 'method' => '4', 'reason' => 'race test'], 1),
            $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190', 'topup_method' => '4'], 1)];
        $dir = sys_get_temp_dir() . '/onet-advance-race-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
        Capsule::connection()->disconnect(); $children = [];
        foreach ($drafts as $i => $draft) {
            $pid = pcntl_fork(); if ($pid < 0) { throw new RuntimeException('Cannot fork'); }
            if ($pid === 0) {
                try {
                    for ($n = 0; !is_file($dir . '/go'); $n++) { if ($n > 500) { throw new RuntimeException('Start timeout'); } usleep(10000); }
                    Capsule::connection()->reconnect();
                    Capsule::connection()->transaction(function () use ($config, $draft): void {
                        equal('issued', advanceIssue(new AdvanceStore($config), new AdvanceApi(), $config, $draft)['state']); usleep(200000);
                    });
                    file_put_contents($dir . '/' . $i, 'issued');
                } catch (RuntimeException $e) {
                    $blocked = str_contains($e->getMessage(), 'Avans je zatvoren') || str_contains($e->getMessage(), 'preostali iznos') || str_contains($e->getMessage(), 'Drugi zahtev');
                    file_put_contents($dir . '/' . $i, $blocked ? 'blocked' : 'unexpected: ' . $e->getMessage());
                }
                exit(0);
            }
            $children[] = $pid;
        }
        file_put_contents($dir . '/go', 'go'); foreach ($children as $pid) { pcntl_waitpid($pid, $status); equal(0, pcntl_wexitstatus($status)); }
        Capsule::connection()->reconnect(); $results = [file_get_contents($dir . '/0'), file_get_contents($dir . '/1')]; sort($results);
        equal(['blocked', 'issued'], $results);
        equal(1, Capsule::table(Store::ADVANCES)->where('group_id', $group)->whereIn('kind', ['return', 'close'])->where('state', 'issued')->count());
        equal(0, Advance::ledger(Advance::documents($group))['remaining']);
        foreach (glob($dir . '/*') as $f) { unlink($f); } rmdir($dir);
    };
}
