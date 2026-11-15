<?php
declare(strict_types=1);
namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

final class AdvanceAdmin
{
    public const LABELS = ['payment' => 'Avans–Prodaja', 'return' => 'Avans–Refundacija: povraćaj kupcu',
        'close' => 'Avans–Refundacija: interno zatvaranje', 'final' => 'Konačni Promet–Prodaja'];
    public static function url(int $id = 0, int $group = 0): string
    {
        return 'addonmodules.php?module=onetfiskal&view=advances&advanceid=' . $id . '&groupid=' . $group;
    }
    private static function extra(int $id = 0, int $group = 0): string
    {
        return '<input type="hidden" name="view" value="advances"><input type="hidden" name="advanceid" value="' . $id
            . '"><input type="hidden" name="groupid" value="' . $group . '">';
    }
    private static function methods(string $name, int $selected = 4): string
    {
        return '<select name="' . $name . '"><option value="4"' . ($selected === 4 ? ' selected' : '')
            . '>Prenos na račun (4)</option><option value="2"' . ($selected === 2 ? ' selected' : '') . '>Kartica (2)</option></select>';
    }
    public static function handle(string $action, array $config, int $id): int
    {
        $store = new AdvanceStore($config); $admin = (int) $_SESSION['adminid'];
        if ($action === 'advance_new') { return (int) $store->create($_POST, $admin)['invoice_id']; }
        if ($action === 'advance_preview') {
            return (int) $store->draft((int) ($_POST['groupid'] ?? 0), (string) ($_POST['kind'] ?? ''),
                (string) ($_POST['draft_key'] ?? ''), $_POST, $admin)['invoice_id'];
        }
        $row = $store->find($id);
        if (!$row) { throw new RuntimeException('Avansni dokument ne postoji.'); }
        if ($action === 'advance_money') {
            if (($_POST['confirmed_money'] ?? '') !== 'yes') { throw new RuntimeException('Potvrdi izmišljenu test evidenciju.'); }
            AdvanceStore::recordMoney($id, (string) ($_POST['money_reference'] ?? ''), $admin);
            echo '<p>Test evidencija povraćaja je sačuvana. Banka nije pozvana.</p>'; return $id;
        }
        $service = new Service($store, new Vsdc($config), $config);
        if ($action === 'advance_issue') {
            if (($_POST['confirmed'] ?? '') !== 'yes' || !hash_equals(hash('sha256', $row['payload']), (string) ($_POST['advance_hash'] ?? ''))) {
                throw new RuntimeException('Potvrdi pregled konkretnog sandbox dokumenta.');
            }
            $meta = json_decode($row['meta'], true, 512, JSON_THROW_ON_ERROR);
            if ($row['kind'] === 'final' && $meta['topup_cents'] > 0 && ($_POST['confirmed_topup'] ?? '') !== 'yes') {
                throw new RuntimeException('Potvrdi izmišljenu test doplatu pre konačnog računa.');
            }
            $result = $service->submit($id, json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR), $admin);
        } elseif ($action === 'advance_reconcile') { $result = $service->reconcile($id); }
        else { throw new RuntimeException('Nepoznata avansna akcija.'); }
        echo '<p>' . Admin::e($result['message']) . '</p>'; return $id;
    }
    public static function render(?array $config, int $id, int $group): void
    {
        echo '<h3>Avansi — zasebni sandbox testovi</h3><div class="alert alert-warning">Jedna izmišljena avansna uplata po test porudžbini, RSD, jedna sandbox VAT stopa, kartica/prenos. '
            . 'Nema WHMCS uplata, bankarskih naloga, aktivacije usluga ili slanja mejla. Interno zatvaranje nije povraćaj novca kupcu. '
            . 'Kada ovaj tok koristiš za stvarne usluge određuje knjigovođa; produkcija je blokirana.</div>';
        try {
            if ($id > 0) { self::detail($config, $id); }
            elseif ($group > 0) { self::group($config, $group); }
            elseif ($config) {
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))->format('Y-m-d\TH:i');
                $extra = self::extra() . '<input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'
                    . '<p><label>Namena <select name="scenario"><option value="hosting">Test hosting</option><option value="domain">Test domen</option></select></label></p>'
                    . '<p><label>Primljeni test avans, bruto RSD <input name="amount" value="1190.00" required></label></p>'
                    . '<p><label>Datum i vreme test uplate (Beograd) <input type="datetime-local" name="received" value="' . $now . '" required></label></p>'
                    . '<p><label>Izmišljeni ID kupca <input name="buyer_id" value="20:000000000" pattern="20:[0-9]{9}" required></label></p>'
                    . '<p>Način prijema ' . self::methods('method') . '</p>';
                echo Admin::form('advance_new', 0, 'Sačuvaj test porudžbinu i pregled avansa', $extra);
            }
        } catch (RuntimeException $e) { echo '<div class="alert alert-danger">' . Admin::e($e->getMessage()) . '</div>'; }
        echo '<p><a href="' . Admin::e(self::url()) . '">Nova test porudžbina</a> · '
            . '<a href="addonmodules.php?module=onetfiskal&amp;view=journal&amp;source=advance&amp;state=all">Svi avansni dokumenti u žurnalu</a></p>';
    }
    private static function detail(?array $config, int $id): void
    {
        $row = (new Store('', 'advance'))->find($id);
        if (!$row) { throw new RuntimeException('Dokument ne postoji.'); }
        $meta = json_decode($row['meta'], true, 512, JSON_THROW_ON_ERROR);
        echo '<h4>' . Admin::e(self::LABELS[$row['kind']]) . ' #' . $id . '</h4><p>Stanje: ' . Admin::e($row['state'])
            . ' · RequestId: ' . Admin::e($row['request_id']) . '</p><p><a href="' . Admin::e(self::url(0, (int) $row['group_id'])) . '">Test porudžbina #' . (int) $row['group_id'] . ' i nastavak postupka</a></p>';
        if (isset($meta['reason'])) { echo '<p>Razlog: ' . Admin::e($meta['reason']) . '</p>'; }
        if ($row['kind'] === 'close') { echo '<p><strong>INTERNI DOKUMENT — ne dostavljati kupcu. Nije nalog za vraćanje novca.</strong></p>'; }
        if ($row['state'] === 'issued') {
            Admin::receipt(Receipt::decode($row['response']), 'advance', $id, $meta['notes'] ?? []);
            if ($row['kind'] === 'final') { RefundAdmin::parentPanel('advance', $id); }
            CopyAdmin::parentPanel('advance', $id);
            if ($row['kind'] === 'return') {
                if ($row['money_recorded_at']) { echo '<p>Test povraćaj zabeležen: ' . Admin::e($row['money_reference']) . '</p>'; }
                else { echo Admin::form('advance_money', 0, 'Sačuvaj odvojenu test evidenciju isplate', self::extra($id)
                    . '<p><label>Test referenca <input name="money_reference" maxlength="120" required></label></p>'
                    . '<label><input type="checkbox" name="confirmed_money" value="yes" required> Samo izmišljena evidencija; banka nije pozvana.</label>'); }
            }
        } elseif (in_array($row['state'], ['unknown', 'sending'], true)) {
            echo Admin::form('advance_reconcile', 0, 'Proveri ishod avansnog dokumenta (GET)', self::extra($id));
        } elseif ($row['state'] === 'review' && $config) {
            $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            echo '<pre>' . Admin::e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
            foreach ($meta['notes'] ?? [] as $note) { echo '<p>' . Admin::e($note) . '</p>'; }
            $extra = self::extra($id) . '<input type="hidden" name="advance_hash" value="' . hash('sha256', $row['payload']) . '">'
                . '<p><label><input type="checkbox" name="confirmed" value="yes" required> Potvrđujem izmišljeni sandbox postupak: ' . Admin::e(self::LABELS[$row['kind']]) . '.</label></p>';
            if ($row['kind'] === 'final' && $meta['topup_cents'] > 0) {
                $extra .= '<p><label><input type="checkbox" name="confirmed_topup" value="yes" required> Potvrđujem izmišljenu doplatu '
                    . Advance::money($meta['topup_cents']) . ' RSD.</label></p>';
            }
            echo Admin::form('advance_issue', 0, 'Izdaj ovaj test dokument', $extra);
        }
        if ($row['message']) { echo '<p>' . Admin::e($row['message']) . '</p>'; }
        if ($row['response']) { echo '<details><summary>Sačuvan originalni odgovor V-PFR-a</summary><pre>' . Admin::e($row['response']) . '</pre></details>'; }
    }
    private static function group(?array $config, int $id): void
    {
        $group = Capsule::table(Store::ADVANCE_GROUPS)->where('id', $id)->first();
        if (!$group) { throw new RuntimeException('Test porudžbina ne postoji.'); }
        $root = json_decode($group->root, true, 512, JSON_THROW_ON_ERROR); $docs = Advance::documents($id); $ledger = Advance::ledger($docs);
        echo '<h4>Test porudžbina #' . $id . '</h4><p>' . Admin::e($root['description']) . '</p><p>Primljeno: '
            . Advance::money($root['amount_cents']) . ' · Povraćaji kupcu (sa rezervacijama nepoznatog ishoda): ' . Advance::money($ledger['returned'])
            . ' · Preostali otvoreni avans: ' . Advance::money($ledger['remaining']) . ' RSD</p>';
        foreach ($docs as $row) {
            echo '<p><a href="' . Admin::e(self::url((int) $row['invoice_id'])) . '">' . Admin::e(self::LABELS[$row['kind']])
                . ' #' . (int) $row['invoice_id'] . '</a> · ' . Admin::e($row['state']) . ' · ' . Advance::money((int) $row['amount_cents']) . ' RSD</p>';
        }
        if (!$config) { return; }
        $base = self::extra(0, $id);
        if ($ledger['close'] && $ledger['close']['state'] === 'issued' && !$ledger['final']) {
            echo '<p><strong>Interno zatvaranje je potvrđeno. Još treba izdati konačni račun; ne ponavljaj zatvaranje.</strong></p>';
            echo Admin::form('advance_preview', 0, 'Pripremi konačni Promet–Prodaja', $base
                . '<input type="hidden" name="kind" value="final"><input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">');
        } elseif ($ledger['remaining'] > 0 && !$ledger['close'] && !$ledger['final']) {
            echo '<h4>Povraćaj novca kupcu</h4>';
            echo Admin::form('advance_preview', 0, 'Pregled Avans–Refundacije kupcu', $base
                . '<input type="hidden" name="kind" value="return"><input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'
                . '<p><label>Bruto povraćaj RSD <input name="amount" value="' . Advance::money($ledger['remaining']) . '" required></label></p>'
                . '<p>Način povraćaja ' . self::methods('method', $root['method']) . '</p><p><label>Razlog <input name="reason" maxlength="300" required></label></p>');
            echo '<h4>Izvršenje usluge i zatvaranje avansa</h4><p>Dva odvojena potvrđena dokumenta: interni AR, pa konačni PP. Nema bankarskog povraćaja.</p>';
            echo Admin::form('advance_preview', 0, 'Pregled internog zatvaranja avansa', $base
                . '<input type="hidden" name="kind" value="close"><input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'
                . '<p><label>Konačna vrednost izvršene test usluge, bruto RSD <input name="final_amount" value="' . Advance::money($ledger['remaining']) . '" required></label></p>'
                . '<p>Način eventualne doplate ' . self::methods('topup_method', $root['method']) . '</p>');
        }
    }
}
