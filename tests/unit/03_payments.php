<?php
/** Tests: payment session creation, limits, unique amounts, idempotency, expiration. */
declare(strict_types=1);

$T = $GLOBALS['T'];
$card = Card::find($T['card_id']);

section('Payment creation');
$p = Payment::create($T['store_id'], $card, '1001', 'wc_order_1001', 1000000, 'IRT', 100000, ['customer_name' => 'مشتری']);
eq('status pending', 'pending', $p['status']);
eq('base amount 1,000,000 rial (100,000 toman × 10)', 1000000, (int)$p['amount_base_rial']);
t('unique-amount suffix applied within [100,999]', (int)$p['amount_rial'] >= 1000100 && (int)$p['amount_rial'] <= 1000999);
t('token is 40 chars', strlen((string)$p['token']) === 40);
t('expiry in the future', strtotime((string)$p['expires_at']) > time());
t('min amount enforced (below 20,000 rial rejected)', (function () use ($T, $card): bool {
    try { Payment::create($T['store_id'], $card, 'x1', '', 5000, 'IRR', 5000); return false; }
    catch (DomainException) { return true; }
})());
Setting::set('max_amount_rial', '2000000');
t('max amount enforced', (function () use ($T, $card): bool {
    try { Payment::create($T['store_id'], $card, 'x2', '', 5000000, 'IRR', 5000000); return false; }
    catch (DomainException) { return true; }
})());
Setting::set('max_amount_rial', '500000000');

section('Idempotent creation / reuse');
$p2 = Payment::create($T['store_id'], $card, '1001', 'wc_order_1001', 1000000, 'IRT', 100000);
eq('same order+amount reuses open payment', (int)$p['id'], (int)$p2['id']);

$p3 = Payment::create($T['store_id'], $card, '1002', 'wc_order_1002', 2500000, 'IRR', 2500000);
t('different amount for same order id creates new payment', (int)$p3['id'] !== (int)$p['id']);

section('Unique amounts avoid collisions');
$amounts = [];
for ($i = 0; $i < 5; $i++) {
    $pp = Payment::create($T['store_id'], $card, 'U' . $i, '', 3000000, 'IRR', 3000000);
    $amounts[] = (int)$pp['amount_rial'];
}
t('no two open payments share the same payable amount', count($amounts) === count(array_unique($amounts)));

section('Expiration (lazy, no cron)');
DB::q('UPDATE payment_sessions SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $p3['id']]);
$refreshed = Payment::expireIfNeeded(Payment::find((int)$p3['id']));
eq('expired after deadline', 'expired', $refreshed['status']);
$tAfter = Payment::find((int)$p3['id']);
eq('expiration persisted', 'expired', (string)$tAfter['status']);
throws('cannot pay an expired payment', DomainException::class, static fn () => Payment::payWithTransaction((int)$p3['id'], 999999));
