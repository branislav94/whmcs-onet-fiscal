<?php
declare(strict_types=1);
use WHMCS\Database\Capsule;
use Onet\Fiskal\Store;
use Onet\Fiskal\Service;
use Onet\Fiskal\Transport;
use Onet\Fiskal\CopyStore;
use Onet\Fiskal\CopyDocument;
use Onet\Fiskal\CopyAdmin;

final class CopyApi implements Transport {
    public int $posts = 0; public int $gets = 0; public bool $timeout = false; public bool $reject = false;
    public bool $wrong = false; public array $payload = []; public ?string $requestId = null;
    public function request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array {
        if ($path === '/api/v3/status') { return (new DbApi())->request($method, $path); }
        if ($method === 'POST') {
            $this->posts++; $this->payload = $payload; $this->requestId = $requestId;
            if ($this->timeout) { throw new RuntimeException('Test timeout'); }
            if ($this->reject) { return ['code' => 422, 'data' => ['message' => 'Test rejection']]; }
        } else { $this->gets++; equal('/api/v3/invoices/' . $this->requestId, $path); }
        $p = $this->payload; $extension = $p['transactionType'] === 1 ? 'КР' : 'КП';
        return ['code' => 200, 'data' => ['requestedBy' => 'ABCD1234', 'invoiceNumber' => 'ABCD1234-Dt1Ov2o0-' . (200 + $this->posts),
            'totalAmount' => $p['payment'][0]['amount'], 'invoiceCounterExtension' => $this->wrong ? 'ПП' : $extension,
            'journal' => 'ОВО НИЈЕ ФИСКАЛНИ РАЧУН TEST COPY ' . $p['referentDocumentNumber'] . ' ' . ($p['buyerId'] ?? ''),
            'sdcDateTime' => '2026-11-14T13:00:00+01:00', 'signature' => 'mock-signature',
            'verificationUrl' => 'https://sandbox.suf.purs.gov.rs/v/?vl=copy-test', 'verificationQRCode' => tinyGif()]];
    }
}
$tests['copy migration preserves originals and pending fiscal locks'] = function () use ($config, $snapshot): void {
    $original = refundParent($config, $snapshot);
    Capsule::schema()->drop(Store::COPIES);
    Capsule::table(Store::GUARD)->where('id', 1)->update(['source' => 'refund', 'attempt_id' => 99]);
    Store::install(); Store::install();
    equal(true, Capsule::schema()->hasTable(Store::COPIES));
    equal('refund', Capsule::table(Store::GUARD)->value('source')); equal(99, (int) Capsule::table(Store::GUARD)->value('attempt_id'));
    equal($original['response'], (new Store())->find(48)['response']);
};
$tests['sale copy preserves original items payment taxes and references without a duplicate sale'] = function () use ($config, $snapshot): void {
    $original = refundParent($config, $snapshot); $store = new CopyStore($config);
    $row = $store->draft('invoice', 48, bin2hex(random_bytes(16)), 1); $p = json_decode($row['payload'], true);
    $sale = json_decode($original['payload'], true);
    equal(2, $p['invoiceType']); equal(0, $p['transactionType']); equal($sale['items'], $p['items']); equal($sale['payment'], $p['payment']);
    equal('ABCD1234-Dt1Ov2o0-1', $p['referentDocumentNumber']); equal('2026-11-14T12:00:00+01:00', $p['referentDocumentDT']);
    equal(false, isset($p['buyerId']));
    $api = new CopyApi(); $service = new Service($store, $api, $config);
    equal('issued', $service->submit((int) $row['invoice_id'], $p, 1)['state']);
    equal('issued', $service->submit((int) $row['invoice_id'], $p, 1)['state']);
    equal(1, $api->posts); equal($original['response'], (new Store())->find(48)['response']);
    equal(1, Capsule::table(Store::TABLE)->count()); equal(0, Capsule::table(Store::REFUNDS)->count());
};
$tests['refund copy references the refund not its sale and never changes refund balance or money evidence'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $refundStore = new \Onet\Fiskal\RefundStore($config);
    $refund = $refundStore->draft('invoice', 48, refundInput('0.500'), 1); $rid = (int) $refund['invoice_id'];
    $issued = (new Service($refundStore, new RefundApi(), $config))->submit($rid, json_decode($refund['payload'], true), 1);
    $before = $refundStore->find($rid); $balance = \Onet\Fiskal\Refund::used('invoice', 48);
    $store = new CopyStore($config); $draft = $store->draft('refund', $rid, bin2hex(random_bytes(16)), 1); $p = json_decode($draft['payload'], true);
    equal(2, $p['invoiceType']); equal(1, $p['transactionType']); equal('20:000000000', $p['buyerId']);
    equal(json_decode($issued['response'], true)['invoiceNumber'], $p['referentDocumentNumber']);
    equal(595, $p['payment'][0]['amount']); equal(0.5, $p['items'][0]['quantity']);
    equal('issued', (new Service($store, new CopyApi(), $config))->submit((int) $draft['invoice_id'], $p, 1)['state']);
    equal($balance, \Onet\Fiskal\Refund::used('invoice', 48)); equal($before, $refundStore->find($rid));
};
$tests['copy drafts deduplicate and reject unissued parents copies and changed certificate identity'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new CopyStore($config); $key = bin2hex(random_bytes(16));
    $a = $store->draft('invoice', 48, $key, 1); $b = $store->draft('invoice', 48, $key, 1);
    equal($a['invoice_id'], $b['invoice_id']); equal(1, Capsule::table(Store::COPIES)->count());
    rejected(fn() => $store->draft('invoice', 48, $key, 2));
    rejected(fn() => $store->draft('copy', (int) $a['invoice_id'], bin2hex(random_bytes(16)), 1));
    (new Store())->queue(49); rejected(fn() => $store->draft('invoice', 49, bin2hex(random_bytes(16)), 1));
    $bad = $config; $bad['expected_uid'] = 'OTHER123'; rejected(fn() => (new CopyStore($bad))->draft('invoice', 48, bin2hex(random_bytes(16)), 1));
    $bad = $config; $bad['vsdc_url'] = 'https://vsdc.suf.purs.gov.rs'; rejected(fn() => (new CopyStore($bad))->draft('invoice', 48, bin2hex(random_bytes(16)), 1));
};
$tests['unknown copy blocks all fiscal sources and reconciles using the original request'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new CopyStore($config); $api = new CopyApi(); $api->timeout = true;
    $draft = $store->draft('invoice', 48, bin2hex(random_bytes(16)), 1); $id = (int) $draft['invoice_id'];
    equal('unknown', (new Service($store, $api, $config))->submit($id, json_decode($draft['payload'], true), 1)['state']);
    equal('copy', Capsule::table(Store::GUARD)->value('source'));
    rejected(fn() => (new Service(new Store(hash_file('sha256', $config['pfx_path'])), new DbApi(), $config))->issue(49, $snapshot, 1));
    $rs = new \Onet\Fiskal\RefundStore($config); $rd = $rs->draft('invoice', 48, refundInput(), 1);
    rejected(fn() => (new Service($rs, new RefundApi(), $config))->submit((int) $rd['invoice_id'], json_decode($rd['payload'], true), 1));
    equal('issued', (new Service($store, $api, $config))->reconcile($id)['state']);
    equal(1, $api->posts); equal(1, $api->gets); equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
$tests['copy cannot bypass a pending sale and original responses cannot masquerade as copies'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $saleApi = new DbApi(); $saleApi->timeout = true;
    (new Service(new Store(hash_file('sha256', $config['pfx_path'])), $saleApi, $config))->issue(49, $snapshot, 1);
    $store = new CopyStore($config); $draft = $store->draft('invoice', 48, bin2hex(random_bytes(16)), 1); $api = new CopyApi();
    rejected(fn() => (new Service($store, $api, $config))->submit((int) $draft['invoice_id'], json_decode($draft['payload'], true), 1));
    equal(0, $api->posts);
    refundParent($config, $snapshot); $draft = $store->draft('invoice', 48, bin2hex(random_bytes(16)), 1); $api->wrong = true;
    equal('unknown', (new Service($store, $api, $config))->submit((int) $draft['invoice_id'], json_decode($draft['payload'], true), 1)['state']);
};
$tests['copy claim rejects price item reference owner and expiry tampering'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new CopyStore($config);
    $draft = $store->draft('invoice', 48, bin2hex(random_bytes(16)), 1); $id = (int) $draft['invoice_id']; $p = json_decode($draft['payload'], true);
    $bad = $p; $bad['items'][0]['unitPrice'] = 1; rejected(fn() => $store->claim($id, $bad, 1));
    $bad = $p; $bad['referentDocumentNumber'] = 'ABCD1234-Dt1Ov2o0-999'; rejected(fn() => $store->claim($id, $bad, 1));
    rejected(fn() => $store->claim($id, $p, 2));
    Capsule::table(Store::COPIES)->where('invoice_id', $id)->update(['created_at' => '2020-01-01 00:00:00']);
    rejected(fn() => $store->claim($id, $p, 1)); equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
$tests['copy rejection permits a fresh deliberate draft but never repeats the rejected request'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new CopyStore($config); $api = new CopyApi(); $api->reject = true;
    $draft = $store->draft('invoice', 48, bin2hex(random_bytes(16)), 1); $p = json_decode($draft['payload'], true); $id = (int) $draft['invoice_id'];
    $service = new Service($store, $api, $config); equal('rejected', $service->submit($id, $p, 1)['state']);
    rejected(fn() => $service->submit($id, $p, 1)); equal(1, $api->posts); equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
    $store->draft('invoice', 48, bin2hex(random_bytes(16)), 1); equal(2, Capsule::table(Store::COPIES)->count());
};
$tests['issued copies appear in journal and protected downloads with a prominent nonfiscal notice'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new CopyStore($config);
    $draft = $store->draft('invoice', 48, bin2hex(random_bytes(16)), 1); $id = (int) $draft['invoice_id'];
    (new Service($store, new CopyApi(), $config))->submit($id, json_decode($draft['payload'], true), 1);
    equal(1, \Onet\Fiskal\Journal::search(['source' => 'copy'])['total']);
    accessFixtures(); $_SESSION = ['adminid' => 1];
    $html = \Onet\Fiskal\ReceiptExport::load(['source' => 'copy', 'id' => $id])['body'];
    equal(true, str_contains($html, 'class="onet-copy-notice"')); equal(true, str_contains($html, 'font-size:2em'));
    $text = \Onet\Fiskal\ReceiptExport::load(['source' => 'copy', 'id' => $id, 'format' => 'txt'])['body'];
    equal(json_decode($store->find($id)['response'], true)['journal'], $text);
    $_SESSION = []; rejected(fn() => \Onet\Fiskal\ReceiptExport::load(['source' => 'copy', 'id' => $id]));
};
$tests['copy preview UI performs no live calls and requires explicit new-document confirmation'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $_SESSION = ['adminid' => 1, 'onetfiskal_csrf' => str_repeat('a', 64)];
    $row = (new CopyStore($config))->draft('invoice', 48, bin2hex(random_bytes(16)), 1);
    ob_start(); try { CopyAdmin::render($config, (int) $row['invoice_id']); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'copy_hash')); equal(true, str_contains($html, 'copy_issue'));
    equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
