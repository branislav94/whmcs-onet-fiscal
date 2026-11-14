<?php
declare(strict_types=1);
if (!defined('WHMCS')) { http_response_code(403); exit; }
require_once __DIR__ . '/bootstrap.php';

function onetfiskal_config(): array
{
    return [
        'name' => 'ONET Fiskal — Sandbox', 'version' => '0.1.2', 'author' => 'UTG / ONET',
        'description' => 'Razvojni ESIR modul. PHP 8.3+, RSD, podesiva test VAT stopa 19/20%. Samo sandbox; nema produkcionog izdavanja.',
        'fields' => [
            'configpath' => ['FriendlyName' => 'Privatni config fajl', 'Type' => 'text', 'Size' => '70',
                'Description' => 'Apsolutna putanja izvan public_html i WHMCS direktorijuma.'],
        ],
    ];
}

function onetfiskal_activate(): array
{
    try {
        if (PHP_VERSION_ID < 80300) { throw new RuntimeException('Potreban je PHP 8.3 ili noviji.'); }
        foreach (['curl', 'openssl', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) { throw new RuntimeException('Nedostaje PHP ekstenzija: ' . $ext); }
        }
        \Onet\Fiskal\Store::install();
        return ['status' => 'success', 'description' => 'Sandbox modul aktiviran. Podesi privatnu konfiguraciju i Access Control.'];
    } catch (Throwable $e) {
        return ['status' => 'error', 'description' => $e->getMessage()];
    }
}

function onetfiskal_deactivate(): array
{
    return ['status' => 'success', 'description' => 'Modul deaktiviran. Evidencija i zaključavanje su sačuvani.'];
}

function onetfiskal_output(array $vars): void
{
    \Onet\Fiskal\Admin::render($vars);
}
