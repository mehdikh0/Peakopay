<?php
declare(strict_types=1);
require __DIR__ . '/wp-stubs.php';          // NO WooCommerce classes
require __DIR__ . '/../../woocommerce/peakopay/peakopay.php';
do_action('plugins_loaded');                // boot attempt
echo "MARKER no_woo_boot_survived\n";
echo "MARKER gateway_class_exists=" . var_export(class_exists('Peakopay_Gateway'), true) . "\n";
echo "MARKER api_class_exists=" . var_export(class_exists('Peakopay_API'), true) . "\n";
ob_start();
do_action('admin_notices');                 // the admin warning must appear
$notice = (string) ob_get_clean();
echo "MARKER admin_notice_shown=" . var_export(str_contains($notice, 'ووکامرس'), true) . "\n";
echo "MARKER no_fatal=true\n";
