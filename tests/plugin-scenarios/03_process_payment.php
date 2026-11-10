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
$GLOBALS['__hp_orders'][1001]->virtualOnly = false;

$GLOBALS['__hp_http_handler'] = static function ($url, $args) {
    $body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
    $envelope = [
        'ok' => true,
        'data' => ['payment' => [
            'token' => 'tok_' . md5((string) ($body['order_id'] ?? '')),
            'url' => 'https://pay.peakora.ir/pay/tok_' . md5((string) ($body['order_id'] ?? '')),
            'amount_rial' => 2500000,
        ]],
    ];
    return ['body' => json_encode($envelope), 'response' => ['code' => 200]];
};

$gw = new Peakopay_Gateway();
$res = $gw->process_payment(1001);
$order = wc_get_order(1001);
echo "MARKER result=" . ($res['result'] ?? '?') . "\n";
echo "MARKER redirect=" . ($res['redirect'] ?? '') . "\n";
echo "MARKER order_status=" . $order->status . "\n";
echo "MARKER order_token=" . $order->get_meta('_peakopay_token') . "\n";
echo "MARKER order_amount_rial=" . $order->get_meta('_peakopay_amount_rial') . "\n";

// idempotent retry (same attempt → same token via Idempotency-Key)
$res2 = $gw->process_payment(1001);
echo "MARKER retry_result=" . ($res2['result'] ?? '?') . "\n";
echo "MARKER retry_same_redirect=" . var_export(($res2['redirect'] ?? '') === ($res['redirect'] ?? 'X'), true) . "\n";

// failure path: HTTP 500 from server → graceful failure notice
$GLOBALS['__hp_http_handler'] = static function ($url, $args) {
    return ['body' => json_encode(['ok' => false, 'error' => ['code' => 'no_card', 'message' => 'کارت مقصد تنظیم نشده']]), 'response' => ['code' => 409]];
};
$res3 = $gw->process_payment(1002);
$GLOBALS['__hp_orders'][1002] = $GLOBALS['__hp_orders'][1002] ?? new WC_Order(1002);
echo "MARKER failure_result=" . ($res3['result'] ?? '?') . "\n";
echo "MARKER failure_notice_shown=" . var_export(!empty($GLOBALS['__hp_notices']), true) . "\n";
