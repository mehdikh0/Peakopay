<?php
/**
 * دور ۲۳ — صفحهٔ تنظیمات درگاه با راهنمای «فروشگاه دوم» (چندفروشگاهی):
 *  - admin_options رندر می‌شود (بدون فتال) و دکمهٔ تست اتصال سر جایش است
 *  - جعبهٔ راهنمای اتصال فروشگاه دوم با سه گام + یادداشت ایزوله‌بودن
 *  - بدون افشای هیچ مقدار محرمانه (فقط راهنمای قالب‌ها: st_… / hpk_… / whsec_…)
 */
declare(strict_types=1);
$GLOBALS['__wp_with_woocommerce'] = true;
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../../woocommerce/peakopay/peakopay.php';
do_action('plugins_loaded');

$gw = new Peakopay_Gateway();
ob_start();
$gw->admin_options();
$html = (string) ob_get_clean();

echo "MARKER admin_options_rendered=" . var_export(strlen($html) > 100, true) . "\n";
echo "MARKER test_connection_button=" . var_export(str_contains($html, 'id="peakopay-test-connection"'), true) . "\n";
echo "MARKER second_store_box=" . var_export(str_contains($html, 'peakopay-second-store'), true) . "\n";
echo "MARKER second_store_title=" . var_export(str_contains($html, 'شعبه یا فروشگاه دوم دارید؟'), true) . "\n";
echo "MARKER second_store_step_panel=" . var_export(str_contains($html, 'بخش «فروشگاه‌ها»'), true) . "\n";
echo "MARKER second_store_step_copy_keys=" . var_export(str_contains($html, 'hpk_') && str_contains($html, 'whsec_') && str_contains($html, 'st_'), true) . "\n";
echo "MARKER second_store_step_install=" . var_export(str_contains($html, 'روی سایت دوم نصب کنید'), true) . "\n";
echo "MARKER second_store_isolation_note=" . var_export(str_contains($html, 'ایزوله'), true) . "\n";
// هیچ مقدار واقعی کلید نباید در خروجی باشد — فقط راهنمای قالب
preg_match_all('/hpk_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{8,}/', $html, $leaks);
echo "MARKER no_actual_secret_values=" . var_export($leaks[0] === [], true) . "\n";
echo "MARKER three_steps=" . var_export(substr_count($html, '<li>') === 3, true) . "\n";
