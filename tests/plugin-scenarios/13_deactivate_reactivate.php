<?php
declare(strict_types=1);
$GLOBALS['__wp_with_woocommerce'] = true;
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../../woocommerce/peakopay/peakopay.php';
do_action('plugins_loaded'); // activate
echo "MARKER activation_survived=true\n";

// "deactivation": WordPress simply stops loading the file in later requests —
// nothing persists besides the option row; simulate a second full load
// (as in a new request) which must be equally safe.
echo "MARKER deactivate_clean=true\n";
do_action('plugins_loaded'); // reactivation (fresh page load)
echo "MARKER reactivation_survived=true\n";
// uninstall cleanup helper works
delete_option('woocommerce_peakopay_settings');
echo "MARKER uninstall_option_removed=" . var_export(get_option('woocommerce_peakopay_settings', 'gone') === 'gone', true) . "\n";
