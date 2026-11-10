<?php
/**
 * Shared setup for webhook scenarios: boots the plugin with WooCommerce,
 * pre-seeds an order and registers a shutdown dumper.
 * Expects: $orderId, optional $preMarkPaid, optional $orderStatus.
 */
declare(strict_types=1);
$GLOBALS['__wp_with_woocommerce'] = true;
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../../woocommerce/peakopay/peakopay.php';
do_action('plugins_loaded');

update_option('woocommerce_peakopay_settings', [
    'enabled' => 'yes', 'base_url' => 'https://pay.peakora.ir',
    'store_id' => 'st_test123', 'api_key' => 'hpk_testkey0000000000000000000000',
    'webhook_secret' => WH_SECRET, 'amount_unit' => 'IRT', 'timeout' => '20', 'debug' => 'yes',
]);

$GLOBALS['__hp_orders'][(int) ORDER_ID] = new WC_Order((int) ORDER_ID);
$GLOBALS['__hp_orders'][(int) ORDER_ID]->status = ORDER_STATUS;
if (defined('PRE_TOKEN')) {
    $GLOBALS['__hp_orders'][(int) ORDER_ID]->update_meta_data('_peakopay_token', PRE_TOKEN);
}
if (PRE_MARK_PAID) {
    $GLOBALS['__hp_orders'][(int) ORDER_ID]->update_meta_data('_peakopay_paid', '1');
}
if (defined('PRE_AMOUNT')) {
    $GLOBALS['__hp_orders'][(int) ORDER_ID]->update_meta_data('_peakopay_amount_rial', (string) PRE_AMOUNT);
}

register_shutdown_function(static function (): void {
    echo "\n__HP_STATE__" . json_encode(__hp_state(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});
