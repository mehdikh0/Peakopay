<?php
declare(strict_types=1);
$GLOBALS['__wp_with_woocommerce'] = true;
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../../woocommerce/peakopay/peakopay.php';
do_action('plugins_loaded');

$GLOBALS['__hp_http_handler'] = static function ($url, $args) {
    // شبیه‌سازی سرور: فقط هدرهای انجمنیِ درست (مثل وردپرس واقعی) دیده می‌شوند.
    $h = $args['headers'] ?? [];
    $body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
    $store = (string) ($h['x-peakopay-store'] ?? '');
    $auth  = (string) ($h['authorization'] ?? '');
    $xkey  = (string) ($h['x-peakopay-key'] ?? '');
    $bStore = (string) ($body['store_id'] ?? '');
    $bKey   = (string) ($body['api_key'] ?? '');
    $noNumericKeys = !isset($h['0']); // آرایه عددی = باگ وردپرس واقعی
    $ok = str_contains($url, '/api/v1/test-connection')
        && $noNumericKeys
        && ($store === 'st_test123' || $bStore === 'st_test123')
        && (str_contains($auth, 'hpk_testkey') || $xkey === 'hpk_testkey0000000000000000000000' || $bKey === 'hpk_testkey0000000000000000000000');
    $envelope = $ok
        ? ['ok' => true, 'data' => ['connected' => true, 'store' => 'فروشگاه تست', 'version' => '9.9.9', 'currency' => 'IRT', 'cards' => 2, 'senders' => 3, 'devices' => 1]]
        : ['ok' => false, 'error' => ['code' => 'unauthorized', 'message' => 'شناسه فروشگاه یا کلید API ارسال نشده است.']];
    return ['body' => json_encode($envelope), 'response' => ['code' => $ok ? 200 : 401]];
};

$_POST['security'] = wp_create_nonce('peakopay_test_connection');
$_POST['base_url'] = 'https://pay.peakora.ir';
$_POST['store_id'] = 'st_test123';
$_POST['api_key']  = 'hpk_testkey0000000000000000000000';
peakopay_ajax_test_connection(); // exits with JSON
