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
