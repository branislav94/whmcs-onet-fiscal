<?php
declare(strict_types=1);
use WHMCS\Database\Capsule;
use Onet\Fiskal\Store;
use Onet\Fiskal\Service;
use Onet\Fiskal\Transport;

// Included by database.php; runs against the same dedicated SQLite/MySQL test database.
function refundParent(array $config, array $snapshot): array {
    resetDatabase();
    $api = new DbApi();
    $row = (new Service(new Store(hash_file('sha256', $config['pfx_path'])), $api, $config))->issue(48, $snapshot, 1);
    equal('issued', $row['state']);
    return $row;
}
function refundInput(string $quantity = '1'): array {
    return ['draft_key' => bin2hex(random_bytes(16)), 'quantities' => [$quantity],
        'buyer_id' => '20:000000000', 'payment_type' => '4', 'reason' => 'Izmišljeni test povraćaja'];
}
final class RefundApi implements Transport {
    public int $posts = 0; public bool $timeout = false; public bool $reject = false;
    public bool $wrong = false; public array $payload = []; public ?string $requestId = null;
    public function request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array {
        if ($path === '/api/v3/status') { return (new DbApi())->request($method, $path); }
        if ($method === 'POST') {
            $this->posts++; $this->payload = $payload; $this->requestId = $requestId;
            if ($this->timeout) { throw new RuntimeException('Test connection lost'); }
            if ($this->reject) { return ['code' => 400, 'data' => ['message' => 'Test validation rejection']]; }
        } else { equal('/api/v3/invoices/' . $this->requestId, $path); }
        $p = $this->payload;
        return ['code' => 200, 'data' => ['requestedBy' => 'ABCD1234', 'invoiceNumber' => 'ABCD1234-Dt1Ov2o0-' . (100 + $this->posts),
            'totalAmount' => $p['payment'][0]['amount'], 'invoiceCounterExtension' => $this->wrong ? 'ПП' : 'ПР',
            'journal' => 'TEST REFUND ' . $p['referentDocumentNumber'] . ' ' . $p['buyerId'],
            'sdcDateTime' => '2026-11-14T12:00:00+01:00', 'signature' => 'mock-signature',
            'verificationUrl' => 'https://sandbox.suf.purs.gov.rs/v/?vl=refund-test', 'verificationQRCode' => tinyGif()]];
    }
}
$tests['refund migration is repeatable and preserves the original sale and shared guard'] = function () use ($config, $snapshot): void {
    $old = refundParent($config, $snapshot);
    Store::install(); Store::install();
    equal($old['payload'], (new Store())->find(48)['payload']);
    equal($old['response'], (new Store())->find(48)['response']);
    equal(0, Capsule::table(Store::REFUNDS)->count()); equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
$tests['refund draft carries immutable original references and positive gross prices'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config); $input = refundInput('0.500');
    $draft = $store->draft('invoice', 48, $input, 1); $p = json_decode($draft['payload'], true);
    equal(0, $p['invoiceType']); equal(1, $p['transactionType']);
    equal('ABCD1234-Dt1Ov2o0-1', $p['referentDocumentNumber']);
    equal('2026-11-14T12:00:00+01:00', $p['referentDocumentDT']);
    equal(595, $p['payment'][0]['amount']); equal(1190, $p['items'][0]['unitPrice']);
    equal(0.5, $p['items'][0]['quantity']); equal(['Ж'], $p['items'][0]['labels']);
    $input['quantities'] = ['1']; $input['buyer_id'] = 'invalid';
    equal($draft['payload'], $store->draft('invoice', 48, $input, 1)['payload']);
    rejected(fn() => $store->draft('synthetic', 48, $input, 1));
    rejected(fn() => $store->draft('invoice', 48, $input, 2));
};
$tests['refund requires identification noncash method valid quantities and complete issued original'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config);
    foreach ([['buyer_id' => ''], ['buyer_id' => '10:123456789'], ['payment_type' => '1'],
        ['payment_type' => '2; DROP TABLE'], ['quantities' => ['-1']], ['quantities' => ['1.001']],
        ['quantities' => ['0']], ['quantities' => ['0.0001']], ['quantities' => ['1e0']],
        ['quantities' => ['0,5']], ['quantities' => []], ['reason' => '']] as $change) {
        rejected(fn() => $store->draft('invoice', 48, array_merge(refundInput(), $change), 1));
    }
    (new Store())->queue(49);
    rejected(fn() => $store->draft('invoice', 49, refundInput(), 1));
    rejected(fn() => $store->draft('refund', 48, refundInput(), 1));
    equal(0, Capsule::table(Store::REFUNDS)->count());
};
$tests['two stale refund drafts cannot overrun the original after the first is issued'] = function () use ($config, $snapshot): void {
    $old = refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config); $api = new RefundApi();
    $a = $store->draft('invoice', 48, refundInput(), 1); $b = $store->draft('invoice', 48, refundInput(), 1);
    $service = new Service($store, $api, $config);
    equal('issued', $service->submit((int) $a['invoice_id'], json_decode($a['payload'], true), 1)['state']);
    rejected(fn() => $service->submit((int) $b['invoice_id'], json_decode($b['payload'], true), 1));
    equal('issued', $service->submit((int) $a['invoice_id'], json_decode($a['payload'], true), 1)['state']);
    equal(1, $api->posts); equal(1000, \Onet\Fiskal\Refund::used('invoice', 48)[0]['quantity']);
    equal($old['response'], (new Store())->find(48)['response']);
};
$tests['successive partial refunds preserve original price and never exceed the original money'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config); $api = new RefundApi();
    foreach (['0.500', '0.500'] as $q) {
        $draft = $store->draft('invoice', 48, refundInput($q), 1);
        equal('issued', (new Service($store, $api, $config))->submit((int) $draft['invoice_id'], json_decode($draft['payload'], true), 1)['state']);
    }
    equal(2, $api->posts); equal(119000, \Onet\Fiskal\Refund::used('invoice', 48)[0]['cents']);
    rejected(fn() => $store->draft('invoice', 48, refundInput('0.001'), 1));
    equal(2, \Onet\Fiskal\Journal::search(['source' => 'refund'])['total']);
};
$tests['refund quantity and cent rounding limits are both enforced'] = function (): void {
    $parent = ['payload' => ['items' => [['name' => 'tiny', 'quantity' => 1, 'unitPrice' => 0.01, 'totalAmount' => 0.01, 'labels' => ['Ж']]]],
        'response' => ['invoiceNumber' => 'ABCD1234-Dt1Ov2o0-1', 'sdcDateTime' => '2026-11-14T12:00:00+01:00']];
    $p = \Onet\Fiskal\Refund::build($parent, [500], '20:000000000', 4, 1);
    equal(0.01, $p['items'][0]['totalAmount']);
    rejected(fn() => \Onet\Fiskal\Refund::checkBalance($parent, [500], [['quantity' => 500, 'cents' => 1]]));
};
$tests['unknown refund reserves balance locks every source and reconciles with no repeat POST'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config); $api = new RefundApi(); $api->timeout = true;
    $draft = $store->draft('invoice', 48, refundInput(), 1); $id = (int) $draft['invoice_id'];
    equal('unknown', (new Service($store, $api, $config))->submit($id, json_decode($draft['payload'], true), 1)['state']);
    equal('refund', Capsule::table(Store::GUARD)->value('source'));
    rejected(fn() => $store->draft('invoice', 48, refundInput(), 1));
    rejected(fn() => (new Service(new Store(hash_file('sha256', $config['pfx_path'])), new DbApi(), $config))->issue(49, $snapshot, 1));
    equal('issued', (new Service($store, $api, $config))->reconcile($id)['state']);
    equal(1, $api->posts); equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
$tests['original sale response cannot masquerade as a successful refund'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config); $api = new RefundApi(); $api->wrong = true;
    $draft = $store->draft('invoice', 48, refundInput(), 1);
    equal('unknown', (new Service($store, $api, $config))->submit((int) $draft['invoice_id'], json_decode($draft['payload'], true), 1)['state']);
    equal('refund', Capsule::table(Store::GUARD)->value('source'));
};
$tests['refund explicit rejection releases guard and balance but cannot resubmit the same draft'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config); $api = new RefundApi(); $api->reject = true;
    $draft = $store->draft('invoice', 48, refundInput(), 1); $service = new Service($store, $api, $config);
    equal('rejected', $service->submit((int) $draft['invoice_id'], json_decode($draft['payload'], true), 1)['state']);
    equal(null, Capsule::table(Store::GUARD)->value('attempt_id')); equal([], \Onet\Fiskal\Refund::used('invoice', 48));
    rejected(fn() => $service->submit((int) $draft['invoice_id'], json_decode($draft['payload'], true), 1)); equal(1, $api->posts);
    $store->draft('invoice', 48, refundInput(), 1);
};
$tests['refund claim rejects changed PFX tampered payload and stale draft'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config);
    $draft = $store->draft('invoice', 48, refundInput(), 1); $id = (int) $draft['invoice_id']; $p = json_decode($draft['payload'], true);
    $bad = $p; $bad['payment'][0]['amount'] = 1;
    rejected(fn() => $store->claim($id, $bad, 1)); rejected(fn() => $store->claim($id, $p, 2));
    Capsule::table(Store::REFUNDS)->where('invoice_id', $id)->update(['created_at' => '2020-01-01 00:00:00']);
    rejected(fn() => $store->claim($id, $p, 1));
    $badConfig = $config; $badConfig['expected_uid'] = 'DIFF1234';
    rejected(fn() => (new \Onet\Fiskal\RefundStore($badConfig))->draft('invoice', 48, refundInput(), 1));
    $badConfig = $config; $badConfig['environment'] = 'production';
    rejected(fn() => (new \Onet\Fiskal\RefundStore($badConfig))->draft('invoice', 48, refundInput(), 1));
    equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
$tests['refund money reference is an independent immutable local record and export is authorized'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config); $api = new RefundApi();
    $draft = $store->draft('invoice', 48, refundInput(), 1); $id = (int) $draft['invoice_id'];
    rejected(fn() => \Onet\Fiskal\RefundStore::recordMoney($id, 'fake-test', 1));
    (new Service($store, $api, $config))->submit($id, json_decode($draft['payload'], true), 1);
    $before = $store->find($id)['response'];
    \Onet\Fiskal\RefundStore::recordMoney($id, 'TEST-ONLY-REF-1', 1);
    rejected(fn() => \Onet\Fiskal\RefundStore::recordMoney($id, 'overwrite', 1));
    equal($before, $store->find($id)['response']); equal(1, $api->posts);
    accessFixtures(); $_SESSION = ['adminid' => 1];
    equal(true, str_contains(\Onet\Fiskal\ReceiptExport::load(['source' => 'refund', 'id' => $id])['body'], 'TEST REFUND'));
    ob_start(); try { \Onet\Fiskal\RefundAdmin::render(null, $id, '', 0); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'TEST-ONLY-REF-1')); equal(false, str_contains($html, 'refund_issue'));
};
$tests['refund selects a later item without changing its price or reference mapping'] = function () use ($config, $snapshot): void {
    $s = $snapshot;
    $s['items'] = [['description' => 'first service', 'amount' => '500.00', 'taxed' => 1],
        ['description' => 'second service', 'amount' => '500.00', 'taxed' => 1]];
    refundParent($config, $s); $store = new \Onet\Fiskal\RefundStore($config);
    $input = refundInput(); $input['quantities'] = ['0', '0.500'];
    $row = $store->draft('invoice', 48, $input, 1); $p = json_decode($row['payload'], true);
    equal('second service', $p['items'][0]['name']); equal(595, $p['items'][0]['unitPrice']);
    equal(297.5, $p['payment'][0]['amount']);
    (new Service($store, new RefundApi(), $config))->submit((int) $row['invoice_id'], $p, 1);
    equal([1 => ['quantity' => 500, 'cents' => 29750]], \Onet\Fiskal\Refund::used('invoice', 48));
};
$tests['refund preview UI has bound confirmation and no bank or live API action'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $_SESSION = ['adminid' => 1, 'onetfiskal_csrf' => str_repeat('a', 64)];
    $row = (new \Onet\Fiskal\RefundStore($config))->draft('invoice', 48, refundInput(), 1);
    ob_start(); try { \Onet\Fiskal\RefundAdmin::render($config, (int) $row['invoice_id'], '', 0); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'refund_hash')); equal(true, str_contains($html, 'refund_issue'));
    equal(true, str_contains($html, '1.190,00') && str_contains($html, 'RSD')); equal(true, str_contains($html, 'Ne vraća novac preko banke'));
    equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
if ($driver === 'mysql') {
    $tests['concurrent MySQL refund submissions cannot both spend the same original balance'] = function () use ($config, $snapshot): void {
        if (!function_exists('pcntl_fork')) { throw new RuntimeException('MySQL race test requires pcntl'); }
        refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config);
        $drafts = [$store->draft('invoice', 48, refundInput(), 1), $store->draft('invoice', 48, refundInput(), 1)];
        $dir = sys_get_temp_dir() . '/onet-refund-race-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
        Capsule::connection()->disconnect(); $children = [];
        foreach ($drafts as $i => $draft) {
            $pid = pcntl_fork();
            if ($pid < 0) { throw new RuntimeException('Cannot start concurrency worker'); }
            if ($pid === 0) {
                try {
                    for ($n = 0; !is_file($dir . '/go'); $n++) {
                        if ($n > 500) { throw new RuntimeException('Race start timeout'); }
                        usleep(10000);
                    }
                    Capsule::connection()->reconnect();
                    Capsule::connection()->transaction(function () use ($config, $draft): void {
                        $r = (new Service(new \Onet\Fiskal\RefundStore($config), new RefundApi(), $config))
                            ->submit((int) $draft['invoice_id'], json_decode($draft['payload'], true), 1);
                        equal('issued', $r['state']); usleep(200000);
                    });
                    file_put_contents($dir . '/' . $i, 'issued');
                } catch (RuntimeException $e) {
                    $blocked = str_contains($e->getMessage(), 'Povraćaj prelazi') || str_contains($e->getMessage(), 'Drugi zahtev');
                    file_put_contents($dir . '/' . $i, $blocked ? 'blocked' : 'unexpected: ' . $e->getMessage());
                }
                exit(0);
            }
            $children[] = $pid;
        }
        file_put_contents($dir . '/go', 'go');
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); equal(0, pcntl_wexitstatus($status)); }
        Capsule::connection()->reconnect();
        $results = [file_get_contents($dir . '/0'), file_get_contents($dir . '/1')]; sort($results);
        equal(['blocked', 'issued'], $results);
        equal(1, Capsule::table(Store::REFUNDS)->where('state', 'issued')->count());
        equal(119000, \Onet\Fiskal\Refund::used('invoice', 48)[0]['cents']);
        foreach (glob($dir . '/*') as $file) { unlink($file); } rmdir($dir);
    };
}
