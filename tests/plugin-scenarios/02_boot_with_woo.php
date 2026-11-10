<?php
declare(strict_types=1);
$GLOBALS['__wp_with_woocommerce'] = true;
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../../woocommerce/peakopay/peakopay.php';
do_action('plugins_loaded');
echo "MARKER boot_with_woo_survived=true\n";
echo "MARKER gateway_class_exists=" . var_export(class_exists('Peakopay_Gateway'), true) . "\n";
$gateways = apply_filters('woocommerce_payment_gateways', []);
echo "MARKER gateway_registered=" . var_export(in_array('Peakopay_Gateway', $gateways, true), true) . "\n";
echo "MARKER webhook_action_registered=" . var_export(isset($GLOBALS['__wp_filters']['woocommerce_api_peakopay']), true) . "\n";
$gw = new Peakopay_Gateway();
$keys = array_keys($gw->form_fields);
echo "MARKER settings_fields=" . implode(',', $keys) . "\n";
echo "MARKER default_title=" . $gw->get_option('title') . "\n";
echo "MARKER double_boot_survived=" . var_export(true, true) . "\n";
peakopay_boot(); // idempotency guard — must not re-register anything
$count = 0;
foreach ($GLOBALS['__wp_filters']['woocommerce_payment_gateways'] ?? [] as $h) {
    $g = call_user_func($h['callback'], []);
    if (in_array('Peakopay_Gateway', $g, true)) { $count++; }
}
echo "MARKER boot_is_idempotent=" . var_export($count === 1, true) . "\n";
do_action('init'); // real WP fires init on every request — textdomain loads here
echo "MARKER blocks_hook_registered=" . var_export(isset($GLOBALS['__wp_filters']['woocommerce_blocks_loaded']), true) . "\n";
echo "MARKER privacy_exporter_registered=" . var_export(isset($GLOBALS['__wp_filters']['wp_privacy_personal_data_exporters']), true) . "\n";
echo "MARKER privacy_policy_hook_registered=" . var_export(isset($GLOBALS['__wp_filters']['admin_init']), true) . "\n";
echo "MARKER textdomain_loaded=" . var_export(in_array(['peakopay', 'peakopay/languages'], array_map(static fn ($t) => [$t[0], $t[1]], $GLOBALS['__wp_textdomain'] ?? []), true), true) . "\n";
echo "MARKER pot_file_shipped=" . var_export(is_file(__DIR__ . '/../../woocommerce/peakopay/languages/peakopay.pot'), true) . "\n";
echo "MARKER license_file_shipped=" . var_export(is_file(__DIR__ . '/../../woocommerce/peakopay/LICENSE.txt'), true) . "\n";
echo "MARKER blocks_js_shipped=" . var_export(is_file(__DIR__ . '/../../woocommerce/peakopay/assets/js/peakopay-blocks.js'), true) . "\n";
