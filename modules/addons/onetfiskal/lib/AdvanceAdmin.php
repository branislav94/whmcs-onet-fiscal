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
            . '>Prenos na račun</option><option value="2"' . ($selected === 2 ? ' selected' : '') . '>Platna kartica</option></select>';
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
        try {
            if ($id > 0) { self::detail($config, $id); }
            elseif ($group > 0) { self::group($config, $group); }
            else {
                echo Ui::heading('Avansi', 'Evidentiraj test uplatu unapred, zatvori je konačnim računom ili pripremi povraćaj kupcu.');
                if ($config) { self::newOrder(); }
            }
        } catch (RuntimeException $e) { echo Ui::note('Postupak nije nastavljen', $e->getMessage(), 'danger'); }
        echo '<div class="of-actions">' . Ui::button(self::url(), 'Nova test porudžbina')
            . Ui::button('addonmodules.php?module=onetfiskal&view=journal&source=advance&state=all', 'Svi avansni dokumenti') . '</div>';
    }
    private static function newOrder(): void
    {
        echo Ui::steps([['label' => 'Primljeni avans', 'hint' => 'Unesi izmišljenu test uplatu', 'state' => 'current'],
            ['label' => 'Interno zatvaranje', 'hint' => 'Kada je usluga izvršena'], ['label' => 'Konačni račun', 'hint' => 'Sa ili bez doplate']]);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))->format('Y-m-d\TH:i');
        $extra = self::extra() . '<input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'
            . '<div class="of-form-grid">'
            . Ui::field('Namena avansa', '<select name="scenario"><option value="hosting">Test hosting</option><option value="domain">Test domen</option></select>')
            . Ui::field('Primljeni iznos sa porezom · RSD', '<input name="amount" inputmode="decimal" value="1190.00" required>', 'Koristi tačku za decimale, na primer 1190.00.')
            . Ui::field('Datum i vreme uplate', '<input type="datetime-local" name="received" value="' . $now . '" required>', 'Vremenska zona: Beograd.')
            . Ui::field('Način prijema uplate', self::methods('method')) . '</div>'
            . Ui::field('Test identifikacija kupca', '<input name="buyer_id" value="20:000000000" pattern="20:[0-9]{9}" required>', 'Izmišljeni broj lične karte; bez stvarnih ličnih podataka.');
        echo '<div class="of-grid">' . Ui::card('Nova avansna uplata', Admin::form('advance_new', 0, 'Pregledaj test avans →', $extra), 'Korak 1: sačuvaj podatke, pa proveri dokument pre izdavanja.', 'primary')
            . Ui::card('Dva različita nastavka', Ui::note('Usluga je izvršena', 'Interni AR zatvara avans, zatim izdaješ konačni PP. Novac se ne vraća kupcu.', 'close')
                . Ui::note('Kupcu vraćaš uplatu', 'Pripremaš Avans–Refundaciju kupcu. Bankarska isplata je odvojena.', 'refund')
                . '<p class="of-muted">Jedna test uplata po porudžbini. Nema promene WHMCS uplata, aktivacije usluge ili automatskog mejla.</p>') . '</div>';
    }
    private static function detail(?array $config, int $id): void
    {
        $row = (new Store('', 'advance'))->find($id);
        if (!$row) { throw new RuntimeException('Dokument ne postoji.'); }
        $meta = json_decode($row['meta'], true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $kind = $row['kind']; $groupId = (int) $row['group_id'];
        echo Ui::heading(self::LABELS[$kind], 'Test porudžbina #' . $groupId . ' · Dokument #' . $id, Ui::badge($row['state']), 'Avansni postupak');
        echo Ui::documentSteps($row['state']);
        if ($kind === 'close') { echo Ui::note('Interno zatvaranje avansa · korak 1 od 2', 'Ne vraća novac kupcu. Posle potvrđenog zatvaranja mora se izdati konačni račun. Interni dokument ne dostavljaj kupcu.', 'close'); }
        if ($kind === 'return') { echo Ui::note('Povraćaj avansa kupcu', 'Izdaje se dokument povraćaja. Ne vraća novac preko banke; isplata i njena evidencija su odvojene.', 'refund'); }
        if (isset($meta['reason'])) { echo '<p><strong>Razlog:</strong> ' . Admin::e($meta['reason']) . '</p>'; }
        if ($row['state'] === 'review' && $config) {
            $body = Ui::payload($payload, $kind);
            foreach ($meta['notes'] ?? [] as $note) { $body .= '<p class="of-muted">' . Admin::e($note) . '</p>'; }
            $extra = self::extra($id) . '<input type="hidden" name="advance_hash" value="' . hash('sha256', $row['payload']) . '">'
                . Ui::confirm('confirmed', 'Proverio/la sam iznos i podatke. Potvrđujem sandbox postupak: ' . self::LABELS[$kind] . '.');
            if ($kind === 'final' && $meta['topup_cents'] > 0) { $extra .= Ui::confirm('confirmed_topup', 'Potvrđujem izmišljenu test doplatu ' . Ui::money($meta['topup_cents']) . ' RSD.'); }
            $label = match ($kind) { 'payment' => 'Potvrdi i izdaj test avans', 'return' => 'Potvrdi test povraćaj avansa',
                'close' => 'Potvrdi interno zatvaranje', default => 'Potvrdi i izdaj konačni test račun' };
            echo Ui::card('Proveri pre izdavanja', $body . Admin::form('advance_issue', 0, $label, $extra, $kind === 'close' ? 'close' : 'issue'));
        } elseif (in_array($row['state'], ['unknown', 'sending'], true)) {
            echo Ui::card('Prvo proveri ishod', Ui::note('Novo izdavanje je zaustavljeno', 'Zahtev možda već ima izdat dokument. Proveri postojeći zahtev; ne pripremaj zamenski.', 'danger')
                . Admin::form('advance_reconcile', 0, 'Proveri postojeći zahtev', self::extra($id)));
        } elseif ($row['state'] === 'issued') {
            echo Ui::payload($payload, $kind);
            if ($kind === 'close') {
                $ledger = Advance::ledger(Advance::documents($groupId));
                if (!$ledger['final'] && $config) {
                    $next = self::extra(0, $groupId) . '<input type="hidden" name="kind" value="final"><input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">';
                    echo Ui::card('Još jedan korak: konačni račun', Admin::form('advance_preview', 0, 'Nastavi na konačni račun →', $next),
                        'Interni AR je izdat. Ne ponavljaj zatvaranje; sada pripremi konačni Promet–Prodaja.', 'primary');
                } else { echo '<div class="of-actions">' . Ui::button(self::url(0, $groupId), 'Otvori postupak i konačni račun →', 'primary') . '</div>'; }
            } elseif ($kind === 'payment') {
                echo Ui::card('Avans je izdat. Izaberi sledeći korak.', Ui::button(self::url(0, $groupId), 'Otvori test porudžbinu i nastavi →', 'primary'),
                    'Usluga je izvršena: zatvaranje i konačni račun. Vraćaš uplatu: zaseban postupak povraćaja.', 'success');
            } elseif ($kind === 'final') { echo Ui::note('Konačni račun je izdat', 'Ovaj niz avansa je završen. Plaćeno avansno i eventualna doplata prikazani su uz dokument.', 'success'); }
            Admin::receipt(Receipt::decode($row['response']), 'advance', $id, $meta['notes'] ?? []);
            if ($kind === 'return') { self::moneyCard($row, $id); }
            if ($kind === 'final') { RefundAdmin::parentPanel('advance', $id); }
            CopyAdmin::parentPanel('advance', $id);
        }
        if ($row['state'] === 'rejected') { echo Ui::note('Zahtev je odbijen', (string) $row['message'], 'danger'); }
        echo '<div class="of-actions">' . Ui::button(self::url(0, $groupId), '← Test porudžbina #' . $groupId) . '</div>';
        echo Ui::metadata($row);
        if ($row['response']) { echo Ui::technical('Originalni odgovor V-PFR-a', $row['response']); }
    }
    private static function moneyCard(array $row, int $id): void
    {
        $body = Ui::note('Fiskalni dokument je izdat', 'Bankarska isplata se obavlja van ovog modula. Evidencija ispod je odvojena i ne šalje novac.', 'success');
        if ($row['money_recorded_at']) { $body .= '<p><strong>Test evidencija sačuvana:</strong> ' . Admin::e($row['money_reference']) . '</p>'; }
        else { $body .= Admin::form('advance_money', 0, 'Sačuvaj test evidenciju isplate', self::extra($id)
            . Ui::field('Referenca test isplate', '<input name="money_reference" maxlength="120" required>', 'Na primer: TEST-POVRACAJ-01.')
            . Ui::confirm('confirmed_money', 'Beležim izmišljenu test evidenciju. Banka se ne poziva.')); }
        echo Ui::card('Odvojena evidencija isplate', $body, 'Odsustvo ove evidencije ne znači da fiskalni dokument nije izdat.');
    }
    private static function group(?array $config, int $id): void
    {
        $group = Capsule::table(Store::ADVANCE_GROUPS)->where('id', $id)->first();
        if (!$group) { throw new RuntimeException('Test porudžbina ne postoji.'); }
        $root = json_decode($group->root, true, 512, JSON_THROW_ON_ERROR); $docs = Advance::documents($id); $ledger = Advance::ledger($docs);
        $pending = array_values(array_filter($docs, static fn($d) => in_array($d['state'], ['sending', 'unknown'], true)));
        $drafts = array_values(array_filter($docs, static fn($d) => $d['state'] === 'review'));
        $returned = $ledger['payment'] && !$ledger['close'] && $ledger['remaining'] === 0 && $ledger['returned'] >= $root['amount_cents'];
        $done = $ledger['final'] && $ledger['final']['state'] === 'issued';
        $status = $pending ? 'Ishod nije potvrđen' : ($done ? 'Postupak završen' : ($returned ? 'Avans refundiran kupcu' : ($ledger['close'] ? 'Čeka konačni račun' : ($drafts ? 'Postoji nacrt za pregled' : 'Otvoren avans'))));
        echo Ui::heading('Test porudžbina #' . $id, $root['description'], Ui::badge($pending ? 'unknown' : ($done || $returned ? 'issued' : 'review')), 'Avansni postupak');
        echo '<p><strong>' . Admin::e($status) . '</strong></p>';
        if ($returned) {
            echo Ui::steps([['label' => 'Avans primljen', 'hint' => 'Uplata je evidentirana', 'state' => 'done'],
                ['label' => 'Povraćaj kupcu', 'hint' => $pending ? 'Proveri ishod dokumenta' : 'Dokument povraćaja izdat', 'state' => $pending ? 'blocked' : 'done'],
                ['label' => 'Evidencija isplate', 'hint' => 'Odvojeno od fiskalnog dokumenta']]);
        } else {
            $paid = $ledger['payment'] && $ledger['payment']['state'] === 'issued'; $closed = $ledger['close'] && $ledger['close']['state'] === 'issued';
            echo Ui::steps([['label' => 'Primljeni avans', 'hint' => $paid ? 'Avans–Prodaja izdat' : 'Priprema ili provera', 'state' => $paid ? 'done' : ($pending ? 'blocked' : 'current')],
                ['label' => 'Interno zatvaranje', 'hint' => 'Korak 1 od 2 pri izvršenju', 'state' => $closed ? 'done' : ($paid && !$pending ? 'current' : ($pending && $ledger['close'] ? 'blocked' : 'waiting'))],
                ['label' => 'Konačni račun', 'hint' => 'Korak 2 od 2 pri izvršenju', 'state' => $done ? 'done' : ($closed && !$pending ? 'current' : ($pending && $ledger['final'] ? 'blocked' : 'waiting'))]]);
        }
        echo Ui::metrics([
            ['label' => 'Iznos test avansa', 'value' => Ui::money($root['amount_cents']), 'unit' => 'RSD', 'hint' => $ledger['payment'] && $ledger['payment']['state'] === 'issued' ? 'Avans–Prodaja je potvrđen' : 'Dokument u pripremi ili proveri'],
            ['label' => 'Refundirano / rezervisano', 'value' => Ui::money($ledger['returned']), 'unit' => 'RSD', 'hint' => 'Uključuje iznose sa nepoznatim ishodom', 'tone' => 'refund'],
            ['label' => 'Iznos zatvaranja', 'value' => Ui::money($ledger['close'] ? (int) $ledger['close']['amount_cents'] : 0), 'unit' => 'RSD', 'hint' => $ledger['close'] ? ($ledger['close']['state'] === 'issued' ? 'Interni dokument potvrđen' : 'Rezervisano postojećim zahtevom') : 'Interno zatvaranje nije pripremljeno'],
            ['label' => 'Otvoren avans', 'value' => Ui::money($ledger['remaining']), 'unit' => 'RSD', 'tone' => 'primary'],
        ]);
        echo '<div id="sledeci-korak">';
        if ($pending) { echo Ui::card('Najpre proveri postojeći zahtev', Ui::button(self::url((int) $pending[0]['invoice_id']), 'Otvori zahtev i proveri ishod', 'primary'), 'Novi dokument ne treba izdavati dok ishod nije potvrđen.'); }
        elseif ($drafts) { echo Ui::card('Nastavi postojeći nacrt', Ui::button(self::url((int) $drafts[0]['invoice_id']), 'Otvori nacrt i proveri podatke →', 'primary'), 'Nacrt je sačuvan. Pregled ne izdaje dokument; potvrda je poseban korak.'); }
        elseif ($config && $ledger['close'] && $ledger['close']['state'] === 'issued' && !$ledger['final']) {
            echo Ui::card('Nastavi: konačni račun', Admin::form('advance_preview', 0, 'Pripremi konačni Promet–Prodaja →', self::extra(0, $id)
                . '<input type="hidden" name="kind" value="final"><input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'),
                'Interni AR je već potvrđen. Sledi provera konačnog računa; zatvaranje se ne ponavlja.', 'primary');
        } elseif ($config && $ledger['remaining'] > 0 && !$ledger['close'] && !$ledger['final']) {
            $base = self::extra(0, $id);
            $close = $base . '<input type="hidden" name="kind" value="close"><input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'
                . Ui::field('Konačna vrednost usluge sa porezom · RSD', '<input name="final_amount" inputmode="decimal" value="' . Advance::money($ledger['remaining']) . '" required>', 'Unesi celu vrednost usluge. Eventualna doplata se računa u sledećem koraku.')
                . Ui::field('Način eventualne doplate', self::methods('topup_method', $root['method']));
            $refund = $base . '<input type="hidden" name="kind" value="return"><input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">'
                . Ui::field('Iznos koji vraćaš kupcu · RSD', '<input name="amount" inputmode="decimal" value="' . Advance::money($ledger['remaining']) . '" required>', 'Najviše preostali otvoreni avans; može i deo iznosa.')
                . Ui::field('Način povraćaja', self::methods('method', $root['method']))
                . Ui::field('Razlog povraćaja', '<textarea name="reason" maxlength="300" required placeholder="Na primer: test odustanka od narudžbine"></textarea>');
            echo Ui::heading('Kako nastavljaš ovu porudžbinu?', 'Izaberi odgovarajući postupak. Interno zatvaranje i vraćanje novca kupcu su različite radnje.');
            echo '<div class="of-grid">' . Ui::card('Usluga je izvršena', Ui::note('Zatvaranje → konačni račun', 'Dva potvrđena dokumenta. Ne vraća novac kupcu.', 'close')
                . Admin::form('advance_preview', 0, 'Pregledaj interno zatvaranje →', $close, 'close'), 'Završi avansni postupak i izdaj konačni račun.', 'close')
                . Ui::card('Vrati avans kupcu', Ui::note('Poseban postupak povraćaja', 'Izdaje dokument povraćaja; isplata preko banke obavlja se odvojeno.', 'refund')
                . Admin::form('advance_preview', 0, 'Pregledaj povraćaj kupcu →', $refund, 'refund'), 'Kada se vraća deo ili cela primljena uplata.', 'refund') . '</div>';
        } elseif ($done) { echo Ui::note('Postupak je završen', 'Avans je zatvoren i konačni račun je izdat. Dokumente možeš ponovo otvoriti u evidenciji ispod.', 'success'); }
        elseif ($returned && !$pending) { echo Ui::note('Avans je u celosti refundiran', 'Nema otvorenog avansa za zatvaranje. Evidencija bankarske isplate je odvojena na dokumentu povraćaja.', 'success'); }
        echo '</div>';
        $rows = '';
        foreach ($docs as $row) {
            $rows .= '<tr><td><a href="' . Admin::e(self::url((int) $row['invoice_id'])) . '">' . Admin::e(self::LABELS[$row['kind']]) . '</a><small>#' . (int) $row['invoice_id'] . '</small></td>'
                . '<td class="of-num">' . Ui::money((int) $row['amount_cents']) . '</td><td>' . Ui::badge($row['state']) . '</td><td>' . Admin::e($row['created_at']) . '</td></tr>';
        }
        echo Ui::card('Dokumenti ove porudžbine', '<div class="of-table-wrap"><table class="of-table"><thead><tr><th>Dokument</th><th class="of-num">Iznos RSD</th><th>Status</th><th>Evidencija (UTC)</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    }
}
