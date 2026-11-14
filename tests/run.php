<?php
declare(strict_types=1);
require __DIR__ . '/../modules/addons/onetfiskal/lib/Core.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Runtime.php';

use Onet\Fiskal\Builder;
use Onet\Fiskal\Checks;
use Onet\Fiskal\Config;
use Onet\Fiskal\Money;
use Onet\Fiskal\Repository;
use Onet\Fiskal\Service;
use Onet\Fiskal\Transport;

$count = 0;
function test(string $name, callable $fn): void {
    global $count;
    $fn(); ++$count; echo "PASS $name\n";
}
function same(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function fails(callable $fn): void {
    try { $fn(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Expected rejection');
}
function config(): array {
    return ['expected_uid' => 'ABCD1234', 'tax_label' => 'Ђ', 'payment_map' => ['banktransfer' => 4]];
}
function fixture(): array {
    return [
        'currency' => 'RSD', 'tax_type' => 'Exclusive',
        'client' => ['country' => 'RS', 'companyname' => '', 'tax_id' => ''],
        'invoice' => ['status' => 'Paid', 'credit' => '0.00', 'subtotal' => '1000.00', 'tax' => '200.00',
            'tax2' => '0.00', 'taxrate' => '20.000', 'taxrate2' => '0.000', 'total' => '1200.00'],
        'items' => [['description' => "Hosting BASIC\nexample.rs", 'amount' => '1000.00', 'taxed' => 1]],
        'transactions' => [['amountin' => '1200.00', 'amountout' => '0.00', 'gateway' => 'banktransfer']],
    ];
}
function status(): array {
    return ['uid' => 'ABCD1234', 'currentTaxRates' => ['taxCategories' => [
        ['name' => 'VAT', 'categoryType' => 0, 'taxRates' => [['rate' => 20, 'label' => 'Ђ']]],
    ]]];
}
function result(float $amount = 1200.0): array {
    return ['requestedBy' => 'ABCD1234', 'invoiceNumber' => 'ABCD1234-EFGH5678-1', 'totalAmount' => $amount,
        'journal' => 'SANDBOX test journal', 'sdcDateTime' => '2026-11-14T12:00:00+01:00',
        'signature' => 'test-signature', 'verificationUrl' => 'https://sandbox.suf.purs.gov.rs/v/?vl=test'];
}
final class MemoryRepo implements Repository {
    public array $rows = [];
    public ?int $guard = null;
    public function find(int $id): ?array { return $this->rows[$id] ?? null; }
    public function queue(int $id): void {
        $this->rows[$id] ??= ['id' => $id, 'invoice_id' => $id, 'state' => 'review'];
    }
    public function claim(int $id, array $payload, int $adminId): array {
        $this->queue($id);
        if ($this->guard !== null || $this->rows[$id]['state'] !== 'review') { throw new RuntimeException('Locked'); }
        $this->guard = $id;
        $this->rows[$id] = array_merge($this->rows[$id], ['state' => 'sending', 'request_id' => str_pad((string) $id, 32, '0', STR_PAD_LEFT),
            'payload' => json_encode($payload), 'admin_id' => $adminId]);
        return $this->rows[$id];
    }
    public function finish(int $id, string $state, mixed $response, string $message, bool $release): void {
        if ($this->rows[$id]['state'] === 'issued') { return; }
        $this->rows[$id] = array_merge($this->rows[$id], ['state' => $state, 'response' => $response, 'message' => $message]);
        if ($release && $this->guard === $id) { $this->guard = null; }
    }
}
final class FakeApi implements Transport {
    public int $posts = 0;
    public array $answers = [];
    public array $calls = [];
    public ?array $statusData = null;
    public function request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array {
        $this->calls[] = [$method, $path, $requestId];
        if ($path === '/api/v3/status') { return ['code' => 200, 'data' => $this->statusData ?? status()]; }
        if ($method === 'POST') { $this->posts++; }
        $answer = array_shift($this->answers);
        if ($answer instanceof Throwable) { throw $answer; }
        return $answer ?? ['code' => 200, 'data' => result()];
    }
}

test('exact decimal money, no exponent/locale ambiguity', function (): void {
    same(100001, Money::cents('1000.01')); same(-1, Money::cents('-0.01'));
    fails(fn() => Money::cents('1,20')); fails(fn() => Money::cents('1e3')); fails(fn() => Money::cents('1.001'));
});
test('PDV 20% gross payload and correct bank transfer method', function (): void {
    $p = Builder::build(fixture(), config(), 'admin');
    same(120000, Money::cents($p['items'][0]['totalAmount']));
    same(4, $p['payment'][0]['paymentType']); same(['Ђ'], $p['items'][0]['labels']);
    same(0, $p['invoiceType']); same(0, $p['transactionType']);
});
test('aggregate VAT rounding across multiple invoice items', function (): void {
    $s = fixture();
    $s['items'] = [['description' => 'a', 'amount' => '0.03', 'taxed' => 1], ['description' => 'b', 'amount' => '0.03', 'taxed' => 1]];
    $s['invoice'] = array_merge($s['invoice'], ['subtotal' => '0.06', 'tax' => '0.01', 'total' => '0.07']);
    $s['transactions'][0]['amountin'] = '0.07';
    $p = Builder::build($s, config(), 'a');
    same(7, array_sum(array_map(fn($i) => Money::cents($i['totalAmount']), $p['items'])));
});
foreach (['EUR', 'inclusive', 'company', 'foreign', 'credit', 'partial', 'unmapped', 'negative', 'untaxed', 'wrong-total', 'wrong-tax', 'refund'] as $case) {
    test('reject unsupported ' . $case, function () use ($case): void {
        $s = fixture();
        switch ($case) {
            case 'EUR': $s['currency'] = 'EUR'; break;
            case 'inclusive': $s['tax_type'] = 'Inclusive'; break;
            case 'company': $s['client']['companyname'] = 'DOO'; break;
            case 'foreign': $s['client']['country'] = 'DE'; break;
            case 'credit': $s['invoice']['credit'] = '1.00'; break;
            case 'partial': $s['transactions'][] = $s['transactions'][0]; break;
            case 'unmapped': $s['transactions'][0]['gateway'] = 'unknown'; break;
            case 'negative': $s['items'][0]['amount'] = '-1.00'; break;
            case 'untaxed': $s['items'][0]['taxed'] = 0; break;
            case 'wrong-total': $s['invoice']['total'] = '1199.99'; break;
            case 'wrong-tax': $s['invoice']['taxrate'] = '10.000'; break;
            case 'refund': $s['transactions'][0]['amountout'] = '100.00'; break;
        }
        fails(fn() => Builder::build($s, config(), 'a'));
    });
}
test('active tax label checked by exact script and certificate UID', function (): void {
    Checks::status(status(), config());
    $c = config(); $c['tax_label'] = 'Đ'; fails(fn() => Checks::status(status(), $c));
    $c = config(); $c['expected_uid'] = 'ZZZZ9999'; fails(fn() => Checks::status(status(), $c));
});
test('observed sandbox VAT 19% and Cyrillic label pass without remapping 20%', function (): void {
    $status = ['uid' => 'ABCD1234', 'currentTaxRates' => ['groupId' => 8, 'taxCategories' => [
        ['name' => 'ECAL', 'categoryType' => 0, 'taxRates' => [['rate' => 11, 'label' => 'F']]],
        ['name' => 'VAT', 'categoryType' => 0, 'taxRates' => [
            ['rate' => 10, 'label' => 'A'], ['rate' => 0, 'label' => 'B'], ['rate' => 19, 'label' => 'Ж'],
        ]],
    ]]];
    $c = config(); $c['vat_rate'] = 19; $c['tax_label'] = 'Ж';
    Checks::status($status, $c);
    $c['vat_rate'] = 20; fails(fn() => Checks::status($status, $c));
    $c['vat_rate'] = 19; $c['tax_label'] = 'Zh'; fails(fn() => Checks::status($status, $c));
});
test('19% test invoice produces 1190 RSD; 20% invoice is refused in 19% mode', function (): void {
    $c = config(); $c['vat_rate'] = 19; $c['tax_label'] = 'Ж';
    fails(fn() => Builder::build(fixture(), $c, 'a'));
    $s = fixture(); $s['invoice']['taxrate'] = '19.000';
    $s['invoice']['tax'] = '190.00'; $s['invoice']['total'] = '1190.00';
    $s['transactions'][0]['amountin'] = '1190.00';
    $p = Builder::build($s, $c, 'a');
    same(119000, Money::cents($p['payment'][0]['amount']));
    same(119000, Money::cents($p['items'][0]['totalAmount']));
    same(['Ж'], $p['items'][0]['labels']);
});
test('19% VAT rounding preserves total across multiple low-value items', function (): void {
    $c = config(); $c['vat_rate'] = 19; $c['tax_label'] = 'Ж';
    $s = fixture(); $s['invoice'] = array_merge($s['invoice'], ['taxrate' => '19.000', 'subtotal' => '0.06', 'tax' => '0.01', 'total' => '0.07']);
    $s['items'] = [['description' => 'a', 'amount' => '0.03', 'taxed' => 1], ['description' => 'b', 'amount' => '0.03', 'taxed' => 1]];
    $s['transactions'][0]['amountin'] = '0.07';
    $p = Builder::build($s, $c, 'a');
    same(7, array_sum(array_map(fn($i) => Money::cents($i['totalAmount']), $p['items'])));
});
test('a 19% non-VAT category cannot masquerade as VAT', function (): void {
    $c = config(); $c['vat_rate'] = 19; $c['tax_label'] = 'Ж';
    $s = ['uid' => 'ABCD1234', 'currentTaxRates' => ['taxCategories' => [
        ['name' => 'ECAL', 'categoryType' => 0, 'taxRates' => [['rate' => 19, 'label' => 'Ж']]],
    ]]];
    fails(fn() => Checks::status($s, $c));
});
test('invalid configured VAT cannot enter invoice calculations', function (): void {
    foreach ([0, -1, 100, '19', 19.5] as $rate) {
        $c = config(); $c['vat_rate'] = $rate;
        fails(fn() => Builder::build(fixture(), $c, 'a'));
    }
});
test('complete sandbox 19% issuance uses matching status and actual gross total', function (): void {
    $c = config(); $c['vat_rate'] = 19; $c['tax_label'] = 'Ж';
    $s = fixture(); $s['invoice']['taxrate'] = '19.000';
    $s['invoice']['tax'] = '190.00'; $s['invoice']['total'] = '1190.00'; $s['transactions'][0]['amountin'] = '1190.00';
    $api = new FakeApi(); $api->statusData = ['uid' => 'ABCD1234', 'currentTaxRates' => ['taxCategories' => [
        ['name' => 'VAT', 'categoryType' => 0, 'taxRates' => [['rate' => 19, 'label' => 'Ж']]],
    ]]];
    $api->answers = [['code' => 200, 'data' => result(1190.0)]];
    $repo = new MemoryRepo();
    same('issued', (new Service($repo, $api, $c))->issue(10, $s, 1)['state']);
    same(1, $api->posts); same(null, $repo->guard);
});
test('URL scheme, host, credentials and port restrictions', function (): void {
    same('', Checks::verificationUrl('javascript:alert(1)'));
    same('', Checks::verificationUrl('https://sandbox.suf.purs.gov.rs.attacker.test/v/?vl=x'));
    same('', Checks::verificationUrl('https://a@sandbox.suf.purs.gov.rs/v/?vl=x'));
    same('', Checks::verificationUrl('https://sandbox.suf.purs.gov.rs:444/v/?vl=x'));
    same(result()['verificationUrl'], Checks::verificationUrl(result()['verificationUrl']));
});
test('one POST for repeated submit of an issued invoice', function (): void {
    $repo = new MemoryRepo(); $api = new FakeApi(); $service = new Service($repo, $api, config());
    same('issued', $service->issue(1, fixture(), 1)['state']);
    same('issued', $service->issue(1, fixture(), 1)['state']); same(1, $api->posts); same(null, $repo->guard);
});
test('mixed-case V-PFR signedBy invoice number is accepted unchanged', function (): void {
    $r = result(); $r['invoiceNumber'] = 'ABCD1234-Dt1Ov2o0-1'; $r['signedBy'] = 'Dt1Ov2o0';
    $api = new FakeApi(); $api->answers = [['code' => 200, 'data' => $r]];
    $repo = new MemoryRepo();
    $row = (new Service($repo, $api, config()))->issue(1, fixture(), 1);
    same('issued', $row['state']); same('ABCD1234-Dt1Ov2o0-1', $row['response']['invoiceNumber']);
});
test('recover old unknown response from saved JSON with zero network requests', function (): void {
    $repo = new MemoryRepo();
    $repo->claim(1, Builder::build(fixture(), config(), 'a'), 1);
    $r = result(); $r['invoiceNumber'] = 'ABCD1234-Dt1Ov2o0-1';
    $repo->finish(1, 'unknown', json_encode($r), 'Old validator', false);
    $requestId = $repo->find(1)['request_id'];
    $api = new FakeApi();
    $row = (new Service($repo, $api, config()))->reconcile(1);
    same('issued', $row['state']); same(null, $repo->guard); same([], $api->calls);
    same($requestId, $row['request_id']); same('ABCD1234-Dt1Ov2o0-1', $row['response']['invoiceNumber']);
});
test('saved mismatched response does not bypass reconciliation or release lock', function (): void {
    $repo = new MemoryRepo(); $repo->claim(1, Builder::build(fixture(), config(), 'a'), 1);
    $repo->finish(1, 'unknown', json_encode(result(1.0)), 'Wrong total', false);
    $api = new FakeApi(); $api->answers = [['code' => 200, 'data' => null]];
    $row = (new Service($repo, $api, config()))->reconcile(1);
    same('unknown', $row['state']); same(1, $repo->guard); same(0, $api->posts);
});
test('timeout locks all new issuance; reconcile uses GET and original ID', function (): void {
    $repo = new MemoryRepo(); $api = new FakeApi(); $api->answers = [new RuntimeException('Timeout')];
    $service = new Service($repo, $api, config());
    same('unknown', $service->issue(1, fixture(), 1)['state']);
    fails(fn() => $service->issue(2, fixture(), 1));
    fails(fn() => $service->issue(1, fixture(), 1)); same(1, $api->posts);
    same('issued', $service->reconcile(1)['state']); same(null, $repo->guard);
    same(['GET', '/api/v3/invoices/' . str_pad('1', 32, '0', STR_PAD_LEFT), null], end($api->calls));
});
test('null lookup never triggers another POST or clears lock', function (): void {
    $repo = new MemoryRepo(); $api = new FakeApi();
    $api->answers = [new RuntimeException('Lost response'), ['code' => 200, 'data' => null]];
    $service = new Service($repo, $api, config());
    $service->issue(1, fixture(), 1); same('unknown', $service->reconcile(1)['state']);
    same(1, $api->posts); same(1, $repo->guard);
});
test('wrong response total retained as unknown, not success', function (): void {
    $repo = new MemoryRepo(); $api = new FakeApi(); $api->answers = [['code' => 200, 'data' => result(1199.99)]];
    $service = new Service($repo, $api, config()); same('unknown', $service->issue(1, fixture(), 1)['state']); same(1, $repo->guard);
});
test('explicit validation rejection releases guard but does not retry', function (): void {
    $repo = new MemoryRepo(); $api = new FakeApi(); $api->answers = [['code' => 400, 'data' => ['message' => 'Invalid request']]];
    $service = new Service($repo, $api, config()); same('rejected', $service->issue(1, fixture(), 1)['state']);
    same(null, $repo->guard); fails(fn() => $service->issue(1, fixture(), 1)); same(1, $api->posts);
});
test('HTTP 500 retained as unknown even with error JSON', function (): void {
    $repo = new MemoryRepo(); $api = new FakeApi(); $api->answers = [['code' => 500, 'data' => ['message' => 'Server error']]];
    $service = new Service($repo, $api, config()); same('unknown', $service->issue(1, fixture(), 1)['state']); same(1, $repo->guard);
});
test('invalid preflight tax does not send an invoice', function (): void {
    $repo = new MemoryRepo(); $api = new FakeApi(); $c = config(); $c['tax_label'] = 'WRONG';
    fails(fn() => (new Service($repo, $api, $c))->issue(1, fixture(), 1)); same(0, $api->posts); same(null, $repo->guard);
});
test('production URL is refused by configuration', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'onet-config-'); chmod($path, 0600);
    file_put_contents($path, '<?php return ["environment"=>"production"];');
    try { fails(fn() => Config::load($path)); } finally { unlink($path); }
});
echo "\n$count tests passed. No live requests made.\n";
