<?php
declare(strict_types=1);
$GLOBALS['__wp_with_woocommerce'] = true;
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../../woocommerce/peakopay/peakopay.php';
do_action('plugins_loaded');

update_option('woocommerce_peakopay_settings', [
    'enabled' => 'yes', 'base_url' => 'https://pay.peakora.ir',
    'store_id' => 'st_test123', 'api_key' => 'hpk_testkey0000000000000000000000',
    'webhook_secret' => 'whsec_test00000000000000000000000000000',
    'amount_unit' => 'IRT', 'timeout' => '20', 'debug' => 'yes',
]);
$GLOBALS['__hp_orders'][1001] = new WC_Order(1001);
$GLOBALS['__hp_orders'][1001]->update_meta_data('_peakopay_token', 'tok_poll123');
$GLOBALS['__hp_orders'][1001]->update_meta_data('_peakopay_amount_rial', '2500000');

// server says the payment is paid
$GLOBALS['__hp_http_handler'] = static function ($url, $args) {
    $envelope = ['ok' => true, 'data' => ['payment' => ['status' => 'paid', 'amount_rial' => 2500000, 'order_id' => '1001'],
        'transaction' => ['reference' => 'REFPOLL', 'bank' => 'بانک ملت']]];
    return ['body' => json_encode($envelope), 'response' => ['code' => 200]];
};

$gw = new Peakopay_Gateway();
$gw->thankyou_poll_status(1001);
$gw->thankyou_poll_status(1001); // second visit → single-use guard, no double poll
$order = wc_get_order(1001);
echo "MARKER status=" . $order->status . "\n";
echo "MARKER payment_complete_count=" . $order->paymentCompleteCount . "\n";
echo "MARKER polled_once=" . var_export($order->get_meta('_peakopay_thankyou_polled') === '1', true) . "\n";
