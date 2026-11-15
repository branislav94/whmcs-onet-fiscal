<?php
declare(strict_types=1);

namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

/** Read-only, portable queries over both existing evidence tables; no live fiscal calls. */
final class Journal
{
    public const STATES = ['issued', 'review', 'sending', 'unknown', 'rejected'];
    public const PAGE_SIZE = 25;

    public static function filters(array $input): array
    {
        foreach (['q', 'source', 'state', 'from', 'to', 'page'] as $key) {
            if (isset($input[$key]) && !is_scalar($input[$key])) { throw new RuntimeException('Neispravan filter.'); }
        }
        $q = trim((string) ($input['q'] ?? ''));
        if (mb_strlen($q) > 120) { throw new RuntimeException('Pretraga može imati najviše 120 znakova.'); }
        $source = (string) ($input['source'] ?? 'all');
        $state = (string) ($input['state'] ?? 'issued');
        if (!in_array($source, ['all', 'invoice', 'synthetic', 'refund', 'copy', 'advance'], true)
            || !in_array($state, array_merge(['all'], self::STATES), true)) {
            throw new RuntimeException('Nepoznat izvor ili stanje.');
        }
        $f = ['q' => $q, 'source' => $source, 'state' => $state];
        foreach (['from', 'to'] as $key) {
            $s = (string) ($input[$key] ?? '');
            if ($s !== '' && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $s)) {
                throw new RuntimeException('Datum mora biti važeći i u obliku GGGG-MM-DD.');
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $s, new \DateTimeZone('UTC'));
            if ($s !== '' && (!$date || $date->format('Y-m-d') !== $s)) {
                throw new RuntimeException('Datum mora biti važeći i u obliku GGGG-MM-DD.');
            }
            $f[$key] = $s;
        }
        if ($f['from'] !== '' && $f['to'] !== '' && $f['from'] > $f['to']) {
            throw new RuntimeException('Početni datum mora biti pre završnog.');
        }
        $page = (string) ($input['page'] ?? '1');
        if (!preg_match('/^[1-9][0-9]{0,6}$/D', $page)) { throw new RuntimeException('Neispravna stranica.'); }
        $f['page'] = (int) $page;
        return $f;
    }

    public static function search(array $input): array
    {
        $f = self::filters($input);
        $sources = $f['source'] === 'all' ? ['invoice', 'synthetic', 'refund', 'copy', 'advance'] : [$f['source']];
        $union = null;
        foreach ($sources as $source) {
            $table = match ($source) { 'invoice' => Store::TABLE, 'synthetic' => Store::TESTS, 'refund' => Store::REFUNDS, 'advance' => Store::ADVANCES, default => Store::COPIES };
            $query = Capsule::table($table)->select(['id', 'invoice_id', 'state', 'request_id', 'response', 'created_at', 'updated_at'])
                ->selectRaw('? AS source', [$source])
                ->selectRaw($source === 'advance' ? 'kind AS document_kind' : 'NULL AS document_kind');
            if ($f['state'] !== 'all') { $query->where('state', $f['state']); }
            if ($f['from'] !== '') { $query->where('created_at', '>=', $f['from'] . ' 00:00:00'); }
            if ($f['to'] !== '') {
                $end = (new \DateTimeImmutable($f['to'], new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
                $query->where('created_at', '<', $end);
            }
            if ($f['q'] !== '') {
                $q = $f['q'];
                // Escape SQL wildcard characters; user text is always a bound parameter.
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
                $query->where(function ($query) use ($q, $like): void {
                    if (ctype_digit($q) && strlen($q) <= 10) {
                        $query->where('invoice_id', (int) $q);
                    } else {
                        $query->whereRaw("response LIKE ? ESCAPE '!'", [$like])->orWhere('request_id', $q);
                    }
                });
            }
            $union = $union === null ? $query : $union->unionAll($query);
        }
        $outer = Capsule::connection()->query()->fromSub($union, 'evidence');
        $total = (clone $outer)->count();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $f['page'] = min($f['page'], $pages);
        $rows = $outer->orderByDesc('created_at')->orderBy('source')->orderByDesc('id')
            ->offset(($f['page'] - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)->get();
        return ['filters' => $f, 'total' => $total, 'pages' => $pages,
            'rows' => array_map(static fn($r) => (array) $r, $rows->all())];
    }

    private static function url(array $f, int $page): string
    {
        return 'addonmodules.php?' . http_build_query(array_merge($f,
            ['module' => 'onetfiskal', 'view' => 'journal', 'page' => $page]), '', '&', PHP_QUERY_RFC3986);
    }

    public static function render(array $input): void
    {
        echo Ui::heading('Elektronski žurnal', 'Pretraga svih dokumenata i postupaka bez novog izdavanja.');
        echo '<p class="of-muted">Obuhvata prodaje, zasebne testove, refundacije i kopije; nije zbirni izveštaj prometa. '
            . 'Datum filtera je datum nastanka evidencije (UTC), a ne PFR datum izdavanja. Bez datuma prikazuje se cela sačuvana istorija.</p>';
        try { self::filters($input); }
        catch (RuntimeException $e) {
            echo '<div class="alert alert-danger">' . Admin::e($e->getMessage())
                . '</div><p><a href="addonmodules.php?module=onetfiskal&amp;view=journal">Resetuj filtere</a></p>';
            return;
        }
        try { $result = self::search($input); }
        catch (\Throwable) {
            echo '<div class="alert alert-danger">Pretraga nije uspela. Proveri dostupnost baze.</div>';
            return;
        }
        $f = $result['filters'];
        $controls = '<form class="of-form" method="get" action="addonmodules.php"><input type="hidden" name="module" value="onetfiskal">'
            . '<input type="hidden" name="view" value="journal">'
            . Ui::field('Pretraga dokumenata', '<input name="q" maxlength="120" placeholder="ID, PFR broj ili RequestId" value="' . Admin::e($f['q']) . '">', 'Pretražuje i tekst sačuvanog odgovora V-PFR-a.') . '<div class="of-form-grid">';
        foreach (['source' => ['all' => 'Svi izvori', 'invoice' => 'WHMCS fakture', 'synthetic' => 'Zasebni testovi', 'refund' => 'Refundacije', 'copy' => 'Kopije', 'advance' => 'Avansni postupak'],
            'state' => ['all' => 'Sva stanja', 'issued' => 'Izdat', 'review' => 'Čeka potvrdu', 'sending' => 'Slanje / provera', 'unknown' => 'Ishod nije potvrđen', 'rejected' => 'Odbijen']] as $name => $options) {
            $select = '<select name="' . $name . '">';
            foreach ($options as $value => $label) {
                $select .= '<option value="' . Admin::e($value) . '"' . ($f[$name] === $value ? ' selected' : '') . '>' . Admin::e($label) . '</option>';
            }
            $controls .= Ui::field($name === 'source' ? 'Izvor dokumenta' : 'Status', $select . '</select>');
        }
        $controls .= Ui::field('Evidentirano od · UTC', '<input type="date" name="from" value="' . Admin::e($f['from']) . '">')
            . Ui::field('Evidentirano do · UTC', '<input type="date" name="to" value="' . Admin::e($f['to']) . '">') . '</div>'
            . '<div class="of-form-footer"><button class="of-button of-button--primary">Pretraži dokumente</button>'
            . Ui::button('addonmodules.php?module=onetfiskal&view=journal', 'Resetuj filtere') . '</div></form>';
        echo Ui::card('Pronađi dokument', $controls, 'Filter datuma koristi nastanak lokalne evidencije, a ne datum izdavanja PFR-a.');
        echo '<p>Ukupno: ' . $result['total'] . ' · Stranica ' . $f['page'] . '/' . $result['pages'] . '</p>'
            . '<div class="of-table-wrap"><table class="of-table"><thead><tr><th>Izvor / ID</th><th>Stanje</th>'
            . '<th>PFR broj / RequestId</th><th class="of-num">Iznos RSD</th><th>PFR vreme (iz odgovora)</th><th>Evidencija (UTC)</th><th>Račun</th></tr></thead><tbody>';
        foreach ($result['rows'] as $row) {
            $id = (int) $row['invoice_id'];
            $source = $row['source'];
            $detail = Admin::e($source === 'copy' ? CopyAdmin::url($id) : CopyAdmin::originalUrl($source, $id));
            $response = json_decode((string) $row['response'], true);
            $response = is_array($response) ? $response : [];
            echo '<tr><td><a href="' . $detail . '">' . ($source === 'invoice' ? 'WHMCS #' : ($source === 'refund' ? 'Refundacija #' : ($source === 'copy' ? 'Kopija #' : ($source === 'advance' ? 'Avansni dokument #' : 'Test #')))) . $id . '</a>' . ($source === 'advance' ? '<br><small>' . Admin::e(AdvanceAdmin::LABELS[$row['document_kind']] ?? '') . '</small>' : '') . '</td><td>'
                . Ui::badge($row['state']) . '</td><td>' . Admin::e(is_scalar($response['invoiceNumber'] ?? null) ? $response['invoiceNumber'] : '')
                . '<br><small class="of-reference">' . Admin::e($row['request_id']) . '</small></td><td class="of-num">'
                . (is_scalar($response['totalAmount'] ?? null) ? Ui::value($response['totalAmount']) : '—') . '</td><td>'
                . Admin::e(is_scalar($response['sdcDateTime'] ?? null) ? $response['sdcDateTime'] : '') . '</td><td>'
                . Admin::e($row['created_at']) . '</td><td>';
            if ($row['state'] === 'issued') {
                echo '<a target="_blank" rel="noopener noreferrer" href="' . Admin::e(Receipt::endpoint()) . '?source='
                    . $source . '&amp;id=' . $id . '&amp;format=html">Štampa / PDF</a>';
            }
            echo '</td></tr>';
        }
        if ($result['total'] === 0) { echo '<tr><td colspan="7">Nema rezultata za izabrane filtere.</td></tr>'; }
        echo '</tbody></table></div><p>';
        if ($f['page'] > 1) { echo '<a class="btn btn-default" href="' . Admin::e(self::url($f, $f['page'] - 1)) . '">Prethodna</a> '; }
        if ($f['page'] < $result['pages']) { echo '<a class="btn btn-default" href="' . Admin::e(self::url($f, $f['page'] + 1)) . '">Sledeća</a>'; }
        echo '</p>';
    }
}
