<?php
declare(strict_types=1);
namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

final class CopyAdmin
{
    public static function url(int $id = 0): string
    {
        return 'addonmodules.php?module=onetfiskal&view=copies&copyid=' . $id;
    }
    public static function originalUrl(string $source, int $id): string
    {
        return 'addonmodules.php?module=onetfiskal&' . match ($source) {
            'invoice' => 'invoiceid=' . $id, 'synthetic' => 'view=tests&testid=' . $id,
            'refund' => 'view=refunds&refundid=' . $id, 'advance' => 'view=advances&advanceid=' . $id, default => 'view=journal',
        };
    }
    private static function extra(int $id): string
    {
        return '<input type="hidden" name="view" value="copies"><input type="hidden" name="copyid" value="' . $id . '">';
    }
    public static function parentPanel(string $source, int $id): void
    {
        echo '<h4>Kopije dokumenta</h4><p>Otvaranje ili preuzimanje originala ne izdaje kopiju. '
            . 'Naredno dugme priprema poseban dokument Kopija, koji se šalje Poreskoj tek nakon potvrde.</p>';
        echo Admin::form('copy_preview', 0, 'Pripremi sandbox kopiju', self::extra(0)
            . '<input type="hidden" name="parent_source" value="' . Admin::e($source) . '">'
            . '<input type="hidden" name="parent_id" value="' . $id . '">'
            . '<input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">');
        foreach (Capsule::table(Store::COPIES)->where('parent_source', $source)->where('parent_id', $id)->orderBy('id')->get() as $r) {
            echo '<p><a href="' . Admin::e(self::url((int) $r->invoice_id)) . '">Kopija #' . (int) $r->invoice_id
                . '</a> · ' . Admin::e($r->state) . '</p>';
        }
    }
    public static function handle(string $action, array $config, int $id): int
    {
        $store = new CopyStore($config); $admin = (int) $_SESSION['adminid'];
        if ($action === 'copy_preview') {
            $row = $store->draft((string) ($_POST['parent_source'] ?? ''), (int) ($_POST['parent_id'] ?? 0),
                (string) ($_POST['draft_key'] ?? ''), $admin);
            return (int) $row['invoice_id'];
        }
        $row = $store->find($id);
        if (!$row) { throw new RuntimeException('Kopija ne postoji.'); }
        $service = new Service($store, new Vsdc($config), $config);
        if ($action === 'copy_issue') {
            if (($_POST['confirmed'] ?? '') !== 'yes'
                || !hash_equals(hash('sha256', $row['payload']), (string) ($_POST['copy_hash'] ?? ''))) {
                throw new RuntimeException('Potvrdi pregled izdavanja test kopije.');
            }
            $result = $service->submit($id, json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR), $admin);
        } elseif ($action === 'copy_reconcile') { $result = $service->reconcile($id); }
        else { throw new RuntimeException('Nepoznata akcija kopije.'); }
        echo '<div class="alert alert-info">' . Admin::e($result['message']) . '</div>';
        return $id;
    }
    public static function render(?array $config, int $id): void
    {
        echo '<h3>Kopije — sandbox</h3><p>Kopija–Prodaja ili Kopija–Refundacija: zaseban dokument sa referencom na original. '
            . 'Ne evidentira novu prodaju, novu refundaciju ili bankarsku isplatu; ne šalje mejl i ne menja WHMCS fakture.</p>';
        if ($id === 0) {
            echo '<p>Otvori izdatu prodaju ili refundaciju i klikni „Pripremi sandbox kopiju”.</p>';
        } else {
            try {
                $row = (new Store('', 'copy'))->find($id);
                if (!$row) { throw new RuntimeException('Kopija ne postoji.'); }
                $parent = CopyDocument::parent($row['parent_source'], (int) $row['parent_id']);
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                echo '<h4>Kopija #' . $id . '</h4><p>Stanje: ' . Admin::e($row['state']) . ' · RequestId: ' . Admin::e($row['request_id']) . '</p>'
                    . '<p>Original: <a href="' . Admin::e(self::originalUrl($row['parent_source'], (int) $row['parent_id'])) . '">'
                    . Admin::e($parent['response']['invoiceNumber']) . '</a></p>';
                if ($row['state'] === 'issued') { Admin::receipt(Receipt::decode($row['response']), 'copy', $id, Advance::notes('copy', $row)); }
                elseif (in_array($row['state'], ['sending', 'unknown'], true)) {
                    echo Admin::form('copy_reconcile', 0, 'Proveri ishod kopije (GET)', self::extra($id));
                } elseif ($row['state'] === 'review' && $config) {
                    echo '<p>Vrsta: ' . ($payload['transactionType'] === 1 ? 'Kopija–Refundacija' : 'Kopija–Prodaja') . '</p><pre>'
                        . Admin::e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                    echo Admin::form('copy_issue', 0, 'Izdaj test kopiju', self::extra($id)
                        . '<input type="hidden" name="copy_hash" value="' . hash('sha256', $row['payload']) . '">'
                        . '<p><label><input type="checkbox" name="confirmed" value="yes" required> Potvrđujem izdavanje posebnog sandbox dokumenta Kopija.</label></p>');
                }
                if ($row['message']) { echo '<p>' . Admin::e($row['message']) . '</p>'; }
                if ($row['response']) { echo '<details><summary>Sačuvan odgovor V-PFR-a</summary><pre>' . Admin::e($row['response']) . '</pre></details>'; }
            } catch (RuntimeException $e) { echo '<div class="alert alert-danger">' . Admin::e($e->getMessage()) . '</div>'; }
        }
        echo '<p><a href="addonmodules.php?module=onetfiskal&amp;view=journal&amp;source=copy&amp;state=all">Sve kopije u žurnalu</a></p>';
    }
}
