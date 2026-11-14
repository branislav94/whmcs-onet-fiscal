<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
class_alias(\Illuminate\Database\Capsule\Manager::class, 'WHMCS\\Database\\Capsule');
require __DIR__ . '/../modules/addons/onetfiskal/lib/Core.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Synthetic.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Runtime.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/Admin.php';
require __DIR__ . '/../modules/addons/onetfiskal/lib/TestAdmin.php';

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
    foreach ([Store::GUARD, Store::TABLE, Store::TESTS, 'tblinvoices', 'tblaccounts', 'tblconfiguration'] as $name) {
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
    equal(true, str_contains($html, '1190.00 RSD'));
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
try {
    foreach ($tests as $name => $test) { $test(); echo 'PASS [' . $driver . '] ' . $name . PHP_EOL; }
    echo count($tests) . ' database integration tests passed. No live fiscal requests made.' . PHP_EOL;
} finally {
    unlink($certPath);
}
