<?php
declare(strict_types=1);

// Synthetic X.509 certificates only; private keys remain in memory and are never written.
$dir = sys_get_temp_dir() . '/onet-ca-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'ONET synthetic CA test'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 1);
    if (!$cert || !openssl_x509_export($cert, $pem)) { throw new RuntimeException('Cannot create test certificate'); }
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
    file_put_contents($dir . '/root.cer', $der);
    file_put_contents($dir . '/issuer.cer', $pem);
    $output = $dir . '/bundle.pem';
    $run = static function (string $target) use ($dir): int {
        $process = proc_open([PHP_BINARY, '-n', __DIR__ . '/../config/build-ca-bundle.php',
            $dir . '/root.cer', $dir . '/issuer.cer', $target],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start CA helper'); }
        fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        return proc_close($process);
    };
    if ($run($output) !== 0 || substr_count(file_get_contents($output), '-----BEGIN CERTIFICATE-----') < 2) {
        throw new RuntimeException('PEM/DER conversion failed');
    }
    if ((fileperms($output) & 0077) !== 0) { throw new RuntimeException('CA bundle permissions are too broad'); }
    $hash = hash_file('sha256', $output);
    if ($run($output) === 0 || hash_file('sha256', $output) !== $hash) { throw new RuntimeException('Existing bundle was overwritten'); }
    file_put_contents($dir . '/root.cer', 'not a certificate');
    if ($run($dir . '/invalid.pem') === 0 || file_exists($dir . '/invalid.pem')) {
        throw new RuntimeException('Invalid certificate was accepted');
    }
    echo "3 CA helper checks passed: PEM/DER conversion and private permissions; no overwrite; invalid certificate rejected.\n";
} finally {
    foreach (glob($dir . '/*') as $path) { unlink($path); }
    rmdir($dir);
}
