<?php
declare(strict_types=1);

namespace Onet\Fiskal;

use RuntimeException;

final class Synthetic
{
    /** Build a fictitious snapshot only; this function never reads or writes WHMCS billing tables. */
    public static function snapshot(array $input, array $config): array
    {
        if (($config['environment'] ?? '') !== 'sandbox'
            || rtrim((string) ($config['vsdc_url'] ?? ''), '/') !== 'https://vsdc.sandbox.suf.purs.gov.rs') {
            throw new RuntimeException('Zaseban test dozvoljen je samo za zvanični sandbox.');
        }
        $scenario = (string) ($input['scenario'] ?? 'hosting');
        if (!in_array($scenario, ['hosting', 'domain'], true)) {
            throw new RuntimeException('Izaberi test hostinga ili domena.');
        }
        $net = Money::cents($input['net_amount'] ?? '');
        if ($net < 1 || $net > 100000000) {
            throw new RuntimeException('Test osnovica mora biti između 0.01 i 1000000.00 RSD.');
        }
        $gateway = (string) ($input['gateway'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $gateway)) {
            throw new RuntimeException('Izaberi gateway iz konfiguracije.');
        }
        $rate = Checks::vatRate($config);
        $tax = intdiv($net * $rate + 50, 100);
        $money = static fn(int $n): string => intdiv($n, 100) . '.' . str_pad((string) ($n % 100), 2, '0', STR_PAD_LEFT);
        $snapshot = [
            'currency' => 'RSD', 'tax_type' => 'Exclusive',
            'client' => ['country' => 'RS', 'companyname' => '', 'tax_id' => ''],
            'invoice' => ['status' => 'Paid', 'credit' => '0.00', 'subtotal' => $money($net),
                'tax' => $money($tax), 'tax2' => '0.00', 'taxrate' => (string) $rate,
                'taxrate2' => '0', 'total' => $money($net + $tax)],
            'items' => [['description' => 'SANDBOX TEST — ' . ($scenario === 'hosting' ? 'hosting' : 'domen') . ' — izmišljeni promet',
                'amount' => $money($net), 'taxed' => 1]],
            'transactions' => [['amountin' => $money($net + $tax), 'amountout' => '0.00', 'gateway' => $gateway]],
        ];
        Builder::build($snapshot, $config, 'SANDBOX preview');
        return $snapshot;
    }
}

final class Diagnostics
{
    public static function curl(int $code): string
    {
        return match ($code) {
            6 => 'DNS ne može da razreši V-PFR domen. Proveri resolver na WHMCS serveru.',
            7 => 'Veza prema V-PFR portu 443 nije uspostavljena. Proveri izlazni firewall i dostupnost servisa.',
            28 => 'V-PFR nije odgovorio na vreme. Ako je račun slat, proveri njegov ishod pre novog izdavanja.',
            35 => 'TLS rukovanje nije uspelo. Proveri CA lanac i klijentski sertifikat.',
            58 => 'Klijentski sertifikat ili privatni ključ nije moguće upotrebiti. Proveri PFX i podršku OpenSSL-a.',
            60 => 'TLS sertifikat V-PFR servera nije potvrđen. Koristi CA bundle sa sistemskim javnim CA i potrebnim sandbox CA sertifikatima.',
            77 => 'CA bundle nije moguće učitati. Proveri PEM format, putanju i dozvole PHP korisnika.',
            default => 'V-PFR komunikacija nije završena. Proveri mrežu i konfiguraciju sertifikata.',
        } . ' (cURL ' . $code . ')';
    }
}

final class PaymentLabels
{
    public static function name(int $code): string
    {
        return [0 => 'Drugo bezgotovinsko plaćanje', 1 => 'Gotovina', 2 => 'Platna kartica',
            3 => 'Ček', 4 => 'Prenos na račun', 5 => 'Vaučer', 6 => 'Instant plaćanje'][$code] ?? 'Nepoznat način';
    }
}
