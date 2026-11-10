<?php
declare(strict_types=1);
$GLOBALS['__wp_with_woocommerce'] = true;
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../../woocommerce/peakopay/peakopay.php';
do_action('plugins_loaded');

$GLOBALS['__hp_orders'][1001] = new WC_Order(1001);
$GLOBALS['__hp_orders'][1001]->update_meta_data('_peakopay_amount_rial', '2500000');

$payment = ['id' => 5, 'token' => 'tok_1001aaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'order_id' => '1001', 'amount_rial' => 2500000, 'status' => 'paid', 'test' => false];
$txn = ['id' => 7, 'amount_rial' => 2500000, 'reference' => 'REF123456', 'bank' => 'بانک ملت', 'card_last4' => '0019'];

$first  = Peakopay_Webhook::complete_order(1001, $payment, $txn, 'تست مستقیم ۱');
$second = Peakopay_Webhook::complete_order(1001, $payment, $txn, 'تست مستقیم ۲'); // duplicate
$order  = wc_get_order(1001);

echo "MARKER first=" . var_export($first, true) . "\n";
echo "MARKER second=" . var_export($second, true) . "\n";
echo "MARKER payment_complete_count=" . $order->paymentCompleteCount . "\n";
echo "MARKER status=" . $order->status . "\n";
echo "MARKER ref=" . $order->paymentRef . "\n";
echo "MARKER paid_meta=" . $order->get_meta('_peakopay_paid') . "\n";
