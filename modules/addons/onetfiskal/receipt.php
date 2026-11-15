<?php
declare(strict_types=1);

// WHMCS bootstrap owns the session. Every request still checks the admin role and addon ACL.
require_once dirname(__DIR__, 3) . '/init.php';
require_once __DIR__ . '/bootstrap.php';

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        throw new RuntimeException('Dozvoljen je samo GET.', 405);
    }
    $export = \Onet\Fiskal\ReceiptExport::load($_GET);
    header('Content-Type: ' . match ($export['format']) { 'pdf' => 'application/pdf', 'html' => 'text/html; charset=UTF-8', default => 'text/plain; charset=UTF-8' });
    header('Content-Disposition: ' . ($export['download'] ? 'attachment' : 'inline') . '; filename="' . $export['filename'] . '"');
    echo $export['body'];
} catch (Throwable $e) {
    $code = in_array($e->getCode(), [400, 403, 404, 405, 422, 503], true) ? $e->getCode() : 500;
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $code === 500 ? 'Račun nije moguće otvoriti. Proveri evidenciju modula.' : $e->getMessage();
}
