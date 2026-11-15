<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
class_alias(\Illuminate\Database\Capsule\Manager::class, 'WHMCS\\Database\\Capsule');
require __DIR__ . '/../modules/addons/onetfiskal/lib/Core.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Synthetic.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Runtime.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Admin.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Ui.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Receipt.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Journal.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/TestAdmin.php';

require __DIR__ . '/../modules/addons/onetfiskal/lib/Refund.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/RefundAdmin.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Copy.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/CopyAdmin.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Advance.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/AdvanceAdmin.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Delivery.php';

use WHMCS\Database\Capsule;
use Onet\Fiskal\Builder;
use Onet\Fiskal\Repository;
use Onet\Fiskal\Service;
use Onet\Fiskal\Store;
use Onet\Fiskal\Synthetic;
use Onet\Fiskal\Transport;
use Onet\Fiskal\Admin;
use Onet\Fiskal\TestAdmin;

$driver = getenv('DB_DRIVER') ?: 'sqlite';
if (!in_array($driver, ['sqlite', 'mysql'], true)) { throw new RuntimeException('Unsupported test database'); }
$capsule = new Capsule();
if ($driver === 'sqlite') {
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
} else {
    // This suite deletes only its fixed test tables, inside a dedicated test database.
    if (getenv('DB_DATABASE') !== 'onet_fiscal_test') { throw new RuntimeException('Use the dedicated onet_fiscal_test database'); }
    $capsule->addConnection(['driver' => 'mysql', 'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306', 'database' => 'onet_fiscal_test',
        'username' => getenv('DB_USERNAME'), 'password' => getenv('DB_PASSWORD'),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']);
}
$capsule->setAsGlobal();
$capsule->bootEloquent();
$certPath = tempnam(sys_get_temp_dir(), 'onet-fake-cert-');
file_put_contents($certPath, 'synthetic-test-fingerprint-only');
$config = ['environment' => 'sandbox', 'vsdc_url' => 'https://vsdc.sandbox.suf.purs.gov.rs',
    'pfx_path' => $certPath, 'expected_uid' => 'ABCD1234', 'vat_rate' => 19, 'tax_label' => 'Ж',
    'payment_map' => ['onetipsqr' => 4, 'corvuspay' => 2]];
$credential = hash_file('sha256', $certPath);
$snapshot = Synthetic::snapshot(['net_amount' => '1000.00', 'gateway' => 'onetipsqr'], $config);

function equal(mixed $a, mixed $b): void {
    if ($a !== $b) { throw new RuntimeException('Mismatch: ' . var_export([$a, $b], true)); }
}
function rejected(callable $f): void {
    try { $f(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Expected operation to be blocked');
}
function resetDatabase(): void {
    foreach ([Store::GUARD, Store::TABLE, Store::TESTS, Store::REFUNDS, Store::COPIES, Store::ADVANCES, Store::ADVANCE_GROUPS, 'tbladmins', 'tbladdonmodules', 'tblclients', 'tblinvoices', 'tblaccounts', 'tblconfiguration'] as $name) {
        Capsule::schema()->dropIfExists($name);
    }
    Store::install();
}
final class DbApi implements Transport {
    public int $posts = 0;
    public bool $timeout = false;
    public function request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array {
        if ($path === '/api/v3/status') {
            return ['code' => 200, 'data' => ['uid' => 'ABCD1234', 'currentTaxRates' => ['taxCategories' => [
                ['name' => 'VAT', 'categoryType' => 0, 'taxRates' => [['rate' => 19, 'label' => 'Ж']]],
            ]]]];
        }
        if ($method === 'POST') {
            $this->posts++;
            if ($this->timeout) { throw new RuntimeException('Simulated timeout'); }
        }
        return ['code' => 200, 'data' => ['requestedBy' => 'ABCD1234', 'invoiceNumber' => 'ABCD1234-Dt1Ov2o0-1',
            'totalAmount' => 1190, 'journal' => 'SANDBOX TEST', 'sdcDateTime' => '2026-11-14T12:00:00+01:00',
            'signature' => 'mock-signature', 'verificationUrl' => 'https://sandbox.suf.purs.gov.rs/v/?vl=test']];
    }
}

function tinyGif(): string {
    return 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
}
function receiptFixture(int $id): array {
    return ['invoiceNumber' => 'ABCD1234-Dt1Ov2o0-' . $id,
        'journal' => "ORIGINAL JOURNAL\n  1.190,00\n", 'sdcDateTime' => '2026-11-14T12:00:00+01:00',
        'verificationUrl' => 'https://sandbox.suf.purs.gov.rs/v/?vl=test', 'verificationQRCode' => tinyGif()];
}
function evidence(string $source, int $id, array $extra = []): void {
    Capsule::table($source === 'invoice' ? Store::TABLE : Store::TESTS)->insert(array_merge([
        'invoice_id' => $id, 'state' => 'issued', 'request_id' => str_pad((string) $id, 32, '0', STR_PAD_LEFT),
        'response' => json_encode(receiptFixture($id), JSON_UNESCAPED_UNICODE),
        'created_at' => '2026-11-14 12:00:00', 'updated_at' => '2026-11-14 12:00:00'], $extra));
}
function accessFixtures(): void {
    Capsule::schema()->create('tbladmins', function ($t): void { $t->integer('id'); $t->integer('roleid'); $t->integer('disabled'); });
    Capsule::schema()->create('tbladdonmodules', function ($t): void { $t->string('module'); $t->string('setting'); $t->string('value'); });
    Capsule::table('tbladmins')->insert([['id' => 1, 'roleid' => 1, 'disabled' => 0],
        ['id' => 2, 'roleid' => 1, 'disabled' => 1], ['id' => 3, 'roleid' => 2, 'disabled' => 0]]);
    Capsule::table('tbladdonmodules')->insert(['module' => 'onetfiskal', 'setting' => 'access', 'value' => '1']);
}

$tests = [];
$tests['migration is repeatable and preserves an old pending invoice lock'] = function () use ($snapshot): void {
    resetDatabase();
    (new Store())->queue(48);
    Capsule::table(Store::GUARD)->where('id', 1)->update(['attempt_id' => 1]);
    Capsule::schema()->table(Store::GUARD, fn($t) => $t->dropColumn('source'));
    Capsule::schema()->drop(Store::TESTS);
    Store::install(); Store::install();
    equal(1, Capsule::table(Store::GUARD)->where('id', 1)->value('attempt_id'));
    equal('invoice', Capsule::table(Store::GUARD)->where('id', 1)->value('source'));
    equal('review', (new Store())->find(48)['state']);
    equal(true, Capsule::schema()->hasTable(Store::TESTS));
};
$tests['duplicate preview returns the same immutable draft'] = function () use ($credential, $snapshot): void {
    resetDatabase(); $repo = new Store($credential, 'synthetic'); $key = bin2hex(random_bytes(16));
    $a = $repo->createSynthetic($snapshot, $key, 1);
    $changed = $snapshot; $changed['invoice']['total'] = '1.00';
    $b = $repo->createSynthetic($changed, $key, 1);
    equal($a['id'], $b['id']); equal($a['snapshot'], $b['snapshot']);
    equal(1, Capsule::table(Store::TESTS)->count());
    rejected(fn() => $repo->createSynthetic($snapshot, $key, 2));
};
$tests['synthetic issuance does not touch WHMCS billing or tax configuration'] = function () use ($credential, $snapshot, $config): void {
    resetDatabase();
    foreach (['tblinvoices', 'tblaccounts', 'tblconfiguration'] as $name) {
        Capsule::schema()->create($name, function ($t): void { $t->increments('id'); $t->string('sentinel'); });
        Capsule::table($name)->insert(['sentinel' => 'keep']);
    }
    $repo = new Store($credential, 'synthetic'); $draft = $repo->createSynthetic($snapshot, bin2hex(random_bytes(16)), 1);
    $api = new DbApi();
    $r = (new Service($repo, $api, $config))->issue((int) $draft['invoice_id'], $snapshot, 1);
    equal('issued', $r['state']); equal(1, $api->posts); equal(0, Capsule::table(Store::TABLE)->count());
    foreach (['tblinvoices', 'tblaccounts', 'tblconfiguration'] as $name) {
        equal(1, Capsule::table($name)->count()); equal('keep', Capsule::table($name)->value('sentinel'));
    }
};
$tests['pending invoice blocks synthetic issuance and retains the original source'] = function () use ($credential, $snapshot, $config): void {
    resetDatabase(); $invoiceRepo = new Store($credential); $testRepo = new Store($credential, 'synthetic');
    $api = new DbApi(); $api->timeout = true;
    equal('unknown', (new Service($invoiceRepo, $api, $config))->issue(48, $snapshot, 1)['state']);
    $draft = $testRepo->createSynthetic($snapshot, bin2hex(random_bytes(16)), 1);
    rejected(fn() => (new Service($testRepo, $api, $config))->issue((int) $draft['invoice_id'], $snapshot, 1));
    equal(1, $api->posts); equal('invoice', Capsule::table(Store::GUARD)->value('source'));
    equal('review', $testRepo->find((int) $draft['invoice_id'])['state']);
};
$tests['pending synthetic test blocks invoice issuance and survives new service instances'] = function () use ($credential, $snapshot, $config): void {
    resetDatabase(); $repo = new Store($credential, 'synthetic');
    $draft = $repo->createSynthetic($snapshot, bin2hex(random_bytes(16)), 1); $id = (int) $draft['invoice_id'];
    $api = new DbApi(); $api->timeout = true;
    equal('unknown', (new Service($repo, $api, $config))->issue($id, $snapshot, 1)['state']);
    rejected(fn() => (new Service(new Store($credential), $api, $config))->issue(48, $snapshot, 1));
    equal('synthetic', Capsule::table(Store::GUARD)->value('source')); equal(1, $api->posts);
    $freshApi = new DbApi();
    equal('issued', (new Service(new Store($credential, 'synthetic'), $freshApi, $config))->reconcile($id)['state']);
    equal(0, $freshApi->posts); equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
};
$tests['same row IDs in separate sources cannot release each others lock'] = function () use ($credential, $snapshot): void {
    resetDatabase(); $a = new Store($credential); $b = new Store($credential, 'synthetic');
    $a->queue(48); $draft = $b->createSynthetic($snapshot, bin2hex(random_bytes(16)), 1);
    equal(1, (int) $draft['id']);
    $b->claim((int) $draft['invoice_id'], Builder::build($snapshot, ['vat_rate' => 19, 'tax_label' => 'Ж', 'payment_map' => ['onetipsqr' => 4]], 'a'), 1);
    $a->finish(1, 'rejected', ['message' => 'local test'], 'test', true);
    equal(1, Capsule::table(Store::GUARD)->value('attempt_id')); equal('synthetic', Capsule::table(Store::GUARD)->value('source'));
};
$tests['repeated claim on the same synthetic run cannot create a second request'] = function () use ($credential, $snapshot, $config): void {
    resetDatabase(); $repo = new Store($credential, 'synthetic');
    $draft = $repo->createSynthetic($snapshot, bin2hex(random_bytes(16)), 1); $id = (int) $draft['invoice_id'];
    $payload = Builder::build($snapshot, $config, 'a'); $first = $repo->claim($id, $payload, 1);
    rejected(fn() => $repo->claim($id, $payload, 1));
    equal($first['request_id'], $repo->find($id)['request_id']);
};
$tests['sandbox preview UI renders a frozen draft without a live API call'] = function () use ($config): void {
    resetDatabase(); $_SESSION = ['adminid' => 1, 'onetfiskal_csrf' => str_repeat('a', 64)];
    $_POST = ['draft_key' => str_repeat('b', 32), 'scenario' => 'hosting', 'net_amount' => '1000.00', 'gateway' => 'onetipsqr'];
    ob_start();
    try {
        $id = TestAdmin::handle('synthetic_preview', $config, 0);
        TestAdmin::render($config, $id);
        $html = ob_get_contents();
    } finally { ob_end_clean(); }
    equal(true, str_contains($html, '1.190,00') && str_contains($html, 'RSD'));
    equal(true, str_contains($html, 'Prenos na račun'));
    equal(true, str_contains($html, 'name="review_hash"'));
    equal(true, str_contains($html, 'synthetic_issue'));
    equal(0, Capsule::table(Store::TABLE)->count());
    equal('review', Capsule::table(Store::TESTS)->value('state'));
    equal(null, Capsule::table(Store::GUARD)->value('attempt_id'));
    $_POST = [];
};
$tests['receipt HTML escapes untrusted journal content and rejects non-official links'] = function (): void {
    ob_start();
    try {
        Admin::receipt(['invoiceNumber' => '<script>x</script>', 'journal' => '<img src=x onerror=x>',
            'verificationUrl' => 'javascript:alert(1)']);
        $html = ob_get_contents();
    } finally { ob_end_clean(); }
    equal(false, str_contains($html, '<script>'));
    equal(false, str_contains($html, '<img'));
    equal(false, str_contains($html, 'javascript:'));
    equal(true, str_contains($html, '&lt;img'));
};
$tests['journal includes old receipts beyond thirty records and keeps sources distinct'] = function (): void {
    resetDatabase();
    for ($i = 1; $i <= 61; $i++) {
        evidence('invoice', $i, ['created_at' => '2026-01-01 12:00:00']);
    }
    evidence('synthetic', 1, ['created_at' => '2026-02-01 12:00:00']);
    $first = \Onet\Fiskal\Journal::search([]);
    equal(62, $first['total']); equal(3, $first['pages']); equal(25, count($first['rows']));
    equal('synthetic', $first['rows'][0]['source']);
    $last = \Onet\Fiskal\Journal::search(['page' => 3]);
    equal(12, count($last['rows']));
    equal(1, (int) $last['rows'][11]['invoice_id']);
    equal(2, \Onet\Fiskal\Journal::search(['q' => '1'])['total']);
};
$tests['journal date bounds are inclusive UTC and status defaults to issued'] = function (): void {
    resetDatabase();
    evidence('invoice', 1, ['created_at' => '2026-11-01 00:00:00']);
    evidence('invoice', 2, ['created_at' => '2026-11-30 23:59:59']);
    evidence('invoice', 3, ['created_at' => '2026-12-01 00:00:00']);
    evidence('invoice', 4, ['state' => 'unknown', 'created_at' => '2026-11-02 00:00:00']);
    equal(2, \Onet\Fiskal\Journal::search(['from' => '2026-11-01', 'to' => '2026-11-30'])['total']);
    equal(4, \Onet\Fiskal\Journal::search(['state' => 'all'])['total']);
    equal(1, \Onet\Fiskal\Journal::search(['state' => 'unknown'])['total']);
};
$tests['journal filters bind literal text and cannot expand SQL wildcard searches'] = function (): void {
    resetDatabase(); evidence('invoice', 1); evidence('synthetic', 2);
    equal(1, \Onet\Fiskal\Journal::search(['source' => 'synthetic'])['total']);
    equal(1, \Onet\Fiskal\Journal::search(['q' => 'ABCD1234-Dt1Ov2o0-2'])['total']);
    equal(1, \Onet\Fiskal\Journal::search(['q' => str_pad('1', 32, '0', STR_PAD_LEFT)])['total']);
    foreach (["%' OR 1=1 --", '%', '_', '!'] as $q) {
        equal(0, \Onet\Fiskal\Journal::search(['q' => $q])['total']);
    }
    foreach ([['source' => 'tbladmins'], ['state' => 'bad'], ['q' => ['x']], ['from' => '2026-02-30'],
        ['from' => '2026-12-01', 'to' => '2026-11-01'], ['page' => '-1']] as $input) {
        rejected(fn() => \Onet\Fiskal\Journal::filters($input));
    }
};
$tests['journal escapes response text and supports corrupt saved JSON without crashing'] = function (): void {
    resetDatabase();
    evidence('invoice', 1, ['response' => json_encode(['invoiceNumber' => '<script>alert(1)</script>', 'sdcDateTime' => '<img src=x>'])]);
    evidence('synthetic', 2, ['response' => 'broken json']);
    ob_start(); try { \Onet\Fiskal\Journal::render(['q' => '<script>']); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(false, str_contains($html, '<script>')); equal(true, str_contains($html, '&lt;script&gt;'));
    equal(1, \Onet\Fiskal\Journal::search(['page' => 999])['filters']['page']);
};
$tests['saved receipt QR uses only validated embedded GIF and preserves original journal'] = function (): void {
    $response = receiptFixture(1); $response['verificationQRCode'] = tinyGif();
    $html = \Onet\Fiskal\Receipt::document($response);
    equal(true, str_contains($html, 'data:image/gif;base64,'));
    equal(true, str_contains($html, 'Ctrl+P')); equal(true, str_contains($html, 'SANDBOX'));
    equal(true, str_contains($html, 'white-space:pre;'));
    equal(true, str_contains($html, "default-src &#039;none&#039;") || str_contains($html, "default-src 'none'"));
    equal(false, str_contains($html, '<script'));
    foreach (['data:image/svg+xml,<svg onload=alert(1)>', base64_encode('<svg onload=alert(1)>'), 'not base64', str_repeat('A', 1400001)] as $bad) {
        equal('', \Onet\Fiskal\Receipt::qr(['verificationQRCode' => $bad]));
    }
    $response['verificationQRCode'] = '';
    equal(true, str_contains(\Onet\Fiskal\Receipt::fragment($response), 'QR kod nedostaje'));
};
$tests['export denies anonymous disabled and unauthorized administrators before reading receipts'] = function (): void {
    resetDatabase(); accessFixtures(); evidence('invoice', 1);
    foreach ([[], ['adminid' => 2], ['adminid' => 3]] as $session) {
        $_SESSION = $session;
        try { \Onet\Fiskal\ReceiptExport::load(['source' => 'invoice', 'id' => 1]); throw new LogicException('Access allowed'); }
        catch (RuntimeException $e) { equal(403, $e->getCode()); }
    }
};
$tests['authorized exports require issued evidence and never need the current certificate or billing rows'] = function (): void {
    resetDatabase(); accessFixtures(); $_SESSION = ['adminid' => 1];
    evidence('invoice', 48); evidence('synthetic', 48);
    evidence('invoice', 49, ['state' => 'unknown']);
    Capsule::table(Store::GUARD)->where('id', 1)->update(['attempt_id' => 99]);
    $before = Capsule::table(Store::TABLE)->get()->toJson();
    $text = \Onet\Fiskal\ReceiptExport::load(['source' => 'invoice', 'id' => 48, 'format' => 'txt']);
    equal(receiptFixture(48)['journal'], $text['body']);
    equal('ONET-sandbox-invoice-48.txt', $text['filename']);
    equal(true, $text['download']);
    $html = \Onet\Fiskal\ReceiptExport::load(['source' => 'synthetic', 'id' => 48, 'format' => 'html', 'download' => '1']);
    equal(true, str_contains($html['body'], '<!doctype html>'));
    equal(true, $html['download']);
    foreach ([['source' => 'invoice', 'id' => 49], ['source' => 'invoice', 'id' => 999], ['source' => 'other', 'id' => 48],
        ['source' => 'invoice', 'id' => '../48'], ['source' => 'invoice', 'id' => 48, 'format' => 'php']] as $input) {
        rejected(fn() => \Onet\Fiskal\ReceiptExport::load($input));
    }
    equal($before, Capsule::table(Store::TABLE)->get()->toJson());
    equal(99, (int) Capsule::table(Store::GUARD)->value('attempt_id'));
};
$tests['issued synthetic receipt remains visible without current private configuration'] = function (): void {
    resetDatabase(); evidence('synthetic', 48); $_SESSION = ['adminid' => 1];
    ob_start(); try { TestAdmin::render(null, 48); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'ABCD1234-Dt1Ov2o0-48'));
    equal(true, str_contains($html, 'Preuzmi HTML'));
    equal(false, str_contains($html, 'synthetic_issue'));
};

$tests['receipt links resolve from the admin directory and WHMCS subdirectory'] = function (): void {
    global $CONFIG;
    $CONFIG = [];
    equal('../modules/addons/onetfiskal/receipt.php', \Onet\Fiskal\Receipt::endpoint());
    $CONFIG = ['SystemURL' => 'https://panel.example.test/billing/'];
    equal('https://panel.example.test/billing/modules/addons/onetfiskal/receipt.php', \Onet\Fiskal\Receipt::endpoint());
    $CONFIG = ['SystemURL' => 'javascript:alert(1)'];
    equal('../modules/addons/onetfiskal/receipt.php', \Onet\Fiskal\Receipt::endpoint());
    $CONFIG = [];
};

require __DIR__ . '/refund-cases.php';
require __DIR__ . '/copy-cases.php';
require __DIR__ . '/advance-cases.php';
require __DIR__ . '/ui-cases.php';
require __DIR__ . '/delivery-cases.php';

try {
    foreach ($tests as $name => $test) { $test(); echo 'PASS [' . $driver . '] ' . $name . PHP_EOL; }
    echo count($tests) . ' database integration tests passed. No live fiscal requests made.' . PHP_EOL;
} finally {
    unlink($certPath);
}
