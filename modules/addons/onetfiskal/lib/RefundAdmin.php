<?php
declare(strict_types=1);
namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

final class RefundAdmin
{
    public static function url(int $id = 0, string $source = '', int $parent = 0): string
    {
        return 'addonmodules.php?' . http_build_query(['module' => 'onetfiskal', 'view' => 'refunds',
            'refundid' => $id, 'parent_source' => $source, 'parent_id' => $parent]);
    }
    private static function extra(int $id, string $source = '', int $parent = 0): string
    {
        return '<input type="hidden" name="view" value="refunds"><input type="hidden" name="refundid" value="' . $id
            . '"><input type="hidden" name="parent_source" value="' . Admin::e($source)
            . '"><input type="hidden" name="parent_id" value="' . $parent . '">';
    }
    public static function handle(string $action, array $config, int $id): int
    {
        $store = new RefundStore($config); $adminId = (int) $_SESSION['adminid'];
        if ($action === 'refund_preview') {
            $row = $store->draft((string) ($_POST['parent_source'] ?? ''), (int) ($_POST['parent_id'] ?? 0), $_POST, $adminId);
            return (int) $row['invoice_id'];
        }
        $row = $store->find($id);
        if (!$row) { throw new RuntimeException('Refundacija ne postoji.'); }
        if ($action === 'refund_money') {
            if (($_POST['confirmed_money'] ?? '') !== 'yes') { throw new RuntimeException('Potvrdi da beležiš samo test evidenciju povraćaja.'); }
            RefundStore::recordMoney($id, (string) ($_POST['money_reference'] ?? ''), $adminId);
            echo '<div class="alert alert-info">Test evidencija povraćaja je sačuvana. Banka nije pozvana.</div>';
            return $id;
        }
        $service = new Service($store, new Vsdc($config), $config);
        if ($action === 'refund_issue') {
            if (($_POST['confirmed'] ?? '') !== 'yes' || !hash_equals(hash('sha256', $row['payload']), (string) ($_POST['refund_hash'] ?? ''))) {
                throw new RuntimeException('Potvrdi pregled test refundacije.');
            }
            $result = $service->submit($id, json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR), $adminId);
        } elseif ($action === 'refund_reconcile') {
            $result = $service->reconcile($id);
        } else { throw new RuntimeException('Nepoznata akcija refundacije.'); }
        echo '<div class="alert alert-info">' . Admin::e($result['message']) . '</div>';
        return $id;
    }
    public static function parentPanel(string $source, int $id): void
    {
        $rows = Capsule::table(Store::REFUNDS)->where('parent_source', $source)->where('parent_id', $id)->orderBy('id')->get();
        echo '<details class="of-technical"><summary>Povraćaji ovog računa · ' . $rows->count() . '</summary><div class="of-details-body">'
            . '<p>Za vraćanje dela ili celog iznosa pripremi zaseban dokument povraćaja.</p>'
            . Ui::button(self::url(0, $source, $id), 'Pripremi povraćaj kupcu →', 'refund');
        foreach ($rows as $r) {
            echo '<p style="margin-top:14px"><a href="' . Admin::e(self::url((int) $r->invoice_id)) . '">Povraćaj #' . (int) $r->invoice_id
                . '</a> ' . Ui::badge($r->state) . '<small class="of-muted"> · Isplata: ' . ($r->money_recorded_at ? 'test evidencija sačuvana' : 'bez odvojene test evidencije') . '</small></p>';
        }
        if ($rows->isEmpty()) { echo '<p class="of-muted" style="margin-top:12px">Nema pripremljenih povraćaja za ovaj račun.</p>'; }
        echo '</div></details>';
    }
    public static function render(?array $config, int $id, string $source, int $parentId): void
    {
        try {
            if ($id > 0) { self::detail($config, $id); }
            elseif ($parentId > 0) { self::previewForm($config, $source, $parentId); }
            else {
                echo Ui::heading('Povraćaji kupcima', 'Pripremi pun ili delimičan povraćaj sa referencom na originalni račun.');
                echo Ui::steps([['label' => 'Izaberi original', 'hint' => 'Otvori izdat račun', 'state' => 'current'],
                    ['label' => 'Odredi povraćaj', 'hint' => 'Stavke, količina i ID kupca'], ['label' => 'Potvrdi dokument', 'hint' => 'Pregled pre slanja']]);
                echo Ui::card('Počni od originalnog računa', '<p>U žurnalu otvori potvrđenu prodaju i izaberi „Pripremi povraćaj kupcu”.</p>'
                    . Ui::button('addonmodules.php?module=onetfiskal&view=journal&state=issued', 'Izaberi račun iz žurnala →', 'primary'));
            }
        } catch (RuntimeException $e) { echo Ui::note('Povraćaj nije pripremljen', $e->getMessage(), 'danger'); }
        echo '<div class="of-actions">' . Ui::button('addonmodules.php?module=onetfiskal&view=journal&source=refund&state=all', 'Sve refundacije u žurnalu') . '</div>';
    }
    private static function previewForm(?array $config, string $source, int $parentId): void
    {
        $parent = Refund::parent($source, $parentId); $used = Refund::used($source, $parentId);
        $total = array_sum(array_map(static fn($i) => Money::cents($i['totalAmount']), $parent['payload']['items']));
        $taken = array_sum(array_column($used, 'cents')); $remainingTotal = max(0, $total - $taken);
        echo Ui::heading('Pripremi povraćaj kupcu', 'Izaberi stavke i količine. Pre slanja dobijaš još jedan pregled sa tačnim iznosom.', '', 'Refundacija prometa');
        echo Ui::steps([['label' => 'Original izabran', 'hint' => $parent['response']['invoiceNumber'], 'state' => 'done'],
            ['label' => 'Stavke i kupac', 'hint' => 'Odredi koliko vraćaš', 'state' => 'current'], ['label' => 'Provera i izdavanje', 'hint' => 'Dokument još nije poslat']]);
        echo Ui::metrics([['label' => 'Originalni račun', 'value' => Ui::money($total), 'unit' => 'RSD'],
            ['label' => 'Refundirano / rezervisano', 'value' => Ui::money($taken), 'unit' => 'RSD', 'hint' => 'Uključuje zahteve sa nepoznatim ishodom', 'tone' => 'refund'],
            ['label' => 'Preostali iznos', 'value' => Ui::money($remainingTotal), 'unit' => 'RSD', 'tone' => 'primary']]);
        echo Ui::note('Fiskalni dokument i bankarska isplata su odvojeni', 'Ne vraća novac preko banke. Ovaj postupak ne menja WHMCS uplate, ne otkazuje uslugu i ne šalje mejl.', 'refund');
        if (!$config) { echo Ui::note('Potrebna konfiguracija', 'Podesi privatnu konfiguraciju pre pripreme novog dokumenta.'); return; }
        if ($remainingTotal === 0) { echo Ui::note('Nema slobodnog iznosa za novi povraćaj', 'Original je u celosti refundiran ili su sredstva rezervisana postojećim zahtevima. Proveri povezane dokumente.'); self::parentPanel($source, $parentId); return; }
        $extra = self::extra(0, $source, $parentId) . '<input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'
            . '<p class="of-muted">Količina <strong>1</strong> = cela stavka · <strong>0.500</strong> = polovina · <strong>0</strong> = ne vraća se. Cena ostaje originalna.</p>'
            . '<div class="of-table-wrap"><table class="of-table"><thead><tr><th>Stavka</th><th class="of-num">Originalna cena RSD</th><th class="of-num">Preostala količina</th><th class="of-num">Vraća se</th></tr></thead><tbody>';
        foreach ($parent['payload']['items'] as $i => $item) {
            $remaining = ($used[$i]['cents'] ?? 0) >= Money::cents($item['totalAmount']) ? 0 : max(0, 1000 - ($used[$i]['quantity'] ?? 0)) / 1000;
            $extra .= '<tr><td>' . Admin::e($item['name']) . '</td><td class="of-num">' . Ui::value($item['unitPrice']) . '</td><td class="of-num">'
                . number_format($remaining, 3, ',', '.') . '</td><td class="of-num"><input aria-label="Količina za povraćaj: ' . Admin::e($item['name'])
                . '" name="quantities[' . $i . ']" type="number" min="0" max="' . $remaining . '" step="0.001" value="' . $remaining . '" required></td></tr>';
        }
        $extra .= '</tbody></table></div><div class="of-form-grid">'
            . Ui::field('Test identifikacija kupca', '<input name="buyer_id" placeholder="20:000000000" pattern="20:[0-9]{9}" required>', '20: i devet cifara izmišljenog broja lične karte.')
            . Ui::field('Način povraćaja', '<select name="payment_type"><option value="4">Prenos na račun</option><option value="2">Platna kartica</option></select>') . '</div>'
            . Ui::field('Razlog povraćaja', '<textarea name="reason" maxlength="300" required placeholder="Na primer: sandbox test delimičnog povraćaja"></textarea>');
        echo Ui::card('Stavke za povraćaj', Admin::form('refund_preview', 0, 'Pregledaj iznos povraćaja →', $extra, 'refund'), 'Naredni korak je pregled i potvrda tačnog iznosa.', 'refund');
    }
    private static function detail(?array $config, int $id): void
    {
        $row = (new Store('', 'refund'))->find($id);
        if (!$row) { throw new RuntimeException('Refundacija ne postoji.'); }
        $parent = Refund::parent($row['parent_source'], (int) $row['parent_id']);
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        echo Ui::heading('Povraćaj kupcu #' . $id, 'Promet–Refundacija · zaseban dokument vezan za originalnu prodaju.', Ui::badge($row['state']));
        echo Ui::documentSteps($row['state']);
        echo '<p>Original: <a class="of-reference" href="' . Admin::e(CopyAdmin::originalUrl($row['parent_source'], (int) $row['parent_id'])) . '">'
            . Admin::e($parent['response']['invoiceNumber']) . '</a></p><p><strong>Razlog:</strong> ' . Admin::e($row['reason']) . '</p>';
        $extra = self::extra($id);
        if ($row['state'] === 'review' && $config) {
            echo Ui::note('Proveri iznos pre potvrde', 'Izdaješ test dokument povraćaja. Ne vraća novac preko banke; isplata je odvojena.', 'refund');
            echo Ui::card('Pregled povraćaja', Ui::payload($payload)
                . Admin::form('refund_issue', 0, 'Potvrdi i izdaj test refundaciju', $extra
                    . '<input type="hidden" name="refund_hash" value="' . hash('sha256', $row['payload']) . '">'
                    . Ui::confirm('confirmed', 'Proverio/la sam iznos i kupca. Ovo je test povraćaj kupcu, a ne poništavanje greškom izdatog računa.')), '', 'refund');
        } elseif (in_array($row['state'], ['sending', 'unknown'], true)) {
            echo Ui::card('Proveri ishod postojećeg zahteva', Ui::note('Ne izdavati zamenski dokument', 'Povraćaj možda već ima izdat dokument. Koristi proveru postojećeg zahteva.', 'danger')
                . Admin::form('refund_reconcile', 0, 'Proveri postojeći zahtev', $extra));
        } elseif ($row['state'] === 'issued') {
            echo Ui::note('Dokument povraćaja je izdat', 'Fiskalni korak je završen. Evidencija bankarske isplate je zasebna; njen izostanak nije greška izdavanja.', 'success');
            echo Ui::payload($payload);
            $body = '<p>Novac se vraća van modula. Ovde možeš sačuvati samo izmišljenu test referencu.</p>';
            if ($row['money_recorded_at']) {
                $body .= Ui::note('Test evidencija je sačuvana', $row['money_reference'] . ' · ' . $row['money_recorded_at'] . ' UTC', 'success');
            } else {
                $body .= Admin::form('refund_money', 0, 'Sačuvaj test evidenciju isplate', $extra
                    . Ui::field('Referenca test isplate', '<input name="money_reference" maxlength="120" required placeholder="TEST-POVRACAJ-01">')
                    . Ui::confirm('confirmed_money', 'Beležim izmišljenu test isplatu. Ne šalje nalog banci.'));
            }
            echo Ui::card('Odvojena evidencija isplate', $body, 'Ovaj modul ne izvršava bankarski povraćaj.');
            Admin::receipt(Receipt::decode($row['response']), 'refund', $id);
            CopyAdmin::parentPanel('refund', $id);
        } elseif ($row['state'] === 'rejected') { echo Ui::note('Zahtev je odbijen', (string) $row['message'], 'danger'); }
        echo '<div class="of-actions">' . Ui::button(self::url(0, $row['parent_source'], (int) $row['parent_id']), '← Original i preostali saldo') . '</div>';
        echo Ui::metadata($row);
        if ($row['response']) { echo Ui::technical('Originalni odgovor V-PFR-a', $row['response']); }
    }
}
