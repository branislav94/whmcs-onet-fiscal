<?php
declare(strict_types=1);
namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

/** Explicitly requested Copy documents, separate from reprinting the saved original. */
final class CopyDocument
{
    public static function parent(string $source, int $id): array
    {
        if (!in_array($source, ['invoice', 'synthetic', 'refund'], true) || $id < 1) {
            throw new RuntimeException('Izaberi potvrđenu originalnu prodaju ili refundaciju; kopija kopije nije podržana.');
        }
        $row = (new Store('', $source))->find($id);
        if (!$row || $row['state'] !== 'issued') { throw new RuntimeException('Originalni dokument nije potvrđeno izdat.'); }
        $payload = json_decode((string) $row['payload'], true);
        $response = Receipt::decode($row['response']);
        if (!is_array($payload) || ($payload['invoiceType'] ?? null) !== 0
            || !in_array($payload['transactionType'] ?? null, [0, 1], true)
            || empty($payload['items']) || empty($payload['payment']) || empty($row['credential'])
            || !is_string($response['sdcDateTime'] ?? null)) {
            throw new RuntimeException('Original nema kompletnu evidenciju za ovaj tip kopije.');
        }
        return ['row' => $row, 'payload' => $payload, 'response' => $response];
    }

    public static function build(array $parent, int $adminId): array
    {
        $p = $parent['payload'];
        $copy = ['dateAndTimeOfIssue' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))->format('c'),
            'cashier' => 'WHMCS admin ' . $adminId, 'invoiceType' => 2, 'transactionType' => $p['transactionType'],
            'referentDocumentNumber' => $parent['response']['invoiceNumber'],
            'referentDocumentDT' => $parent['response']['sdcDateTime'],
            'payment' => $p['payment'], 'items' => $p['items'],
            'options' => ['omitQRCodeGen' => '0', 'omitTextualRepresentation' => '0']];
        foreach (['buyerId', 'buyerCostCenterId'] as $key) {
            if (isset($p[$key])) { $copy[$key] = $p[$key]; }
        }
        return $copy;
    }
}

final class CopyStore implements Repository
{
    private Store $store;
    public function __construct(private array $config)
    {
        $this->store = new Store(hash_file('sha256', $config['pfx_path']), 'copy');
    }
    public function find(int $id): ?array { return $this->store->find($id); }
    public function queue(int $id): void { $this->store->queue($id); }
    public function finish(int $id, string $state, mixed $response, string $message, bool $release): void
    {
        $this->store->finish($id, $state, $response, $message, $release);
    }
    private function compatible(array $parent): void
    {
        if (($this->config['environment'] ?? '') !== 'sandbox'
            || ($this->config['vsdc_url'] ?? '') !== 'https://vsdc.sandbox.suf.purs.gov.rs'
            || $parent['row']['credential'] !== hash_file('sha256', $this->config['pfx_path'])
            || ($parent['response']['requestedBy'] ?? '') !== $this->config['expected_uid']) {
            throw new RuntimeException('Koristi isti originalni sandbox PFX i JID za izdavanje kopije.');
        }
        foreach ($parent['payload']['items'] as $item) {
            if ($item['labels'] !== [$this->config['tax_label']]) {
                throw new RuntimeException('Poreska oznaka u konfiguraciji mora odgovarati originalu.');
            }
        }
    }
    public function draft(string $source, int $parentId, string $key, int $adminId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) { throw new RuntimeException('Neispravan identifikator nacrta.'); }
        $existing = Capsule::table(Store::COPIES)->where('draft_key', $key)->first();
        if ($existing) { return $this->owned($existing, $source, $parentId, $adminId); }
        $parent = CopyDocument::parent($source, $parentId);
        $this->compatible($parent);
        $payload = CopyDocument::build($parent, $adminId);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            Capsule::table(Store::COPIES)->insertOrIgnore(['invoice_id' => random_int(1, 2147483647),
                'parent_source' => $source, 'parent_id' => $parentId, 'draft_key' => $key,
                'state' => 'review', 'admin_id' => $adminId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $row = Capsule::table(Store::COPIES)->where('draft_key', $key)->first();
            if ($row) { return $this->owned($row, $source, $parentId, $adminId); }
        }
        throw new RuntimeException('Nacrt kopije nije sačuvan.');
    }
    private function owned(object $row, string $source, int $parentId, int $adminId): array
    {
        if ($row->parent_source !== $source || (int) $row->parent_id !== $parentId || (int) $row->admin_id !== $adminId) {
            throw new RuntimeException('Nacrt pripada drugom dokumentu ili administratoru.');
        }
        return (array) $row;
    }
    public function claim(int $id, array $payload, int $adminId): array
    {
        return Capsule::connection()->transaction(function () use ($id, $payload, $adminId): array {
            Capsule::table(Store::GUARD)->where('id', 1)->lockForUpdate()->first();
            $row = Capsule::table(Store::COPIES)->where('invoice_id', $id)->lockForUpdate()->first();
            if (!$row || $row->state !== 'review' || (int) $row->admin_id !== $adminId) {
                throw new RuntimeException('Nacrt nije dostupan za novo izdavanje.');
            }
            $parent = CopyDocument::parent($row->parent_source, (int) $row->parent_id);
            $this->compatible($parent);
            $expected = CopyDocument::build($parent, $adminId);
            $expected['dateAndTimeOfIssue'] = $payload['dateAndTimeOfIssue'] ?? '';
            $saved = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($saved !== $payload || json_encode($expected) !== json_encode($payload)
                || strtotime($row->created_at . ' UTC') < time() - 3600) {
                throw new RuntimeException('Nacrt je promenjen ili stariji od sat vremena. Napravi novi pregled.');
            }
            return $this->store->claim($id, $payload, $adminId);
        });
    }
}
