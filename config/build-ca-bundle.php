<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if ($argc !== 4) {
    fwrite(STDERR, "Upotreba: php build-ca-bundle.php RCA.cer ICA.cer /privatna/putanja/sandbox-ca-bundle.pem\n");
    exit(1);
}
try {
    if (file_exists($argv[3])) { throw new RuntimeException('Izlazni fajl već postoji. Izaberi novu putanju.'); }
    $bundle = '';
    $systemCaAdded = false;
    foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt'] as $systemCa) {
        if (is_readable($systemCa)) {
            $systemPem = file_get_contents($systemCa);
            if ($systemPem !== false && str_contains($systemPem, '-----BEGIN CERTIFICATE-----')) {
                $bundle = rtrim($systemPem) . "\n";
                $systemCaAdded = true;
                break;
            }
        }
    }
    foreach ([$argv[1], $argv[2]] as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) { throw new RuntimeException('Ulazni fajl nije čitljiv.'); }
        $pem = str_contains($raw, '-----BEGIN CERTIFICATE-----') ? $raw
            : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($raw), 64, "\n") . "-----END CERTIFICATE-----\n";
        $cert = openssl_x509_read($pem);
        if ($cert === false || !openssl_x509_export($cert, $normalized)) {
            throw new RuntimeException('Fajl nije važeći X.509 sertifikat.');
        }
        $meta = openssl_x509_parse($cert);
        if (!$meta || ($meta['validTo_time_t'] ?? 0) <= time()) { throw new RuntimeException('CA sertifikat je istekao.'); }
        $bundle .= $normalized;
    }
    $oldMask = umask(0077);
    try {
        $out = fopen($argv[3], 'x');
        if (!$out) { throw new RuntimeException('Izlazni fajl nije moguće napraviti.'); }
        try {
            if (fwrite($out, $bundle) !== strlen($bundle)) { throw new RuntimeException('Nepotpun upis CA bundle-a.'); }
        } finally { fclose($out); }
    } finally { umask($oldMask); }
    echo "CA bundle napravljen. Sistemski javni CA: " . ($systemCaAdded ? 'dodati' : 'nisu pronađeni') . ".\n";
    echo "Koristi samo originalne CA sertifikate Poreske uprave.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
}
