<?php
declare(strict_types=1);
use Onet\Fiskal\Ui;

$tests['UI financial summaries use Serbian decimals and escape untrusted labels'] = function (): void {
    equal('1.234.567,89', Ui::money(123456789)); equal('0,01', Ui::value('0.01'));
    equal('—', Ui::value('not-a-number'));
    $html = Ui::heading('<script>alert(1)</script>') . Ui::metrics([['label' => '<img>', 'value' => '1.190,00']]);
    equal(false, str_contains($html, '<script>')); equal(false, str_contains($html, '<img>'));
    equal(true, str_contains($html, '&lt;script&gt;'));
};
$tests['UI advance group resumes its existing draft before offering another financial action'] = function () use ($config): void {
    resetDatabase(); $_SESSION = ['adminid' => 1, 'onetfiskal_csrf' => str_repeat('a', 64)];
    $store = new \Onet\Fiskal\AdvanceStore($config);
    $draft = $store->create(advanceInput(), 1);
    ob_start(); try { \Onet\Fiskal\AdvanceAdmin::render($config, 0, (int) $draft['group_id']); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'Nastavi postojeći nacrt'));
    equal(false, str_contains($html, 'name="kind" value="close"'));
    equal(false, str_contains($html, 'name="kind" value="return"'));
    equal('review', $store->find((int) $draft['invoice_id'])['state']);
};
$tests['UI separates return and settlement and shows only final continuation after confirmed closing'] = function () use ($config): void {
    resetDatabase(); $_SESSION = ['adminid' => 1]; $api = new AdvanceApi();
    $payment = openAdvance($config, $api); $store = new \Onet\Fiskal\AdvanceStore($config); $group = (int) $payment['group_id'];
    ob_start(); try { \Onet\Fiskal\AdvanceAdmin::render($config, 0, $group); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'Usluga je izvršena')); equal(true, str_contains($html, 'Vrati avans kupcu'));
    equal(true, str_contains($html, 'name="kind" value="close"')); equal(true, str_contains($html, 'name="kind" value="return"'));
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190.00', 'topup_method' => '4'], 1);
    $close = advanceIssue($store, $api, $config, $close);
    ob_start(); try { \Onet\Fiskal\AdvanceAdmin::render($config, (int) $close['invoice_id'], 0); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'name="kind" value="final"'));
    equal(false, str_contains($html, 'value="advance_money"'));
    equal(false, str_contains($html, 'value="advance_issue"'));
    equal(2, $api->posts); // UI rendering cannot issue the final document.
};
$tests['UI unknown refund offers reconciliation without an issuing or banking action'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config);
    $row = $store->draft('invoice', 48, refundInput('0.500'), 1); $api = new RefundApi(); $api->timeout = true;
    (new \Onet\Fiskal\Service($store, $api, $config))->submit((int) $row['invoice_id'], json_decode($row['payload'], true), 1);
    ob_start(); try { \Onet\Fiskal\RefundAdmin::render($config, (int) $row['invoice_id'], '', 0); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'value="refund_reconcile"'));
    equal(false, str_contains($html, 'value="refund_issue"')); equal(false, str_contains($html, 'value="refund_money"'));
    equal(true, str_contains($html, 'of-step--blocked')); equal(1, $api->posts);
};
$tests['UI issued refund presents separate optional money evidence and protected original exports'] = function () use ($config, $snapshot): void {
    refundParent($config, $snapshot); $store = new \Onet\Fiskal\RefundStore($config);
    $row = $store->draft('invoice', 48, refundInput(), 1); $api = new RefundApi();
    (new \Onet\Fiskal\Service($store, $api, $config))->submit((int) $row['invoice_id'], json_decode($row['payload'], true), 1);
    ob_start(); try { \Onet\Fiskal\RefundAdmin::render($config, (int) $row['invoice_id'], '', 0); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'Odvojena evidencija isplate'));
    equal(true, str_contains($html, 'source=refund&amp;id='));
    equal(true, str_contains($html, 'name="confirmed_money" value="yes" required'));
    equal(false, str_contains($html, 'value="refund_issue"'));
    equal(null, $store->find((int) $row['invoice_id'])['money_recorded_at']); equal(1, $api->posts);
};

$tests['WHMCS invoice tags batch-read exact assigned numbers without inventing missing numbers'] = function (): void {
    resetDatabase();
    \WHMCS\Database\Capsule::schema()->create('tblinvoices', function ($t): void { $t->integer('id'); $t->string('invoicenum')->nullable(); });
    \WHMCS\Database\Capsule::table('tblinvoices')->insert([
        ['id' => 48, 'invoicenum' => 'UTG-2026-048'], ['id' => 40, 'invoicenum' => ''],
        ['id' => 36, 'invoicenum' => '<img src=x onerror=alert(1)>'], ['id' => 44, 'invoicenum' => null],
    ]);
    $db = \WHMCS\Database\Capsule::connection(); $db->enableQueryLog(); $db->flushQueryLog();
    $numbers = Ui::invoiceNumbers([48, 40, 36, 44, 999, 48]);
    equal(1, count($db->getQueryLog())); $db->disableQueryLog();
    equal('UTG-2026-048', $numbers[48]); equal(false, isset($numbers[40])); equal(false, isset($numbers[44])); equal(false, isset($numbers[999]));
    equal('', Ui::invoiceTag(null)); equal('', Ui::invoiceTag('  '));
    $html = Ui::invoiceIdentity(36, $numbers[36]);
    equal(false, str_contains($html, '<img')); equal(true, str_contains($html, '&lt;img'));
    equal(4, \WHMCS\Database\Capsule::table('tblinvoices')->count());
};
$tests['invoice list detail journal and refund show linked WHMCS number without changing fiscal evidence'] = function () use ($config, $snapshot): void {
    $original = refundParent($config, $snapshot); accessFixtures();
    \WHMCS\Database\Capsule::schema()->create('tblinvoices', function ($t): void { $t->integer('id'); $t->string('invoicenum')->nullable(); });
    \WHMCS\Database\Capsule::table('tblinvoices')->insert(['id' => 48, 'invoicenum' => 'UTG-2026-048']);
    $_SESSION = ['adminid' => 1]; $_POST = []; $_GET = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start(); try { \Onet\Fiskal\Admin::render([]); $list = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($list, 'of-invoice-identity')); equal(true, str_contains($list, 'UTG-2026-048'));
    $_GET = ['invoiceid' => 48];
    ob_start(); try { \Onet\Fiskal\Admin::render([]); $detail = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($detail, 'UTG-2026-048')); equal(true, str_contains($detail, 'of-heading-tags'));
    ob_start(); try { \Onet\Fiskal\Journal::render(['source' => 'invoice']); $journal = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($journal, 'UTG-2026-048'));
    $refund = (new \Onet\Fiskal\RefundStore($config))->draft('invoice', 48, refundInput('0.500'), 1);
    ob_start(); try { \Onet\Fiskal\RefundAdmin::render($config, (int) $refund['invoice_id'], '', 0); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'UTG-2026-048')); equal(true, str_contains($html, 'name="refund_hash"'));
    equal($original['response'], (new \Onet\Fiskal\Store())->find(48)['response']);
    equal(null, \WHMCS\Database\Capsule::table(\Onet\Fiskal\Store::GUARD)->value('attempt_id')); $_GET = [];
};

$tests['work overview prioritizes unresolved requests across sources and keeps explicit empty filters'] = function () use ($config, $snapshot): void {
    resetDatabase(); $api = new DbApi(); $api->timeout = true;
    (new \Onet\Fiskal\Service(new \Onet\Fiskal\Store(hash_file('sha256', $config['pfx_path'])), $api, $config))->issue(48, $snapshot, 1);
    (new \Onet\Fiskal\Store(hash_file('sha256', $config['pfx_path']), 'synthetic'))->createSynthetic($snapshot, bin2hex(random_bytes(16)), 1);
    $before = \WHMCS\Database\Capsule::table(\Onet\Fiskal\Store::GUARD)->first();
    $data = \Onet\Fiskal\Workbench::data();
    equal('attention', $data['filter']); equal(1, $data['counts']['attention']); equal(1, $data['counts']['review']); equal(2, $data['counts']['all']);
    equal('unknown', $data['rows'][0]['state']); equal('invoice', $data['rows'][0]['source']);
    $all = \Onet\Fiskal\Workbench::data('all'); equal(2, count($all['rows'])); equal('unknown', $all['rows'][0]['state']);
    $issued = \Onet\Fiskal\Workbench::data('issued'); equal('issued', $issued['filter']); equal([], $issued['rows']);
    equal((array) $before, (array) \WHMCS\Database\Capsule::table(\Onet\Fiskal\Store::GUARD)->first()); equal(1, $api->posts);
};
$tests['work overview surfaces an internal closing until its final draft is prepared'] = function () use ($config): void {
    resetDatabase(); $api = new AdvanceApi(); $payment = openAdvance($config, $api);
    $store = new \Onet\Fiskal\AdvanceStore($config); $group = (int) $payment['group_id'];
    $close = $store->draft($group, 'close', bin2hex(random_bytes(16)), ['final_amount' => '1190.00', 'topup_method' => '4'], 1);
    advanceIssue($store, $api, $config, $close);
    $data = \Onet\Fiskal\Workbench::data(); equal(1, $data['closing_count']); equal($group, (int) $data['closings'][0]->group_id);
    $_GET = []; ob_start(); try { \Onet\Fiskal\Workbench::render(''); $html = ob_get_contents(); } finally { ob_end_clean(); }
    equal(true, str_contains($html, 'Avansni postupak nije završen')); equal(true, str_contains($html, 'Nastavi porudžbinu #'));
    equal(false, str_contains($html, 'value="advance_issue"'));
    $final = $store->draft($group, 'final', bin2hex(random_bytes(16)), [], 1);
    $data = \Onet\Fiskal\Workbench::data(); equal(0, $data['closing_count']); equal(1, $data['counts']['review']); equal(2, $api->posts);
};
$tests['work overview never presents an unconfirmed response amount as the trusted requested amount'] = function (): void {
    $row = ['state' => 'unknown', 'response' => '{"totalAmount":9999}',
        'payload' => '{"items":[{"totalAmount":1190}]}', 'snapshot' => null];
    equal('1.190,00', \Onet\Fiskal\Workbench::amount($row));
    $row['state'] = 'issued'; equal('9.999,00', \Onet\Fiskal\Workbench::amount($row));
    $row['state'] = 'unknown'; $row['payload'] = null; equal('—', \Onet\Fiskal\Workbench::amount($row));
};
