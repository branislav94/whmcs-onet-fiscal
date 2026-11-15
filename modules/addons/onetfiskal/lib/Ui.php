<?php
declare(strict_types=1);
namespace Onet\Fiskal;

use WHMCS\Database\Capsule;

/** Presentation only: never writes financial evidence or calls a fiscal/banking service. */
final class Ui
{
    public static function styles(): string { return (string) file_get_contents(__DIR__ . '/../assets/admin.css'); }
    public static function money(int $cents): string { return number_format($cents / 100, 2, ',', '.'); }
    public static function value(mixed $value): string
    {
        try { return self::money(Money::cents($value)); } catch (\Throwable) { return '—'; }
    }
    /** Display-only lookup, one query per list. Never derives or assigns invoice numbers. */
    public static function invoiceNumbers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (!$ids) { return []; }
        try {
            $rows = Capsule::table('tblinvoices')->whereIn('id', $ids)->get(['id', 'invoicenum']);
        } catch (\Throwable) {
            // Historical fiscal documents remain readable when WHMCS metadata is unavailable.
            return [];
        }
        $numbers = [];
        foreach ($rows as $row) {
            $number = trim((string) ($row->invoicenum ?? ''));
            if ($number !== '') { $numbers[(int) $row->id] = $number; }
        }
        return $numbers;
    }
    public static function invoiceTag(?string $number): string
    {
        $number = trim($number ?? '');
        return $number === '' ? '' : '<span class="of-invoice-tag" title="WHMCS broj računa">' . Admin::e($number) . '</span>';
    }
    public static function invoiceIdentity(int $id, ?string $number): string
    {
        return '<span class="of-invoice-identity"><span class="of-invoice-id">#' . $id . '</span>' . self::invoiceTag($number) . '</span>';
    }
    public static function badge(string $state): string
    {
        $labels = ['issued' => 'Izdat', 'review' => 'Čeka potvrdu', 'sending' => 'Slanje / provera',
            'unknown' => 'Ishod nije potvrđen', 'rejected' => 'Odbijen', 'sandbox' => 'SANDBOX', 'close' => 'Interni postupak'];
        $s = isset($labels[$state]) ? $state : 'review';
        return '<span class="of-badge of-badge--' . $s . '" data-state="' . $s . '">' . Admin::e($labels[$state] ?? $state) . '</span>';
    }
    public static function heading(string $title, string $description = '', string $actions = '', string $kicker = ''): string
    {
        return '<div class="of-heading"><div>' . ($kicker ? '<div class="of-kicker">' . Admin::e($kicker) . '</div>' : '')
            . '<h2>' . Admin::e($title) . '</h2>' . ($description ? '<p>' . Admin::e($description) . '</p>' : '') . '</div>' . $actions . '</div>';
    }
    public static function card(string $title, string $body, string $description = '', string $tone = ''): string
    {
        $tone = in_array($tone, ['primary', 'close', 'refund', 'success', 'records'], true) ? ' of-card--' . $tone : '';
        return '<section class="of-card' . $tone . '"><div class="of-card-header"><h3>' . Admin::e($title) . '</h3>'
            . ($description ? '<p>' . Admin::e($description) . '</p>' : '') . '</div>' . $body . '</section>';
    }
    public static function note(string $title, string $body, string $tone = ''): string
    {
        $tone = in_array($tone, ['sandbox', 'refund', 'close', 'success', 'danger'], true) ? ' of-note--' . $tone : '';
        return '<div class="of-note' . $tone . '"><strong>' . Admin::e($title) . '</strong><p>' . Admin::e($body) . '</p></div>';
    }
    public static function metrics(array $items): string
    {
        $html = '<div class="of-metrics" style="--of-metrics:' . count($items) . '">';
        foreach ($items as $item) {
            $tone = in_array($item['tone'] ?? '', ['primary', 'refund'], true) ? ' of-metric--' . $item['tone'] : '';
            $html .= '<div class="of-metric' . $tone . '"><div class="of-metric-label">' . Admin::e($item['label'])
                . '</div><div class="of-metric-value">' . Admin::e($item['value'])
                . (!empty($item['unit']) ? '<small>' . Admin::e($item['unit']) . '</small>' : '') . '</div>'
                . (!empty($item['hint']) ? '<div class="of-metric-hint">' . Admin::e($item['hint']) . '</div>' : '') . '</div>';
        }
        return $html . '</div>';
    }
    public static function steps(array $steps): string
    {
        $html = '<ol class="of-steps" aria-label="Koraci postupka" style="--of-steps:' . count($steps) . '">';
        foreach ($steps as $i => $step) {
            $state = in_array($step['state'] ?? '', ['done', 'current', 'blocked'], true) ? $step['state'] : 'waiting';
            $html .= '<li class="of-step of-step--' . $state . '"' . ($state === 'current' ? ' aria-current="step"' : '')
                . '><span class="of-step-index" aria-hidden="true">' . ($state === 'done' ? '✓' : $i + 1) . '</span><div><strong>'
                . Admin::e($step['label']) . '</strong><small>' . Admin::e($step['hint'] ?? '') . '</small></div></li>';
        }
        return $html . '</ol>';
    }
    public static function documentSteps(string $state): string
    {
        return self::steps([
            ['label' => 'Podaci', 'hint' => 'Nacrt je sačuvan', 'state' => 'done'],
            ['label' => 'Provera i potvrda', 'hint' => $state === 'review' ? 'Proveri iznos pre slanja' : ($state === 'issued' ? 'Potvrđeno' : 'Proveri ishod zahteva'),
                'state' => $state === 'issued' ? 'done' : ($state === 'review' ? 'current' : 'blocked')],
            ['label' => 'Izdat dokument', 'hint' => 'Broj računa i QR provera', 'state' => $state === 'issued' ? 'done' : 'waiting'],
        ]);
    }
    public static function field(string $label, string $control, string $hint = ''): string
    {
        return '<label class="of-field"><span class="of-label">' . Admin::e($label) . '</span>' . $control
            . ($hint ? '<span class="of-field-hint">' . Admin::e($hint) . '</span>' : '') . '</label>';
    }
    public static function confirm(string $name, string $text): string
    {
        return '<label class="of-confirm"><input type="checkbox" name="' . Admin::e($name) . '" value="yes" required><span>' . Admin::e($text) . '</span></label>';
    }
    public static function technical(string $title, mixed $data): string
    {
        $text = is_array($data) ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $data;
        return '<details class="of-technical"><summary>' . Admin::e($title) . '</summary><pre>' . Admin::e($text) . '</pre></details>';
    }
    public static function metadata(array $row): string
    {
        return self::technical('Tehnički podaci zahteva', ['state' => $row['state'], 'RequestId' => $row['request_id'] ?? '',
            'evidence_id' => $row['invoice_id'] ?? '', 'updated_at_utc' => $row['updated_at'] ?? '']);
    }
    public static function payload(array $payload, string $purpose = ''): string
    {
        $total = array_sum(array_map(static fn($i) => Money::cents($i['totalAmount']), $payload['items']));
        $paid = array_sum(array_map(static fn($p) => Money::cents($p['amount']), $payload['payment']));
        $copy = ($payload['invoiceType'] ?? 0) === 2;
        $refund = ($payload['transactionType'] ?? 0) === 1;
        $label = $purpose === 'close' ? 'Iznos internog zatvaranja' : ($copy ? 'Vrednost originalnog dokumenta' : ($refund ? 'Iznos za povraćaj kupcu' : 'Vrednost dokumenta'));
        $items = [['label' => $label, 'value' => self::money($total), 'unit' => 'RSD', 'tone' => $purpose === 'close' ? '' : ($refund ? 'refund' : 'primary')]];
        if (!$copy && !$refund) { $items[] = ['label' => $purpose === 'final' ? 'Doplata uz konačni račun' : 'Plaćanje u dokumentu', 'value' => self::money($paid), 'unit' => 'RSD',
            'hint' => $paid === 0 ? 'Nema nove uplate uz ovaj dokument' : 'Ne pokreće bankarsku transakciju']; }
        $html = '<div class="of-document-preview">' . self::metrics($items) . '<div class="of-meta">';
        if (!empty($payload['referentDocumentNumber'])) { $html .= '<span>Original / referenca: <strong class="of-reference">' . Admin::e($payload['referentDocumentNumber']) . '</strong></span>'; }
        if (!empty($payload['buyerId'])) { $html .= '<span>ID kupca: <strong>' . Admin::e($payload['buyerId']) . '</strong></span>'; }
        $html .= '</div><div class="of-table-wrap"><table class="of-table"><thead><tr><th>Stavka</th><th class="of-num">Količina</th><th class="of-num">Cena sa porezom</th><th class="of-num">Ukupno RSD</th></tr></thead><tbody>';
        foreach ($payload['items'] as $item) {
            $html .= '<tr><td>' . Admin::e($item['name']) . '</td><td class="of-num">' . Admin::e(number_format((float) $item['quantity'], 3, ',', '.'))
                . '</td><td class="of-num">' . self::value($item['unitPrice']) . '</td><td class="of-num">' . self::value($item['totalAmount']) . '</td></tr>';
        }
        $method = PaymentLabels::name((int) ($payload['payment'][0]['paymentType'] ?? 4));
        return $html . '</tbody></table></div><p class="of-muted">' . ($copy ? 'Način plaćanja na originalu: ' : 'Način plaćanja: ')
            . Admin::e($method) . '</p>' . self::technical('Tehnički prikaz zahteva (JSON)', $payload) . '</div>';
    }
    public static function button(string $url, string $label, string $tone = 'quiet'): string
    {
        $tone = in_array($tone, ['primary', 'quiet', 'refund', 'close', 'success'], true) ? $tone : 'quiet';
        return '<a class="of-button of-button--' . $tone . '" href="' . Admin::e($url) . '">' . Admin::e($label) . '</a>';
    }
    public static function nav(string $active): string
    {
        $tabs = ['invoices' => ['Radni pregled', ''], 'journal' => ['Žurnal', 'journal'], 'advances' => ['Avansi', 'advances'],
            'refunds' => ['Povraćaji', 'refunds'], 'copies' => ['Kopije', 'copies'], 'tests' => ['Test prodaja', 'tests'], 'delivery' => ['Dostava · pregled', 'delivery']];
        $html = '<nav class="of-nav" aria-label="ONET Fiskal sekcije">';
        foreach ($tabs as $key => [$label, $view]) {
            $html .= '<a href="addonmodules.php?module=onetfiskal' . ($view ? '&amp;view=' . $view : '') . '"'
                . ($active === $key ? ' aria-current="page"' : '') . '>' . self::icon($key) . Admin::e($label) . '</a>';
        }
        return $html . '</nav>';
    }
    private static function icon(string $name): string
    {
        $path = match ($name) {
            'advances' => '<path d="M4 5h16v14H4zM4 10h16M8 15h3"/>',
            'refunds' => '<path d="M9 6 4 11l5 5M4 11h10a5 5 0 0 1 0 10"/>',
            'copies' => '<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V3H3v13h5"/>',
            'tests' => '<path d="m8 3 2 6-5 10h14L14 9l2-6M9 3h6M8 14h8"/>',
            default => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        };
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}

/** Read-only task overview. It cannot claim, issue, reconcile or change a financial record. */
final class Workbench
{
    private static function query(bool $summary = false): mixed
    {
        $union = null;
        foreach (['invoice' => Store::TABLE, 'synthetic' => Store::TESTS, 'refund' => Store::REFUNDS, 'copy' => Store::COPIES, 'advance' => Store::ADVANCES] as $source => $table) {
            $q = Capsule::table($table)->select('state');
            if (!$summary) {
                $q->select(['id', 'invoice_id', 'state', 'response', 'payload', 'updated_at'])
                    ->selectRaw($source === 'synthetic' ? 'snapshot' : 'NULL AS snapshot')
                    ->selectRaw('? AS source', [$source])->selectRaw($source === 'advance' ? 'kind AS kind, group_id AS group_id' : 'NULL AS kind, NULL AS group_id');
            }
            $union = $union ? $union->unionAll($q) : $q;
        }
        return Capsule::connection()->query()->fromSub($union, 'work');
    }
    public static function data(string $filter = 'auto'): array
    {
        if (!in_array($filter, ['auto', 'attention', 'review', 'issued', 'all'], true)) { $filter = 'auto'; }
        $query = self::query(); $counts = ['attention' => 0, 'review' => 0, 'issued' => 0, 'all' => 0];
        foreach (self::query(true)->select('state')->selectRaw('COUNT(*) AS amount')->groupBy('state')->get() as $r) {
            $count = (int) $r->amount; $counts['all'] += $count;
            if (in_array($r->state, ['unknown', 'sending', 'rejected'], true)) { $counts['attention'] += $count; }
            elseif (array_key_exists($r->state, $counts)) { $counts[$r->state] += $count; }
        }
        if ($filter === 'auto') { $filter = $counts['attention'] ? 'attention' : ($counts['review'] ? 'review' : 'all'); }
        if ($filter === 'attention') { $query->whereIn('state', ['unknown', 'sending', 'rejected']); }
        elseif ($filter !== 'all') { $query->where('state', $filter); }
        $rows = $query->orderByRaw("CASE state WHEN 'unknown' THEN 0 WHEN 'sending' THEN 1 WHEN 'rejected' THEN 2 WHEN 'review' THEN 3 ELSE 4 END")
            ->orderByDesc('updated_at')->orderBy('source')->orderByDesc('id')->limit(25)->get()->map(static fn($r) => (array) $r)->all();
        $closing = Capsule::table(Store::ADVANCES . ' as closing')->where('closing.kind', 'close')->where('closing.state', 'issued')
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from(Store::ADVANCES . ' as final')->whereColumn('final.group_id', 'closing.group_id')
                    ->where('final.kind', 'final')->whereIn('final.state', ['review', 'sending', 'unknown', 'issued']);
            });
        return ['counts' => $counts, 'filter' => $filter, 'rows' => $rows, 'closing_count' => (clone $closing)->count(),
            'closings' => $closing->orderByDesc('closing.updated_at')->limit(5)->get(['closing.group_id', 'closing.invoice_id'])->all()];
    }
    public static function amount(array $row): string
    {
        $response = json_decode((string) $row['response'], true);
        if ($row['state'] === 'issued' && is_scalar($response['totalAmount'] ?? null)) { return Ui::value($response['totalAmount']); }
        $payload = json_decode((string) $row['payload'], true);
        if (is_array($payload['items'] ?? null) && $payload['items']) {
            try { return Ui::money(array_sum(array_map(static fn($i) => Money::cents($i['totalAmount']), $payload['items']))); }
            catch (\Throwable) { return '—'; }
        }
        $snapshot = json_decode((string) $row['snapshot'], true);
        return isset($snapshot['invoice']['total']) ? Ui::value($snapshot['invoice']['total']) : '—';
    }
    public static function render(string $lookup): void
    {
        $data = self::data(is_string($_GET['work'] ?? null) ? $_GET['work'] : 'auto');
        echo '<div class="of-work-heading"><div><div class="of-kicker">FISKALNA EVIDENCIJA</div><h2>Radni pregled</h2><p>Proveri zahteve, nastavi nacrte i pronađi izdate dokumente.</p></div>'
            . '<div class="of-lookup">' . $lookup . '</div></div>';
        $labels = ['attention' => ['Zahteva pažnju', 'Nepotvrđeni ili odbijeni zahtevi'], 'review' => ['Čeka tvoju potvrdu', 'Sačuvani nacrti · još nisu izdati'],
            'issued' => ['Izdati dokumenti', 'Prodaje, povraćaji, avansi i kopije']];
        echo '<div class="of-work-stats">';
        foreach ($labels as $key => [$label, $hint]) {
            echo '<a class="of-work-stat of-work-stat--' . $key . '" href="addonmodules.php?module=onetfiskal&amp;work=' . $key . '" data-count="' . $data['counts'][$key] . '"><span>' . $label . '</span><strong>'
                . $data['counts'][$key] . '</strong><small>' . $hint . '</small></a>';
        }
        echo '</div>';
        if ($data['closing_count']) {
            echo '<section class="of-next-required"><div><strong>Avansni postupak nije završen</strong><p>' . $data['closing_count'] . ' porudžbina ima potvrđeno interno zatvaranje, ali još nema nacrt konačnog računa.</p></div><div class="of-actions">';
            foreach ($data['closings'] as $r) { echo Ui::button(AdvanceAdmin::url(0, (int) $r->group_id), 'Nastavi porudžbinu #' . (int) $r->group_id, 'primary'); }
            echo '</div></section>';
        }
        echo '<section class="of-work-list"><div class="of-list-heading"><div><h3>Dokumenti i sledeće radnje</h3><p>Do 25 rezultata. Ovo su brojevi dokumenata, a ne zbir prometa.</p></div>'
            . Ui::button('addonmodules.php?module=onetfiskal&view=journal&state=all', 'Ceo žurnal →') . '</div><nav class="of-work-filters" aria-label="Filter radnog pregleda">';
        foreach (['attention' => 'Zahteva pažnju', 'review' => 'Nacrti', 'issued' => 'Izdati', 'all' => 'Svi dokumenti'] as $key => $label) {
            echo '<a href="addonmodules.php?module=onetfiskal&amp;work=' . $key . '"' . ($data['filter'] === $key ? ' aria-current="page"' : '') . '>' . $label . '<span>' . $data['counts'][$key] . '</span></a>';
        }
        echo '</nav><div class="of-table-wrap"><table class="of-table"><thead><tr><th>Dokument</th><th>Veza / broj računa</th><th class="of-num">Iznos RSD</th><th>Status</th><th>Sledeća radnja</th></tr></thead><tbody>';
        $numbers = Ui::invoiceNumbers(array_column(array_filter($data['rows'], static fn($r) => $r['source'] === 'invoice'), 'invoice_id'));
        foreach ($data['rows'] as $row) {
            $id = (int) $row['invoice_id']; $source = $row['source']; $state = $row['state'];
            $url = $source === 'copy' ? CopyAdmin::url($id) : CopyAdmin::originalUrl($source, $id);
            $title = match ($source) { 'invoice' => 'WHMCS faktura', 'synthetic' => 'Test prodaja', 'refund' => 'Povraćaj kupcu', 'copy' => 'Kopija dokumenta', default => AdvanceAdmin::LABELS[$row['kind']] ?? 'Avansni dokument' };
            $action = match ($state) { 'unknown', 'sending' => 'Proveri ishod →', 'review' => 'Pregledaj i nastavi →', 'rejected' => 'Pogledaj razlog →', default => 'Otvori dokument →' };
            $response = json_decode((string) $row['response'], true); $amount = self::amount($row);
            echo '<tr class="of-work-row of-work-row--' . Admin::e($state) . '"><td><a class="of-row-title" href="' . Admin::e($url) . '">' . Admin::e($title) . '</a><small>ID #' . $id . ' · ' . Admin::e($row['updated_at']) . ' UTC</small></td><td>';
            if ($source === 'invoice') { echo Ui::invoiceIdentity($id, $numbers[$id] ?? null); }
            elseif ($source === 'advance') { echo '<span class="of-invoice-tag">Porudžbina #' . (int) $row['group_id'] . '</span>'; }
            else { echo '<span class="of-muted">' . ($source === 'synthetic' ? 'Zaseban test' : 'Referenca u dokumentu') . '</span>'; }
            if ($state === 'issued' && is_string($response['invoiceNumber'] ?? null)) { echo '<small class="of-reference">' . Admin::e($response['invoiceNumber']) . '</small>'; }
            echo '</td><td class="of-num">' . $amount . ($amount === '—' ? '<small>Proverava se na pregledu</small>' : '') . '</td><td>' . Ui::badge($state) . '</td><td>' . Ui::button($url, $action, in_array($state, ['unknown', 'sending', 'rejected'], true) ? 'refund' : ($state === 'review' ? 'primary' : 'quiet')) . '</td></tr>';
        }
        if (!$data['rows']) { echo '<tr><td colspan="5"><div class="of-empty"><strong>Nema dokumenata u ovom prikazu</strong>Izaberi drugi filter ili otvori WHMCS fakturu.</div></td></tr>'; }
        echo '</tbody></table></div></section>';
    }
}
