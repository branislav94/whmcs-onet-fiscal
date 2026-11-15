<?php
declare(strict_types=1);
use Onet\Fiskal\Delivery;

function deliveryCustomer(string $email = 'buyer@example.test', string $number = 'UTG-2026-048'): void {
    \WHMCS\Database\Capsule::schema()->create('tblinvoices', function ($t): void { $t->integer('id'); $t->integer('userid'); $t->string('invoicenum'); });
    \WHMCS\Database\Capsule::schema()->create('tblclients', function ($t): void { $t->integer('id'); $t->string('email'); });
    \WHMCS\Database\Capsule::table('tblinvoices')->insert(['id' => 48, 'userid' => 12, 'invoicenum' => $number]);
    \WHMCS\Database\Capsule::table('tblclients')->insert([['id' => 12, 'email' => $email], ['id' => 99, 'email' => 'other@example.test']]);
}
$tests['delivery preview requires addon ACL and only accepts confirmed valid source IDs'] = function (): void {
    resetDatabase(); accessFixtures(); evidence('invoice', 48);
    foreach ([[], ['adminid' => 2], ['adminid' => 3]] as $session) { $_SESSION = $session; rejected(fn() => Delivery::load(['source' => 'invoice', 'documentid' => 48])); }
    $_SESSION = ['adminid' => 1];
    foreach ([['source' => 'all', 'documentid' => 48], ['source' => 'invoice', 'documentid' => '../48'], ['source' => ['invoice'], 'documentid' => 48], ['source' => 'invoice', 'documentid' => 999]] as $bad) { rejected(fn() => Delivery::load($bad)); }
    foreach (['review', 'sending', 'unknown', 'rejected'] as $state) {
        \WHMCS\Database\Capsule::table(\Onet\Fiskal\Store::TABLE)->where('invoice_id', 48)->update(['state' => $state]);
        rejected(fn() => Delivery::load(['source' => 'invoice', 'documentid' => 48]));
    }
};
$tests['delivery preview resolves exact WHMCS recipient and preserves attachments evidence and fiscal lock'] = function (): void {
    resetDatabase(); accessFixtures(); evidence('invoice', 48); deliveryCustomer(); $_SESSION = ['adminid' => 1];
    $before = (new \Onet\Fiskal\Store())->find(48);
    \WHMCS\Database\Capsule::table(\Onet\Fiskal\Store::GUARD)->where('id', 1)->update(['source' => 'invoice', 'attempt_id' => 99]);
    $p = Delivery::load(['source' => 'invoice', 'documentid' => 48, 'recipient' => 'other@example.test', 'client_id' => 99]);
    equal('buyer@example.test', $p['recipient']); equal(12, $p['client_id']); equal(true, $p['recipient_valid']); equal(false, $p['send_enabled']);
    equal(true, str_contains($p['subject'], '[SANDBOX]')); equal(true, str_contains($p['subject'], 'UTG-2026-048'));
    foreach ($p['attachments'] as $a) {
        $format = str_ends_with($a['name'], '.html') ? 'html' : 'txt';
        equal(\Onet\Fiskal\ReceiptExport::load(['source' => 'invoice', 'id' => 48, 'format' => $format])['body'], $a['body']);
        equal(hash('sha256', $a['body']), $a['sha256']);
    }
    equal(false, str_contains($p['html'], 'addonmodules.php')); equal(false, str_contains($p['html'], 'receipt.php'));
    equal($before, (new \Onet\Fiskal\Store())->find(48)); equal(99, (int) \WHMCS\Database\Capsule::table(\Onet\Fiskal\Store::GUARD)->value('attempt_id'));
};
$tests['refund and copy preview inherit recipient from the original WHMCS invoice'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); accessFixtures(); deliveryCustomer(); $_SESSION = ['adminid' => 1];
    $store = new \Onet\Fiskal\RefundStore($config); $row = $store->draft('invoice', 48, refundInput('0.500'), 1);
    (new \Onet\Fiskal\Service($store, new RefundApi(), $config))->submit((int) $row['invoice_id'], json_decode($row['payload'], true), 1);
    $r = Delivery::load(['source' => 'refund', 'documentid' => $row['invoice_id']]); equal(48, $r['invoice_id']); equal('buyer@example.test', $r['recipient']);
    equal(true, str_contains($r['text'], 'nije potvrda bankarske isplate'));
    $copies = new \Onet\Fiskal\CopyStore($config); $copy = $copies->draft('refund', (int) $row['invoice_id'], bin2hex(random_bytes(16)), 1);
    (new \Onet\Fiskal\Service($copies, new CopyApi(), $config))->submit((int) $copy['invoice_id'], json_decode($copy['payload'], true), 1);
    $p = Delivery::load(['source' => 'copy', 'documentid' => $copy['invoice_id']]); equal(48, $p['invoice_id']); equal('buyer@example.test', $p['recipient']);
    equal(true, str_contains($p['subject'], 'Kopija')); equal(false, $p['send_enabled']);
};
$tests['internal advance closing and its copy cannot enter customer delivery preview'] = function () use ($config): void {
    resetDatabase(); accessFixtures(); $_SESSION = ['adminid' => 1]; $api = new AdvanceApi(); $payment = openAdvance($config, $api);
    $store = new \Onet\Fiskal\AdvanceStore($config); $close = $store->draft((int) $payment['group_id'], 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190.00', 'topup_method' => '4'], 1);
    advanceIssue($store, $api, $config, $close);
    rejected(fn() => Delivery::load(['source' => 'advance', 'documentid' => $close['invoice_id']])); equal('', Delivery::link('advance', (int) $close['invoice_id']));
    $copies = new \Onet\Fiskal\CopyStore($config); $copy = $copies->draft('advance', (int) $close['invoice_id'], bin2hex(random_bytes(16)), 1);
    (new \Onet\Fiskal\Service($copies, new CopyApi(), $config))->submit((int) $copy['invoice_id'], json_decode($copy['payload'], true), 1);
    rejected(fn() => Delivery::load(['source' => 'copy', 'documentid' => $copy['invoice_id']])); equal('', Delivery::link('copy', (int) $copy['invoice_id']));
    $final = $store->draft((int) $payment['group_id'], 'final', bin2hex(random_bytes(16)), [], 1); advanceIssue($store, $api, $config, $final);
    $p = Delivery::load(['source' => 'advance', 'documentid' => $final['invoice_id']]); equal('test@example.invalid', $p['recipient']); equal(true, $p['demo_recipient']);
    equal(true, str_contains($p['attachments'][0]['body'], 'Plaćeno avansno')); equal(false, $p['send_enabled']);
};
$tests['delivery preview escapes metadata rejects injected emails and warns on missing QR'] = function (): void {
    resetDatabase(); accessFixtures(); evidence('invoice', 48); deliveryCustomer("buyer@example.test\r\nBcc:other@example.test", '<img src=x onerror=alert(1)>'); $_SESSION = ['adminid' => 1];
    $response = receiptFixture(48); unset($response['verificationQRCode']);
    \WHMCS\Database\Capsule::table(\Onet\Fiskal\Store::TABLE)->where('invoice_id', 48)->update(['response' => json_encode($response)]);
    $p = Delivery::load(['source' => 'invoice', 'documentid' => 48]); equal(false, $p['recipient_valid']); equal(false, $p['qr_valid']); equal(false, $p['send_enabled']);
    equal(false, str_contains($p['html'], '<img src=x')); equal(true, str_contains($p['html'], '&lt;img'));
    ob_start(); try { Delivery::render(['source' => 'invoice', 'documentid' => 48]); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'Slanje je isključeno')); equal(false, str_contains($html, '<img src=x'));
    equal(false, str_contains($html, 'value="issue"')); equal(false, str_contains($html, 'name="recipient"'));
};
