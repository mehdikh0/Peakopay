<?php
/**
 * Round 17 — uninstall behavior: settings deleted, order meta (financial audit
 * trail) preserved. Loads WITH WooCommerce so boot is realistic, seeds an
 * order with plugin meta, then runs uninstall.php.
 */
declare(strict_types=1);
$GLOBALS['__wp_with_woocommerce'] = true;
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../../woocommerce/peakopay/peakopay.php';
do_action('plugins_loaded');

update_option('woocommerce_peakopay_settings', [
    'enabled' => 'yes', 'base_url' => 'https://pay.example.com',
    'store_id' => 'st_test123', 'api_key' => 'hpk_x', 'webhook_secret' => 'whsec_x',
]);

$GLOBALS['__hp_orders'][555] = new WC_Order(555);
$GLOBALS['__hp_orders'][555]->update_meta_data('_peakopay_token', 'tok_keepme');
$GLOBALS['__hp_orders'][555]->update_meta_data('_peakopay_paid', '1');
$GLOBALS['__hp_orders'][555]->update_meta_data('_peakopay_reference', 'REFKEEP');

define('WP_UNINSTALL_PLUGIN', 'peakopay/peakopay.php');
require __DIR__ . '/../../woocommerce/peakopay/uninstall.php';

echo "MARKER settings_deleted=" . var_export(get_option('woocommerce_peakopay_settings', null) === null, true) . "\n";
echo "MARKER order_meta_kept=" . var_export(
    $GLOBALS['__hp_orders'][555]->get_meta('_peakopay_token') === 'tok_keepme'
    && $GLOBALS['__hp_orders'][555]->get_meta('_peakopay_paid') === '1'
    && $GLOBALS['__hp_orders'][555]->get_meta('_peakopay_reference') === 'REFKEEP',
    true
) . "\n";
