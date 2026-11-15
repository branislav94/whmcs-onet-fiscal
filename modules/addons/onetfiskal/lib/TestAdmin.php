<?php
declare(strict_types=1);

namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

final class TestAdmin
{
    public static function handle(string $action, array $config, int $testId): int
    {
        $store = new Store(hash_file('sha256', $config['pfx_path']), 'synthetic');
        $adminId = (int) $_SESSION['adminid'];
        if ($action === 'synthetic_preview') {
            $snapshot = Synthetic::snapshot($_POST, $config);
            $row = $store->createSynthetic($snapshot, (string) ($_POST['draft_key'] ?? ''), $adminId);
            return (int) $row['invoice_id'];
        }
        $row = $store->find($testId);
        if (!$row) { throw new RuntimeException('Sandbox test ne postoji.'); }
        $service = new Service($store, new Vsdc($config), $config);
        if ($action === 'synthetic_issue') {
            if (($_POST['confirmed'] ?? '') !== 'yes') {
                throw new RuntimeException('Potvrdi izdavanje izmišljenog računa u sandboxu.');
            }
            $snapshot = json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!hash_equals(Checks::reviewHash($snapshot, $config), (string) ($_POST['review_hash'] ?? ''))) {
                throw new RuntimeException('Konfiguracija testa se promenila. Pregledaj novi zahtev pre slanja.');
            }
            $result = $service->issue($testId, $snapshot, $adminId);
        } elseif ($action === 'synthetic_reconcile') {
            $result = $service->reconcile($testId);
        } else {
            throw new RuntimeException('Nepoznata sandbox akcija.');
        }
        echo '<div class="alert alert-info">' . Admin::e($result['message']) . '</div>';
        return $testId;
    }

    private static function extra(int $id): string
    {
        return '<input type="hidden" name="view" value="tests"><input type="hidden" name="testid" value="' . $id . '">';
    }

    public static function render(?array $config, int $testId): void
    {
        echo Ui::heading('Zasebna test prodaja', 'Izmišljeni podaci, odvojeni od WHMCS faktura i uplata. Bez aktivacije usluga i slanja mejla.');
        if ($config) {
        echo '<p>Test stopa: <strong>' . Admin::e($config['vat_rate']) . '%</strong> · Oznaka: <strong>'
            . Admin::e($config['tax_label']) . '</strong> · Valuta: RSD</p>';
        }
        if ($testId === 0) {
            if (!$config) { return; }
            $gateways = '';
            foreach ($config['payment_map'] as $name => $type) {
                if (!is_int($type) || !in_array($type, [0, 1, 2, 3, 4, 5, 6], true)
                    || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', (string) $name)) { continue; }
                $gateways .= '<option value="' . Admin::e($name) . '">' . Admin::e($name) . ' → '
                    . Admin::e(PaymentLabels::name($type)) . ' (' . $type . ')</option>';
            }
            if ($gateways === '') {
                echo '<div class="alert alert-info">Prvo dodaj ispravno celobrojno mapiranje gateway-a u privatni payment_map.</div>';
            } else {
                echo Ui::steps([['label' => 'Unesi test podatke', 'state' => 'current'], ['label' => 'Proveri i potvrdi'], ['label' => 'Test račun i QR']]);
                $extra = self::extra(0) . '<input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'
                    . '<div class="of-form-grid">'
                    . Ui::field('Scenario', '<select name="scenario"><option value="hosting">Test hosting</option><option value="domain">Test domen</option></select>')
                    . Ui::field('Osnovica bez poreza · RSD', '<input name="net_amount" type="text" inputmode="decimal" value="1000.00" pattern="[0-9]+([.][0-9]{1,2})?" required>', 'Unos sa tačkom: 1000.00. Porez se dodaje prema sandbox stopi.')
                    . Ui::field('Test način plaćanja', '<select name="gateway">' . $gateways . '</select>') . '</div>';
                echo Ui::card('Podaci nove test prodaje', Admin::form('synthetic_preview', 0, 'Nastavi na pregled', $extra));
            }
        } else {
            try {
                $row = (new Store('', 'synthetic'))->find($testId);
                if (!$row) { throw new RuntimeException('Sandbox test ne postoji.'); }
                echo Ui::heading('Test prodaja #' . $testId, 'Zasebna sandbox evidencija', Ui::badge($row['state']));
                echo Ui::documentSteps($row['state']);
                if ($row['message']) { echo '<p class="of-muted">' . Admin::e($row['message']) . '</p>'; }
                if ($row['state'] === 'issued') {
                    Admin::receipt(Receipt::decode($row['response']), 'synthetic', $testId);
                    RefundAdmin::parentPanel('synthetic', $testId);
                    CopyAdmin::parentPanel('synthetic', $testId);
                } elseif (in_array($row['state'], ['sending', 'unknown'], true)) {
                    echo Admin::form('synthetic_reconcile', 0, 'Proveri ishod postojećeg testa', self::extra($testId));
                } elseif ($row['state'] === 'review') {
                    if (!$config) { throw new RuntimeException('Prvo podesi konfiguraciju za pregled nacrta.'); }
                    $s = json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
                    $payload = Builder::build($s, $config, 'WHMCS admin ' . (int) $_SESSION['adminid']);
                    echo Ui::card('Proveri iznos i potvrdi izdavanje', Ui::payload($payload)
                        . Admin::form('synthetic_issue', 0, 'Potvrdi i izdaj test račun', self::extra($testId)
                        . '<input type="hidden" name="review_hash" value="' . Checks::reviewHash($s, $config) . '">'
                        . Ui::confirm('confirmed', 'Potvrđujem izdavanje izmišljenog sandbox računa; nije za stvarni promet.')));

                }
                if (!empty($row['response'])) {
                    echo Ui::technical('Sačuvan odgovor V-PFR-a', $row['response']);
                }
                echo Ui::metadata($row);
            } catch (\Throwable $e) {
                echo '<div class="alert alert-info">' . Admin::e($e->getMessage()) . '</div>';
            }
            echo '<p><a class="btn btn-default" href="addonmodules.php?module=onetfiskal&amp;view=tests">Novi zaseban test</a></p>';
        }
        echo '<h3>Evidencija zasebnih testova</h3><div class="of-table-wrap"><table class="of-table"><thead><tr><th>Test</th><th>Stanje</th><th>Ažurirano (UTC)</th></tr></thead><tbody>';
        foreach (Capsule::table(Store::TESTS)->orderBy('id', 'desc')->limit(30)->get() as $r) {
            echo '<tr><td><a href="addonmodules.php?module=onetfiskal&amp;view=tests&amp;testid=' . (int) $r->invoice_id . '">#'
                . (int) $r->invoice_id . '</a></td><td>' . Ui::badge($r->state) . '</td><td>' . Admin::e($r->updated_at) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
