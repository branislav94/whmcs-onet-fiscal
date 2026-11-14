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
        echo '<h4>Povezane refundacije</h4><p><a class="btn btn-default" href="' . Admin::e(self::url(0, $source, $id))
            . '">Pripremi sandbox refundaciju</a></p>';
        $rows = Capsule::table(Store::REFUNDS)->where('parent_source', $source)->where('parent_id', $id)->orderBy('id')->get();
        if ($rows->isEmpty()) { echo '<p>Nema evidentiranih refundacija.</p>'; }
        foreach ($rows as $r) {
            echo '<p><a href="' . Admin::e(self::url((int) $r->invoice_id)) . '">Refundacija #' . (int) $r->invoice_id
                . '</a> · ' . Admin::e($r->state) . ' · Povraćaj novca: ' . ($r->money_recorded_at ? 'test evidencija sačuvana' : 'nije evidentiran') . '</p>';
        }
    }
    public static function render(?array $config, int $id, string $source, int $parentId): void
    {
        echo '<h3>Refundacije — sandbox</h3><div class="alert alert-info">Ovo izdaje samo test fiskalni dokument. '
            . 'Ne vraća novac preko banke, ne menja WHMCS fakture/uplate, ne otkazuje hosting ili domen i ne šalje mejl. '
            . 'Podržan je bezgotovinski povraćaj domaćem fizičkom licu, bez avansa. Koristi izmišljene podatke.</div>';
        try {
            if ($id > 0) { self::detail($config, $id); }
            elseif ($parentId > 0) { self::previewForm($config, $source, $parentId); }
            else { echo '<p>Otvori potvrđen originalni račun iz žurnala i izaberi „Pripremi sandbox refundaciju”.</p>'; }
        } catch (RuntimeException $e) { echo '<div class="alert alert-danger">' . Admin::e($e->getMessage()) . '</div>'; }
        echo '<p><a href="addonmodules.php?module=onetfiskal&amp;view=journal&amp;source=refund&amp;state=all">Sve refundacije u žurnalu</a></p>';
    }
    private static function previewForm(?array $config, string $source, int $parentId): void
    {
        $parent = Refund::parent($source, $parentId); $used = Refund::used($source, $parentId);
        echo '<p>Original: <strong>' . Admin::e($parent['response']['invoiceNumber']) . '</strong></p>';
        if (!$config) { echo '<p>Podesi privatnu konfiguraciju za pripremu refundacije.</p>'; return; }
        $extra = self::extra(0, $source, $parentId) . '<input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'
            . '<p>Količina 1 znači celu originalnu stavku; 0 je izostavlja; 0.500 vraća polovinu. '
            . 'Cena i poreska oznaka ostaju originalne. Iznos se obračunava prema količini, ne unosi se proizvoljna nova cena.</p>'
            . '<table class="table"><thead><tr><th>Stavka</th><th>Originalna cena sa porezom</th><th>Preostalo</th><th>Količina za povraćaj</th></tr></thead><tbody>';
        foreach ($parent['payload']['items'] as $i => $item) {
            $remaining = ($used[$i]['cents'] ?? 0) >= Money::cents($item['totalAmount'])
                ? 0 : max(0, 1000 - ($used[$i]['quantity'] ?? 0)) / 1000;
            $extra .= '<tr><td>' . Admin::e($item['name']) . '</td><td>' . Admin::e($item['unitPrice']) . ' RSD</td><td>' . $remaining
                . '</td><td><input name="quantities[' . $i . ']" type="number" min="0" max="' . $remaining . '" step="0.001" value="'
                . $remaining . '" required></td></tr>';
        }
        $extra .= '</tbody></table><p><label>Test ID kupca (20:broj lične karte) <input name="buyer_id" placeholder="20:000000000" pattern="20:[0-9]{9}" required></label></p>'
            . '<p><label>Način povraćaja <select name="payment_type"><option value="4">Prenos na račun (4)</option><option value="2">Platna kartica (2)</option></select></label></p>'
            . '<p><label>Razlog <input name="reason" maxlength="300" required></label></p>';
        echo Admin::form('refund_preview', 0, 'Sačuvaj nacrt i prikaži iznos', $extra);
        self::parentPanel($source, $parentId);
    }
    private static function detail(?array $config, int $id): void
    {
        $row = (new Store('', 'refund'))->find($id);
        if (!$row) { throw new RuntimeException('Refundacija ne postoji.'); }
        $parent = Refund::parent($row['parent_source'], (int) $row['parent_id']);
        $originalUrl = $row['parent_source'] === 'invoice' ? 'invoiceid=' : 'view=tests&amp;testid=';
        echo '<h4>Refundacija #' . $id . '</h4><p>Stanje: ' . Admin::e($row['state']) . ' · RequestId: ' . Admin::e($row['request_id'])
            . '</p><p>Original: <a href="addonmodules.php?module=onetfiskal&amp;' . $originalUrl . (int) $row['parent_id'] . '">'
            . Admin::e($parent['response']['invoiceNumber']) . '</a></p><p>Razlog: ' . Admin::e($row['reason']) . '</p>';
        $extra = self::extra($id);
        if ($row['state'] === 'issued') {
            Admin::receipt(Receipt::decode($row['response']), 'refund', $id);
            echo '<h4>Odvojena test evidencija povraćaja novca</h4>';
            if ($row['money_recorded_at']) {
                echo '<p>Test evidencija sačuvana: ' . Admin::e($row['money_reference']) . ' · ' . Admin::e($row['money_recorded_at']) . ' UTC</p>';
            } else {
                echo '<p>Povraćaj nije evidentiran. Sam fiskalni dokument ne potvrđuje bankarsku transakciju.</p>';
                echo Admin::form('refund_money', 0, 'Sačuvaj test evidenciju povraćaja', $extra
                    . '<p><label>Test referenca <input name="money_reference" maxlength="120" required></label></p>'
                    . '<p><label><input type="checkbox" name="confirmed_money" value="yes" required> Ovo je izmišljena test evidencija; ne šalje nalog banci.</label></p>');
            }
        } elseif (in_array($row['state'], ['sending', 'unknown'], true)) {
            echo Admin::form('refund_reconcile', 0, 'Proveri ishod refundacije (GET)', $extra);
        } elseif ($row['state'] === 'review' && $config) {
            $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            echo '<p>Ukupno za povraćaj: <strong>' . Admin::e($payload['payment'][0]['amount']) . ' RSD</strong></p><pre>'
                . Admin::e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
            echo Admin::form('refund_issue', 0, 'Izdaj test Promet–Refundacija', $extra
                . '<input type="hidden" name="refund_hash" value="' . hash('sha256', $row['payload']) . '">'
                . '<p><label><input type="checkbox" name="confirmed" value="yes" required> Potvrđujem test povraćaj kupcu, a ne poništavanje greškom izdatog računa.</label></p>');
        }
        if ($row['message']) { echo '<p>' . Admin::e($row['message']) . '</p>'; }
        echo '<p><a href="' . Admin::e(self::url(0, $row['parent_source'], (int) $row['parent_id'])) . '">Novi pregled preostalog iznosa</a></p>';
    }
}
