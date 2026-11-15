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

    public static function form(string $action, int $invoiceId, string $label, string $extra = '', string $tone = ''): string
    {
        $issuing = $action === 'issue' || str_ends_with($action, '_issue');
        $reconcile = $action === 'reconcile' || str_ends_with($action, '_reconcile');
        $money = str_ends_with($action, '_money');
        if ($tone === '') { $tone = $issuing ? 'issue' : ($money ? 'success' : ($reconcile || $action === 'test' ? 'quiet' : 'primary')); }
        if (!in_array($tone, ['primary', 'issue', 'close', 'refund', 'quiet', 'success'], true)) { $tone = 'primary'; }
        $hint = $issuing ? 'Šalje novi test dokument Poreskoj.' : ($reconcile ? 'Proverava postojeći zahtev. Ne izdaje novi dokument.'
            : ($money ? 'Samo evidencija. Ne šalje nalog banci.' : ($action === 'test' ? 'Provera sertifikata, veze i poreske oznake.' : 'Sledi pregled i potvrda. Dokument se još ne šalje.')));
        return '<form class="of-form" method="post" action="addonmodules.php?module=onetfiskal&amp;invoiceid=' . $invoiceId . '">'
            . (function_exists('generate_token') ? generate_token('form') : '')
            . '<input type="hidden" name="onet_token" value="' . self::e($_SESSION['onetfiskal_csrf'] ?? '') . '">'
            . '<input type="hidden" name="action" value="' . self::e($action) . '">'
            . '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">'
            . $extra . '<div class="of-form-footer"><button class="of-button of-button--' . $tone . '" type="submit">'
            . self::e($label) . '</button><span class="of-form-hint">' . self::e($hint) . '</span></div></form>';
    }

    private static function end(): void
    {
        echo '<footer class="of-footer"><span>ONET Fiskal · ' . Product::VERSION . ' · Samo razvojno okruženje</span></footer>';
        echo Product::information() . '</div>';
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
        $advanceId = max(0, (int) ($_POST['advanceid'] ?? $_GET['advanceid'] ?? 0));
        $advanceView = ($_POST['view'] ?? $_GET['view'] ?? '') === 'advances' || $advanceId > 0;
        $copyId = max(0, (int) ($_POST['copyid'] ?? $_GET['copyid'] ?? 0));
        $copyView = ($_POST['view'] ?? $_GET['view'] ?? '') === 'copies' || $copyId > 0;
        $refundId = max(0, (int) ($_POST['refundid'] ?? $_GET['refundid'] ?? 0));
        $refundView = ($_POST['view'] ?? $_GET['view'] ?? '') === 'refunds' || $refundId > 0;
        $testView = ($_POST['view'] ?? $_GET['view'] ?? '') === 'tests' || $testId > 0;
        echo '<div class="onet-fiscal" id="onet-fiscal"><style>' . Ui::styles() . Receipt::styles() . '</style>';
        echo '<header class="of-header"><div class="of-brand"><span class="of-brandmark" aria-hidden="true">OF</span><div>'
            . '<h2>ONET Fiskal</h2><p>Računi, avansi i povraćaji na jednom mestu</p></div></div>' . Ui::badge('sandbox') . '</header>';
        echo Ui::note('Test okruženje', 'Dokumenti nisu za stvarni promet. Koristi izmišljene podatke; slanje kupcima i produkcija nisu uključeni.', 'sandbox');
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
                if (str_starts_with($action, 'advance_')) {
                    $advanceView = true;
                    $advanceId = AdvanceAdmin::handle($action, $config, $advanceId);
                } elseif (str_starts_with($action, 'copy_')) {
                    $copyView = true;
                    $copyId = CopyAdmin::handle($action, $config, $copyId);
                } elseif (str_starts_with($action, 'refund_')) {
                    $refundView = true;
                    $refundId = RefundAdmin::handle($action, $config, $refundId);
                } elseif (str_starts_with($action, 'synthetic_')) {
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
        $active = $advanceView ? 'advances' : ($copyView ? 'copies' : ($refundView ? 'refunds' : ($testView ? 'tests' : (($_POST['view'] ?? $_GET['view'] ?? '') === 'journal' ? 'journal' : 'invoices'))));
        echo Ui::nav($active);
        $context = '<input type="hidden" name="view" value="' . self::e($active) . '">'
            . '<input type="hidden" name="testid" value="' . $testId . '"><input type="hidden" name="refundid" value="' . $refundId . '">'
            . '<input type="hidden" name="copyid" value="' . $copyId . '"><input type="hidden" name="advanceid" value="' . $advanceId . '">'
            . '<input type="hidden" name="groupid" value="' . max(0, (int) ($_POST['groupid'] ?? $_GET['groupid'] ?? 0)) . '">';
        echo '<div class="of-toolbar"><span class="of-muted">' . ($config ? 'Konfiguracija je učitana · RSD · Sandbox VAT ' . self::e($config['vat_rate']) . '%' : 'Potrebno je podesiti privatnu konfiguraciju') . '</span><div class="of-actions">'
            . self::form('test', $id, 'Proveri vezu', $context, 'quiet') . '</div></div>';
        $guard = Capsule::table(Store::GUARD)->where('id', 1)->first();
        if ($guard && $guard->attempt_id !== null) {
            $source = $guard->source ?? 'invoice';
            $table = match ($source) { 'refund' => Store::REFUNDS, 'synthetic' => Store::TESTS, 'copy' => Store::COPIES, 'advance' => Store::ADVANCES, default => Store::TABLE };
            $busyId = (int) Capsule::table($table)->where('id', $guard->attempt_id)->value('invoice_id');
            $target = $source === 'copy' ? CopyAdmin::url($busyId) : CopyAdmin::originalUrl($source, $busyId);
            echo '<div class="alert alert-danger">Novo izdavanje je zaključano dok se ne proveri ishod zahteva '
                . '<a href="' . self::e($target) . '">' . self::e($source) . ' #' . $busyId . '</a>.</div>';
        }
        if ($active === 'journal') {
            Journal::render($_GET);
            self::end();
            return;
        }
        if ($advanceView) {
            AdvanceAdmin::render($config, $advanceId, max(0, (int) ($_POST['groupid'] ?? $_GET['groupid'] ?? 0)));
            self::end();
            return;
        }
        if ($copyView) {
            CopyAdmin::render($config, $copyId);
            self::end();
            return;
        }
        if ($refundView) {
            RefundAdmin::render($config, $refundId, (string) ($_POST['parent_source'] ?? $_GET['parent_source'] ?? ''),
                (int) ($_POST['parent_id'] ?? $_GET['parent_id'] ?? 0));
            self::end();
            return;
        }
        if ($testView) {
            TestAdmin::render($config, $testId);
            self::end();
            return;
        }
        echo Ui::heading('WHMCS fakture', 'Otvori plaćenu fakturu, proveri iznos i pripremi test račun. Plaćanje samo dodaje fakturu u pregled.');
        $lookup = '<form class="of-form of-inline-form" method="get" action="addonmodules.php"><input type="hidden" name="module" value="onetfiskal">'
            . Ui::field('WHMCS ID fakture', '<input type="number" name="invoiceid" min="1" value="' . ($id ?: '') . '" placeholder="Na primer: 48" required>')
            . '<button class="of-button of-button--primary">Otvori fakturu</button></form>';
        echo Ui::card('Pronađi fakturu', $lookup);
        if ($id > 0) { self::invoice($id, $config); }
        $rows = '';
        foreach (Capsule::table(Store::TABLE)->orderBy('id', 'desc')->limit(30)->get() as $r) {
            $response = json_decode((string) $r->response, true);
            $amount = is_array($response) && is_scalar($response['totalAmount'] ?? null) ? Ui::value($response['totalAmount']) : '—';
            $rows .= '<tr><td><a href="addonmodules.php?module=onetfiskal&amp;invoiceid=' . (int) $r->invoice_id . '">Faktura #'
                . (int) $r->invoice_id . '</a></td><td class="of-num">' . $amount . '</td><td>' . Ui::badge($r->state)
                . '</td><td>' . self::e($r->updated_at) . '</td></tr>';
        }
        $body = $rows === '' ? '<div class="of-empty"><strong>Još nema evidentiranih faktura</strong>Otvori plaćenu fakturu preko ID-a ili započni zaseban test.</div>'
            : '<div class="of-table-wrap"><table class="of-table"><thead><tr><th>Faktura</th><th class="of-num">Iznos RSD</th><th>Status</th><th>Ažurirano (UTC)</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        echo Ui::card('Poslednje evidentirane fakture', $body . Ui::button('addonmodules.php?module=onetfiskal&view=journal&source=invoice&state=all', 'Otvori ceo žurnal'));
        self::end();
    }

    private static function invoice(int $id, ?array $config): void
    {
        try {
            $saved = (new Store())->find($id);
            if ($saved && $saved['state'] === 'issued') {
                echo Ui::heading('Faktura #' . $id, 'Sačuvan test račun. Ponovno otvaranje i preuzimanje ne izdaju novi dokument.', Ui::badge('issued'));
                echo Ui::documentSteps('issued');
                self::receipt(Receipt::decode($saved['response']), 'invoice', $id);
                RefundAdmin::parentPanel('invoice', $id);
                CopyAdmin::parentPanel('invoice', $id);
                echo Ui::technical('Sačuvan odgovor V-PFR-a', $saved['response']);
                return;
            }
            $snapshot = Store::snapshot($id);
            $row = (new Store())->find($id);
            echo Ui::heading('WHMCS faktura #' . $id, 'Status u WHMCS-u: ' . $snapshot['invoice']['status'], $row ? Ui::badge($row['state']) : '');
            echo Ui::metrics([['label' => 'Ukupna vrednost fakture', 'value' => Ui::value($snapshot['invoice']['total']), 'unit' => $snapshot['currency'], 'tone' => 'primary']]);
            $gateways = array_column($snapshot['transactions'], 'gateway');
            echo '<p class="of-muted">Način plaćanja iz transakcija: <strong>' . self::e(implode(', ', $gateways)) . '</strong></p>';
            if ($row && $row['message']) { echo '<p>' . self::e($row['message']) . '</p>'; }
            if ($row && $row['state'] === 'issued') {
                self::receipt(json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR));
            } elseif ($row && in_array($row['state'], ['sending', 'unknown'], true)) {
                echo self::form('reconcile', $id, 'Proveri ishod postojećeg zahteva (GET)');
            } elseif (!$row || $row['state'] === 'review') {
                if (!$config) { return; }
                $payload = Builder::build($snapshot, $config, 'WHMCS admin ' . (int) $_SESSION['adminid']);
                echo Ui::documentSteps('review') . Ui::payload($payload);
                $extra = '<input type="hidden" name="snapshot_hash" value="' . Checks::reviewHash($snapshot, $config) . '">'
                    . Ui::confirm('confirmed', 'Potvrđujem: sandbox test izvršenog prometa, bez ranijih avansa; nije račun za stvarnu prodaju.');
                echo Ui::card('Potvrda izdavanja', self::form('issue', $id, 'Potvrdi i izdaj test račun', $extra));
            }
            if ($row && !empty($row['response'])) {
                echo Ui::technical('Sačuvan odgovor V-PFR-a', $row['response']);
            }
        } catch (\Throwable $e) {
            echo '<div class="alert alert-info">' . self::e($e->getMessage()) . '</div>';
        }
    }

    public static function receipt(array $response, string $source = '', int $id = 0, array $notes = []): void
    {
        echo '<details class="of-receipt-panel" open><summary>Dokument i QR provera</summary>' . Receipt::fragment($response, $notes);
        if (in_array($source, ['invoice', 'synthetic', 'refund', 'copy', 'advance'], true) && $id > 0) {
            echo Receipt::links($source, $id);
        }
        echo '</details>';
    }
}
