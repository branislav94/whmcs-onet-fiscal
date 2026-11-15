<?php
declare(strict_types=1);
namespace Onet\Fiskal;

/** Presentation only: never writes financial evidence or calls a fiscal/banking service. */
final class Ui
{
    public static function styles(): string { return (string) file_get_contents(__DIR__ . '/../assets/admin.css'); }
    public static function money(int $cents): string { return number_format($cents / 100, 2, ',', '.'); }
    public static function value(mixed $value): string
    {
        try { return self::money(Money::cents($value)); } catch (\Throwable) { return '—'; }
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
        $tone = in_array($tone, ['primary', 'close', 'refund', 'success'], true) ? ' of-card--' . $tone : '';
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
        $html = '<div class="of-metrics">';
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
        $html = self::metrics($items) . '<div class="of-meta">';
        if (!empty($payload['referentDocumentNumber'])) { $html .= '<span>Original / referenca: <strong class="of-reference">' . Admin::e($payload['referentDocumentNumber']) . '</strong></span>'; }
        if (!empty($payload['buyerId'])) { $html .= '<span>ID kupca: <strong>' . Admin::e($payload['buyerId']) . '</strong></span>'; }
        $html .= '</div><div class="of-table-wrap"><table class="of-table"><thead><tr><th>Stavka</th><th class="of-num">Količina</th><th class="of-num">Cena sa porezom</th><th class="of-num">Ukupno RSD</th></tr></thead><tbody>';
        foreach ($payload['items'] as $item) {
            $html .= '<tr><td>' . Admin::e($item['name']) . '</td><td class="of-num">' . Admin::e(number_format((float) $item['quantity'], 3, ',', '.'))
                . '</td><td class="of-num">' . self::value($item['unitPrice']) . '</td><td class="of-num">' . self::value($item['totalAmount']) . '</td></tr>';
        }
        $method = PaymentLabels::name((int) ($payload['payment'][0]['paymentType'] ?? 4));
        return $html . '</tbody></table></div><p class="of-muted">' . ($copy ? 'Način plaćanja na originalu: ' : 'Način plaćanja: ')
            . Admin::e($method) . '</p>' . self::technical('Tehnički prikaz zahteva (JSON)', $payload);
    }
    public static function button(string $url, string $label, string $tone = 'quiet'): string
    {
        $tone = in_array($tone, ['primary', 'quiet', 'refund', 'close', 'success'], true) ? $tone : 'quiet';
        return '<a class="of-button of-button--' . $tone . '" href="' . Admin::e($url) . '">' . Admin::e($label) . '</a>';
    }
    public static function nav(string $active): string
    {
        $tabs = ['invoices' => ['Fakture', ''], 'journal' => ['Žurnal', 'journal'], 'advances' => ['Avansi', 'advances'],
            'refunds' => ['Povraćaji', 'refunds'], 'copies' => ['Kopije', 'copies'], 'tests' => ['Test prodaja', 'tests']];
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
