<?php
declare(strict_types=1);

namespace Onet\Fiskal;

use RuntimeException;
use WHMCS\Database\Capsule;

final class Config
{
    public static function load(string $path): array
    {
        self::privateFile($path);
        $c = require $path;
        if (!is_array($c) || ($c['environment'] ?? '') !== 'sandbox') {
            throw new RuntimeException('Ovo izdanje dozvoljava isključivo environment=sandbox.');
        }
        if (rtrim((string) ($c['vsdc_url'] ?? ''), '/') !== 'https://vsdc.sandbox.suf.purs.gov.rs') {
            throw new RuntimeException('Dozvoljen je samo zvanični V-PFR sandbox endpoint za Srbiju.');
        }
        foreach (['pfx_path', 'ca_bundle'] as $key) {
            self::privateFile((string) ($c[$key] ?? ''));
        }
        if (!preg_match('/^[A-Z0-9]{8}$/D', (string) ($c['expected_uid'] ?? ''))
            || trim((string) ($c['tax_label'] ?? '')) === '' || mb_strlen($c['tax_label']) > 10
            || empty($c['pac']) || !isset($c['pfx_password']) || !is_array($c['payment_map'] ?? null)) {
            throw new RuntimeException('Popuni expected_uid, tax_label, pac, pfx_password i payment_map u privatnom config fajlu.');
        }
        if (preg_match('/[\r\n]/', (string) $c['pac'])) {
            throw new RuntimeException('PAK ne sme da sadrži novi red.');
        }
        $c['vsdc_url'] = rtrim($c['vsdc_url'], '/');
        $c['vat_rate'] = Checks::vatRate($c);
        return $c;
    }

    private static function privateFile(string $path): void
    {
        $real = realpath($path);
        if (!$real || !is_file($real) || !is_readable($real) || !str_starts_with($real, '/')) {
            throw new RuntimeException('Privatni fajl ne postoji ili PHP nema dozvolu za čitanje. Koristi apsolutnu putanju.');
        }
        foreach ([defined('ROOTDIR') ? ROOTDIR : '', $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
            $r = $root !== '' ? realpath($root) : false;
            if ($r && ($real === $r || str_starts_with($real, rtrim($r, '/') . '/'))) {
                throw new RuntimeException('Konfiguracija, sertifikat i CA bundle moraju biti izvan WHMCS i javnog web direktorijuma.');
            }
        }
        if ((fileperms($real) & 0007) !== 0) {
            throw new RuntimeException('Privatni fajl ima dozvole za ostale korisnike. Postavi chmod 600 ili 640.');
        }
    }
}

final class Vsdc implements Transport
{
    private array $cert;

    public function __construct(private array $config)
    {
        if (!extension_loaded('curl') || !extension_loaded('openssl') || !defined('CURLOPT_SSLCERT_BLOB')) {
            throw new RuntimeException('Potrebni su PHP cURL/OpenSSL i libcurl sa podrškom za certificate blobs (>=7.71).');
        }
        $raw = file_get_contents($config['pfx_path']);
        $cert = [];
        if ($raw === false || !openssl_pkcs12_read($raw, $cert, $config['pfx_password']) || empty($cert['pkey'])) {
            throw new RuntimeException('PFX nije moguće otvoriti. Proveri lozinku i OpenSSL podršku za njegov format.');
        }
        $meta = openssl_x509_parse($cert['cert']);
        if (!$meta || ($meta['validTo_time_t'] ?? 0) <= time() || ($meta['validFrom_time_t'] ?? PHP_INT_MAX) > time()) {
            throw new RuntimeException('Sertifikat nije vremenski važeći.');
        }
        $this->cert = $cert;
    }

    public function request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array
    {
        if (!in_array($method, ['GET', 'POST'], true)
            || !preg_match('~^/api/v3/(?:status|invoices(?:/[a-f0-9]{32})?)$~D', $path)) {
            throw new RuntimeException('Nedozvoljena API operacija.');
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'PAC: ' . $this->config['pac']];
        if ($requestId !== null) {
            if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
                throw new RuntimeException('Neispravan RequestId.');
            }
            $headers[] = 'RequestId: ' . $requestId;
        }
        $pem = $this->cert['cert'] . implode('', $this->cert['extracerts'] ?? []);
        $ch = curl_init($this->config['vsdc_url'] . $path);
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => $this->config['ca_bundle'],
            CURLOPT_SSLCERTTYPE => 'PEM', CURLOPT_SSLCERT_BLOB => $pem,
            CURLOPT_SSLKEYTYPE => 'PEM', CURLOPT_SSLKEY_BLOB => $this->cert['pkey'],
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 8 * 1024 * 1024) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        }
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false || $errno !== 0) {
            // Never log request headers, PAK, passwords or private key material.
            throw new RuntimeException('V-PFR komunikacija nije završena; cURL kod ' . $errno . '.');
        }
        $data = json_decode($body, true);
        return ['code' => $code, 'data' => $data];
    }
}

final class Store implements Repository
{
    public const TABLE = 'mod_onetfiskal_invoices';
    public const GUARD = 'mod_onetfiskal_guard';

    public function __construct(private string $credential = '') {}

    public static function install(): void
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            Capsule::schema()->create(self::TABLE, static function ($t): void {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->unsignedInteger('invoice_id')->unique();
                $t->string('state', 16)->default('review')->index();
                $t->string('request_id', 32)->nullable()->unique();
                $t->string('credential', 64)->nullable();
                $t->longText('payload')->nullable();
                $t->longText('response')->nullable();
                $t->text('message')->nullable();
                $t->unsignedInteger('admin_id')->nullable();
                $t->dateTime('created_at');
                $t->dateTime('updated_at');
            });
        }
        if (!Capsule::schema()->hasTable(self::GUARD)) {
            Capsule::schema()->create(self::GUARD, static function ($t): void {
                $t->engine = 'InnoDB';
                $t->unsignedInteger('id')->primary();
                $t->unsignedInteger('attempt_id')->nullable();
            });
        }
        Capsule::table(self::GUARD)->insertOrIgnore(['id' => 1, 'attempt_id' => null]);
    }

    public function find(int $invoiceId): ?array
    {
        $r = Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->first();
        return $r ? (array) $r : null;
    }

    public function queue(int $invoiceId): void
    {
        Capsule::table(self::TABLE)->insertOrIgnore(['invoice_id' => $invoiceId, 'state' => 'review',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function claim(int $invoiceId, array $payload, int $adminId): array
    {
        $this->queue($invoiceId);
        return Capsule::connection()->transaction(function () use ($invoiceId, $payload, $adminId): array {
            $guard = Capsule::table(self::GUARD)->where('id', 1)->lockForUpdate()->first();
            if (!$guard || $guard->attempt_id !== null) {
                throw new RuntimeException('Drugi zahtev čeka potvrdu ishoda. Prvo ga proveri; novo izdavanje je zaustavljeno.');
            }
            $row = Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->lockForUpdate()->first();
            if (!$row || $row->state !== 'review') {
                throw new RuntimeException('Ova faktura već ima zahtev. Dupli klik je zaustavljen.');
            }
            $requestId = bin2hex(random_bytes(16));
            Capsule::table(self::TABLE)->where('id', $row->id)->update([
                'request_id' => $requestId, 'state' => 'sending', 'credential' => $this->credential,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'admin_id' => $adminId, 'message' => 'Zahtev je sačuvan pre slanja.', 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            Capsule::table(self::GUARD)->where('id', 1)->update(['attempt_id' => $row->id]);
            return $this->find($invoiceId);
        });
    }

    public function finish(int $id, string $state, mixed $response, string $message, bool $release): void
    {
        Capsule::connection()->transaction(function () use ($id, $state, $response, $message, $release): void {
            Capsule::table(self::GUARD)->where('id', 1)->lockForUpdate()->first();
            $row = Capsule::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            if (!$row || $row->state === 'issued') {
                return;
            }
            Capsule::table(self::TABLE)->where('id', $id)->update([
                'state' => $state, 'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'message' => $message, 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if ($release) {
                Capsule::table(self::GUARD)->where('id', 1)->where('attempt_id', $id)->update(['attempt_id' => null]);
            }
        });
    }

    public static function snapshot(int $invoiceId): array
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            throw new RuntimeException('WHMCS faktura ne postoji.');
        }
        $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
        if (!$client) {
            throw new RuntimeException('Klijent ne postoji.');
        }
        return [
            'invoice' => (array) $invoice, 'client' => (array) $client,
            'currency' => (string) Capsule::table('tblcurrencies')->where('id', $client->currency)->value('code'),
            'tax_type' => (string) Capsule::table('tblconfiguration')->where('setting', 'TaxType')->value('value'),
            'items' => array_map(static fn($x): array => (array) $x,
                Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->orderBy('id')->get()->all()),
            'transactions' => array_map(static fn($x): array => (array) $x,
                Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->orderBy('id')->get()->all()),
        ];
    }
}
