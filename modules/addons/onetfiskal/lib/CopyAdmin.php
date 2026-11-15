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
        echo '<details class="of-technical"><summary>Kopije dokumenta · opciono</summary><div class="of-details-body">';
        echo Ui::note('Treba ti samo ponovna štampa?', 'Otvori ili preuzmi original iznad. Posebna kopija je novi dokument sa referencom na original, bez nove prodaje ili povraćaja.');
        echo Admin::form('copy_preview', 0, 'Pregledaj posebnu test kopiju', self::extra(0)
            . '<input type="hidden" name="parent_source" value="' . Admin::e($source) . '">'
            . '<input type="hidden" name="parent_id" value="' . $id . '">'
            . '<input type="hidden" name="draft_key" value="' . bin2hex(random_bytes(16)) . '">', 'quiet');
        foreach (Capsule::table(Store::COPIES)->where('parent_source', $source)->where('parent_id', $id)->orderBy('id')->get() as $r) {
            echo '<p><a href="' . Admin::e(self::url((int) $r->invoice_id)) . '">Kopija #' . (int) $r->invoice_id
                . '</a> ' . Ui::badge($r->state) . '</p>';
        }
        echo '</div></details>';
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
        echo Ui::heading('Kopije dokumenata', 'Poseban dokument sa referencom na original. Ne evidentira novu prodaju, povraćaj ili bankarsku isplatu.');
        if ($id === 0) {
            echo Ui::card('Izaberi originalni dokument', '<div class="of-empty"><strong>Počni od izdate prodaje ili refundacije</strong>Otvori dokument u žurnalu, pa sekciju „Kopije dokumenta”.</div>'
                . '<div class="of-actions">' . Ui::button('addonmodules.php?module=onetfiskal&view=journal', 'Pronađi original u žurnalu', 'primary') . '</div>');
        } else {
            try {
                $row = (new Store('', 'copy'))->find($id);
                if (!$row) { throw new RuntimeException('Kopija ne postoji.'); }
                $parent = CopyDocument::parent($row['parent_source'], (int) $row['parent_id']);
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                echo Ui::heading('Kopija #' . $id, $payload['transactionType'] === 1 ? 'Kopija–Refundacija' : 'Kopija–Prodaja', Ui::badge($row['state']));
                echo Ui::documentSteps($row['state']);
                echo '<p>Original: <a class="of-reference" href="' . Admin::e(self::originalUrl($row['parent_source'], (int) $row['parent_id'])) . '">'
                    . Admin::e($parent['response']['invoiceNumber']) . '</a></p>';
                if ($row['state'] === 'issued') {
                    echo Ui::note('Test kopija je izdata', 'Oznaka „Ovo nije fiskalni račun” pripada dokumentu Kopija. Originalna prodaja ostaje zaseban dokument.', 'success');
                    Admin::receipt(Receipt::decode($row['response']), 'copy', $id, Advance::notes('copy', $row));
                } elseif (in_array($row['state'], ['sending', 'unknown'], true)) {
                    echo Ui::note('Najpre proveri postojeći zahtev', 'Ishod nije potvrđen. Provera ne izdaje novu kopiju.', 'sandbox');
                    echo Admin::form('copy_reconcile', 0, 'Proveri ishod kopije', self::extra($id));
                } elseif ($row['state'] === 'review' && $config) {
                    echo Ui::card('Proveri kopiju pre izdavanja', Ui::payload($payload)
                        . Admin::form('copy_issue', 0, 'Potvrdi i izdaj test kopiju', self::extra($id)
                        . '<input type="hidden" name="copy_hash" value="' . hash('sha256', $row['payload']) . '">'
                        . Ui::confirm('confirmed', 'Potvrđujem izdavanje posebnog sandbox dokumenta Kopija.')));
                }
                if ($row['message']) { echo '<p class="of-muted">' . Admin::e($row['message']) . '</p>'; }
                echo Ui::metadata($row);
                if ($row['response']) { echo Ui::technical('Sačuvan odgovor V-PFR-a', $row['response']); }
            } catch (RuntimeException $e) { echo Ui::note('Kopiju nije moguće prikazati', $e->getMessage(), 'danger'); }
        }
        echo '<div class="of-actions">' . Ui::button('addonmodules.php?module=onetfiskal&view=journal&source=copy&state=all', 'Sve kopije u žurnalu') . '</div>';
    }
}
