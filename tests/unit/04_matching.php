<?php
/** Tests: transaction matching engine (exact, tolerance, card filter, duplicates, reuse). */
declare(strict_types=1);

$T = $GLOBALS['T'];
$card = Card::find($T['card_id']);
$device = $T['device'];

function makeTx(array $over = []): array
{
    return array_merge([
        'bank'        => 'بانک ملت',
        'sender'      => 'Mellat',
        'amount_rial' => 1000000,
        'occurred_at' => DB::now(),
        'dest_last4'  => '0019',
        'reference'   => '111222333',
        'raw_text'    => 'واریز مبلغ 1,000,000 ريال به کارت 6219-86••-••••-0019 کد رهگیری: 111222333 ' . bin2hex(random_bytes(3)),
        'raw_hash'    => null,
    ], $over);
}

function freshPayment(array $T, array $card, int $rial, array $over = []): array
{
    return Payment::create($T['store_id'], $card, $over['order'] ?? ('ORD-' . bin2hex(random_bytes(3))), '', $rial, 'IRR', $rial, $over['opts'] ?? []);
}

section('Matching: exact amount');
$p = freshPayment($T, $card, 1000000);
Setting::set('unique_amount', '0'); // exact amounts for deterministic tests
$res = Matcher::process(makeTx(), $device);
Setting::set('unique_amount', '1');
eq('matched + confirmed', 'matched', $res['result']);
eq('payment paid', 'paid', $res['payment']['status']);
eq('transaction confirmed', 'confirmed', $res['transaction']['status']);

section('Matching: tolerance');
Setting::set('match_mode', 'tolerance');
Setting::set('payment_tolerance_rial', '2000');
$p = freshPayment($T, $card, 500000);
$res = Matcher::process(makeTx(['amount_rial' => 500001]), $device); // +1 rial off
eq('within tolerance matched', 'matched', $res['result']);

$p = freshPayment($T, $card, 500000);
$res = Matcher::process(makeTx(['amount_rial' => 505000]), $device); // +5000 rial off
eq('outside tolerance unmatched', 'unmatched', $res['result']);

Setting::set('match_mode', 'exact');
$p = freshPayment($T, $card, 500000);
$res = Matcher::process(makeTx(['amount_rial' => 500001]), $device);
eq('exact mode: 1-rial difference unmatched', 'unmatched', $res['result']);
Setting::set('match_mode', 'tolerance');

section('Matching: destination card filter');
$p = freshPayment($T, $card, 800000);
$res = Matcher::process(makeTx(['amount_rial' => 800000, 'dest_last4' => '9999']), $device);
eq('wrong destination card → unmatched', 'unmatched', $res['result']);
$p = freshPayment($T, $card, 800000);
$res = Matcher::process(makeTx(['amount_rial' => 800000, 'dest_last4' => '']), $device);
eq('SMS without card info still matches (amount+time)', 'matched', $res['result']);

section('Matching: time window');
$p = freshPayment($T, $card, 900000);
$res = Matcher::process(makeTx(['amount_rial' => 900000, 'occurred_at' => gmdate('Y-m-d H:i:s', time() - 7200)]), $device);
eq('transaction far in the past → unmatched', 'unmatched', $res['result']);

$p = freshPayment($T, $card, 900000);
$res = Matcher::process(makeTx(['amount_rial' => 900000, 'occurred_at' => gmdate('Y-m-d H:i:s', time() - 600)]), $device);
eq('transaction 10 minutes ago → matched', 'matched', $res['result']);

section('Duplicates & single-use');
$p = freshPayment($T, $card, 1200000);
$tx = makeTx(['amount_rial' => 1200000]);
$res1 = Matcher::process($tx, $device);
eq('first upload matched', 'matched', $res1['result']);
$res2 = Matcher::process($tx, $device);
eq('identical SMS re-upload → duplicate', 'duplicate', $res2['result']);

$p2 = freshPayment($T, $card, 1300000);
$txB = makeTx(['amount_rial' => 1200000, 'raw_text' => $tx['raw_text'] . ' (retry)']);
$res3 = Matcher::process($txB, $device);
eq('used transaction cannot pay a second order', 'unmatched', $res3['result']);
eq('original payment still paid exactly once', 'paid', Payment::find((int)$p['id'])['status']);

section('Auto-confirm off → manual confirmation');
Setting::set('auto_confirm', '0');
$p = freshPayment($T, $card, 2200000);
$res = Matcher::process(makeTx(['amount_rial' => 2200000]), $device);
eq('matched but pending admin', 'matched_pending', $res['result']);
eq('payment verifying', 'verifying', $res['payment']['status']);
eq('transaction matched', 'matched', $res['transaction']['status']);
t('manual confirm succeeds', Matcher::confirmManually((int)$res['payment']['id'], $T['store_id']));
eq('payment paid after manual confirm', 'paid', Payment::find((int)$res['payment']['id'])['status']);
Setting::set('auto_confirm', '1');

section('Manual attach of unmatched transaction');
$p = freshPayment($T, $card, 3300000);
$txC = makeTx(['amount_rial' => 3300000, 'dest_last4' => '9999']); // wrong card → unmatched
$res = Matcher::process($txC, $device);
eq('initially unmatched', 'unmatched', $res['result']);
t('admin can attach it manually', Matcher::attachManually((int)$res['transaction']['id'], (int)$p['id'], $T['store_id']));
eq('payment paid after attach', 'paid', Payment::find((int)$p['id'])['status']);

section('Test payments never mix with real ones');
Setting::set('test_mode', '1');
$pReal = freshPayment($T, $card, 4400000);
$res = Matcher::process(makeTx(['amount_rial' => 4400000]), $device, true); // simulated as TEST
Setting::set('test_mode', '0');
eq('test transaction does NOT consume real payment', 'unmatched', $res['result']);

// ─── دور ۱۰: در حالت تست، تراکنش واقعیِ دستگاه باید پرداخت تستی را بپردازد ───
// (این همان سناریوی «آزمون سرتاسری با پیامک واقعی» است — پیش از این، تراکنش
//  دستگاه همیشه is_test=0 بود و پرداخت تستی هرگز تطبیق نمی‌خورد)
Setting::set('test_mode', '1');
$pTest = freshPayment($T, $card, 5500000);
$pTest['is_test'] = 1;
$deviceId = (int) ($device['id'] ?? 0);
DB::update('payment_sessions', ['is_test' => 1], 'id = ?', [(int) $pTest['id']]);
$resReal = Matcher::process(makeTx(['amount_rial' => 5500000, 'reference' => 'R10-REAL-' . random_int(1000, 9999)]), $device, false);
Setting::set('test_mode', '0');
eq('REAL device transaction pays TEST payment in test mode', 'matched', $resReal['result']);

// ─── دور ۱۳: تأیید دستی «در هر حالتی» — حتی pending بدون تراکنش ───
Setting::set('auto_confirm', '1');
$pNoTxn = freshPayment($T, $card, 6600000);
t('manual confirm on pending (no txn) with auto_confirm ON', Matcher::confirmManually((int) $pNoTxn['id'], (int) $T['store_id']));
eq('no-txn payment paid after manual confirm', 'paid', Payment::find((int) $pNoTxn['id'])['status']);
$pDup = freshPayment($T, $card, 6600001);
Matcher::confirmManually((int) $pDup['id'], (int) $T['store_id']);
t('double manual confirm returns false (already paid)', !Matcher::confirmManually((int) $pDup['id'], (int) $T['store_id']));

// دلیل فارسی تطبیق‌نشدن (برای نمایش در اپ/پنل)
$resWhy = Matcher::process(makeTx(['amount_rial' => 4455000, 'reference' => 'R10-WHY-' . random_int(1000, 9999)]), $device, false);
t('unmatched carries human reason', $resWhy['result'] === 'unmatched' && mb_strlen((string) ($resWhy['reason'] ?? '')) > 10);
