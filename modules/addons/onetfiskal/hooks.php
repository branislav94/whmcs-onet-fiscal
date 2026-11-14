<?php
declare(strict_types=1);
if (!defined('WHMCS')) { exit; }
require_once __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;
use Onet\Fiskal\Store;

// Hook only queues review; it NEVER calls the fiscal API or changes invoices.
add_hook('InvoicePaid', 1, static function (array $vars): void {
    try {
        if (!Capsule::table('tbladdonmodules')->where('module', 'onetfiskal')->exists()
            || !Capsule::schema()->hasTable(Store::TABLE)) { return; }
        (new Store())->queue((int) $vars['invoiceid']);
    } catch (Throwable) {
        if (function_exists('logActivity')) { logActivity('ONET Fiskal: nije uspelo dodavanje fakture u sandbox pregled.'); }
    }
});

add_hook('AdminInvoicesControlsOutput', 1, static function (array $vars): string {
    if (!\Onet\Fiskal\Admin::allowed()) { return ''; }
    $id = (int) ($vars['invoiceid'] ?? 0);
    return $id > 0 ? '<a class="btn btn-default" href="addonmodules.php?module=onetfiskal&amp;invoiceid=' . $id
        . '">ONET Fiskal — sandbox</a>' : '';
});
