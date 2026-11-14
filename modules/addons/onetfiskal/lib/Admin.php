<?php
declare(strict_types=1);

namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

final class Admin
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function allowed(): bool
    {
        $id = (int) ($_SESSION['adminid'] ?? 0);
        if ($id < 1) { return false; }
        $role = Capsule::table('tbladmins')->where('id', $id)->where('disabled', 0)->value('roleid');
        $access = (string) Capsule::table('tbladdonmodules')->where('module', 'onetfiskal')->where('setting', 'access')->value('value');
        return $role && in_array((string) $role, explode(',', $access), true);
    }

    public static function form(string $action, int $invoiceId, string $label, string $extra = ''): string
    {
        return '<form method="post" action="addonmodules.php?module=onetfiskal&amp;invoiceid=' . $invoiceId . '" style="display:inline-block;margin:8px 8px 8px 0">'
            . (function_exists('generate_token') ? generate_token('form') : '')
            . '<input type="hidden" name="onet_token" value="' . self::e($_SESSION['onetfiskal_csrf']) . '">'
            . '<input type="hidden" name="action" value="' . self::e($action) . '">'
            . '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">'
            . $extra . '<button class="btn btn-primary" type="submit">' . self::e($label) . '</button></form>';
    }

    public static function render(array $vars): void
    {
        if (!self::allowed()) {
            echo '<div class="alert alert-danger">Nemaš pristup modulu. Podesi Access Control u Addon Modules.</div>';
            return;
        }
        try {
            Store::install();
        } catch (\Throwable) {
            echo '<div class="alert alert-danger">Nadogradnja evidencije nije uspela. Proveri dozvole baze i otvori modul ponovo. Ne deaktiviraj modul i ne briši prethodnu evidenciju.</div>';
            return;
        }
        if (empty($_SESSION['onetfiskal_csrf'])) { $_SESSION['onetfiskal_csrf'] = bin2hex(random_bytes(32)); }
        $id = max(0, (int) ($_POST['invoiceid'] ?? $_GET['invoiceid'] ?? 0));
        $testId = max(0, (int) ($_POST['testid'] ?? $_GET['testid'] ?? 0));
        $testView = ($_POST['view'] ?? $_GET['view'] ?? '') === 'tests' || $testId > 0;
        echo '<div style="max-width:1100px"><h2>ONET Fiskal <small>' . Product::VERSION . ' · SANDBOX</small></h2>';
        echo '<div class="alert alert-warning"><strong>Razvojno okruženje — računi nisu za stvarni promet.</strong> '
            . 'Ova verzija izdaje samo test Promet–Prodaja za domaća fizička lica: jedna puna uplata, RSD, podešena sandbox VAT stopa, cene bez poreza u WHMCS-u. '
            . 'Avansi, refundacije, B2B, kredit sa naloga i automatsko slanje klijentima nisu uključeni.</div>';
        echo Product::information();
        echo '<style>' . Receipt::styles() . '</style>';
        $config = null;
        try {
            $config = Config::load((string) ($vars['configpath'] ?? ''));
        } catch (\Throwable $e) {
            echo '<div class="alert alert-info">' . self::e($e->getMessage()) . ' Pogledaj README-SR.md iz ZIP-a.</div>';
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (!isset($_POST['onet_token']) || !hash_equals($_SESSION['onetfiskal_csrf'], (string) $_POST['onet_token'])) {
                    throw new RuntimeException('Sesija ili CSRF token nisu važeći. Osveži stranicu.');
                }
                if (!$config) { throw new RuntimeException('Prvo podesi konfiguraciju.'); }
                $action = (string) ($_POST['action'] ?? '');
                if (str_starts_with($action, 'synthetic_')) {
                    $testView = true;
                    $testId = TestAdmin::handle($action, $config, $testId);
                } else {
                $api = new Vsdc($config);
                $service = new Service(new Store(hash_file('sha256', $config['pfx_path'])), $api, $config);
                if ($action === 'test') {
                    $status = $service->preflight();
                    echo '<div class="alert alert-success">Veza je uspešna. JID: ' . self::e($status['uid']) . '; poreska oznaka '
                        . self::e($config['tax_label']) . ' je aktivna za sandbox VAT ' . self::e($config['vat_rate']) . '%.</div>';
                } elseif ($action === 'issue' && $id > 0) {
                    if (($_POST['confirmed'] ?? '') !== 'yes') {
                        throw new RuntimeException('Potvrdi da je ovo test izvršenog prometa bez ranijih avansa.');
                    }
                    $s = Store::snapshot($id);
                    $hash = Checks::reviewHash($s, $config);
                    if (!hash_equals($hash, (string) ($_POST['snapshot_hash'] ?? ''))) {
                        throw new RuntimeException('WHMCS faktura ili uplata se promenila. Pregledaj nove podatke pre izdavanja.');
                    }
                    $row = $service->issue($id, $s, (int) $_SESSION['adminid']);
                    echo '<div class="alert alert-info">' . self::e($row['message']) . '</div>';
                } elseif ($action === 'reconcile' && $id > 0) {
                    $row = $service->reconcile($id);
                    echo '<div class="alert alert-info">' . self::e($row['message']) . '</div>';
                } else {
                    throw new RuntimeException('Nepoznata akcija.');
                }
                }
            } catch (\Throwable $e) {
                echo '<div class="alert alert-danger">' . self::e($e->getMessage()) . '</div>';
            }
        }
        echo '<p><a class="btn btn-default" href="addonmodules.php?module=onetfiskal">WHMCS fakture</a> '
            . '<a class="btn btn-default" href="addonmodules.php?module=onetfiskal&amp;view=tests">Zaseban sandbox test</a></p>';
        echo '<p><a class="btn btn-default" href="addonmodules.php?module=onetfiskal&amp;view=journal">Elektronski žurnal</a></p>';
        if (($_GET['view'] ?? '') === 'journal' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Journal::render($_GET);
            echo '</div>';
            return;
        }
        echo self::form('test', $id, 'Proveri vezu i poresku oznaku', $testView ? '<input type="hidden" name="view" value="tests"><input type="hidden" name="testid" value="' . $testId . '">' : '');
        $guard = Capsule::table(Store::GUARD)->where('id', 1)->first();
        if ($guard && $guard->attempt_id !== null) {
            $synthetic = ($guard->source ?? 'invoice') === 'synthetic';
            $busyId = Capsule::table($synthetic ? Store::TESTS : Store::TABLE)->where('id', $guard->attempt_id)->value('invoice_id');
            $target = $synthetic ? 'view=tests&amp;testid=' : 'invoiceid=';
            echo '<div class="alert alert-danger">Novo izdavanje je zaključano dok se ne proveri ishod '
                . ($synthetic ? 'zasebnog testa ' : 'WHMCS fakture ') . '<a href="addonmodules.php?module=onetfiskal&amp;'
                . $target . (int) $busyId . '">#' . (int) $busyId . '</a>.</div>';
        }
        if ($testView) {
            TestAdmin::render($config, $testId);
            echo '</div>';
            return;
        }
        echo '<form method="get" action="addonmodules.php" style="margin:16px 0"><input type="hidden" name="module" value="onetfiskal">'
            . '<label>WHMCS ID fakture <input type="number" name="invoiceid" min="1" value="' . ($id ?: '') . '" required></label> '
            . '<button class="btn btn-default">Otvori pregled</button></form>';
        if ($id > 0) {
            self::invoice($id, $config);
        }
        echo '<h3>Poslednje evidentirane fakture</h3><table class="table table-striped"><thead><tr><th>Faktura</th><th>Stanje</th><th>Ažurirano (UTC)</th></tr></thead><tbody>';
        foreach (Capsule::table(Store::TABLE)->orderBy('id', 'desc')->limit(30)->get() as $r) {
            echo '<tr><td><a href="addonmodules.php?module=onetfiskal&amp;invoiceid=' . (int) $r->invoice_id . '">#'
                . (int) $r->invoice_id . '</a></td><td>' . self::e($r->state) . '</td><td>' . self::e($r->updated_at) . '</td></tr>';
        }
        echo '</tbody></table><p>Plaćanje samo dodaje fakturu u pregled. U ovoj verziji nijedan hook ne izdaje račun automatski.</p></div>';
    }

    private static function invoice(int $id, ?array $config): void
    {
        try {
            $saved = (new Store())->find($id);
            if ($saved && $saved['state'] === 'issued') {
                echo '<h3>Sačuvan račun za WHMCS fakturu #' . $id . '</h3>';
                self::receipt(Receipt::decode($saved['response']), 'invoice', $id);
                echo '<details><summary>Sačuvan odgovor V-PFR-a</summary><pre>' . self::e($saved['response']) . '</pre></details>';
                return;
            }
            $snapshot = Store::snapshot($id);
            echo '<h3>WHMCS faktura #' . $id . '</h3><p>Status: ' . self::e($snapshot['invoice']['status'])
                . ' · Ukupno: ' . self::e($snapshot['invoice']['total']) . ' ' . self::e($snapshot['currency']) . '</p>';
            $gateways = array_column($snapshot['transactions'], 'gateway');
            echo '<p>Gateway iz transakcija: <code>' . self::e(implode(', ', $gateways)) . '</code></p>';
            $row = (new Store())->find($id);
            if ($row) {
                echo '<p>Stanje: <strong>' . self::e($row['state']) . '</strong> · RequestId: <code>' . self::e($row['request_id']) . '</code></p>';
                echo '<p>' . self::e($row['message']) . '</p>';
            }
            if ($row && $row['state'] === 'issued') {
                self::receipt(json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR));
            } elseif ($row && in_array($row['state'], ['sending', 'unknown'], true)) {
                echo self::form('reconcile', $id, 'Proveri ishod postojećeg zahteva (GET)');
            } elseif (!$row || $row['state'] === 'review') {
                if (!$config) { return; }
                $payload = Builder::build($snapshot, $config, 'WHMCS admin ' . (int) $_SESSION['adminid']);
                echo '<h4>Pregled zahteva</h4><pre style="max-height:380px;overflow:auto">'
                    . self::e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '</pre>';
                $extra = '<input type="hidden" name="snapshot_hash" value="' . Checks::reviewHash($snapshot, $config) . '">'
                    . '<p><label><input type="checkbox" name="confirmed" value="yes" required> Potvrđujem: sandbox test izvršenog prometa, bez ranijih avansa; nije račun za stvarnu prodaju.</label></p>';
                echo self::form('issue', $id, 'Izdaj test Promet–Prodaja', $extra);
            }
            if ($row && !empty($row['response'])) {
                echo '<details><summary>Sačuvan odgovor V-PFR-a</summary><pre style="white-space:pre-wrap">' . self::e($row['response']) . '</pre></details>';
            }
        } catch (\Throwable $e) {
            echo '<div class="alert alert-info">' . self::e($e->getMessage()) . '</div>';
        }
    }

    public static function receipt(array $response, string $source = '', int $id = 0): void
    {
        echo Receipt::fragment($response);
        if (in_array($source, ['invoice', 'synthetic'], true) && $id > 0) {
            echo Receipt::links($source, $id);
        }
    }
}
