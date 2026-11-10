<?php
/**
 * WooCommerce plugin — WordPress/WooCommerce lifecycle test.
 *
 * Boots a scenario server (php -S) with WordPress + WooCommerce STUBS and
 * exercises the full plugin lifecycle exactly like the report requires:
 *
 *   1. WordPress starts (stubs)          7. Peakopay settings
 *   2. WooCommerce active                8. Save settings (sanitize)
 *   3. Plugin installed/loaded           9. Create test order
 *   4. Plugin boots (plugins_loaded)    10. process_payment → redirect
 *   5. Admin opens fine                 11. Webhook: valid/test/dup/expired/mismatch
 *   6. Settings page + fields           12. Thank-you fallback poll
 *                                      13. AJAX test-connection
 *   + load WITHOUT WooCommerce (no fatal), idempotent boot, deactivate/reactivate.
 *
 * NOTE: this runs against a stub environment (tests/plugin-scenarios/wp-stubs.php),
 * NOT a real WordPress install — stated honestly in the final report.
 */
declare(strict_types=1);

section('WooCommerce plugin lifecycle (WordPress stubs over real HTTP)');

$serverCmd = 'php -d opcache.enable=0 -S 127.0.0.1:8097 ' . escapeshellarg(__DIR__ . '/../plugin-scenarios/server.php');
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$server = proc_open($serverCmd, $descriptors, $pipes);
register_shutdown_function(static function () use (&$server): void {
    if (is_resource($server ?? null)) {
        proc_terminate($server);
        proc_close($server);
    }
});
usleep(500000);

/** GET a scenario, return [status, body]. */
function wp_scenario_get(string $s): array
{
    $ch = curl_init('http://127.0.0.1:8097/?s=' . rawurlencode($s));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); // note: after close this is 0 — recompute below
    return [$status, (string) $raw];
}

/** POST a scenario with raw body + headers, return [status, body]. */
function wp_scenario_post(string $s, string $body, array $headers = []): array
{
    $hdr = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $hdr[] = "$k: $v";
    }
    $ch = curl_init('http://127.0.0.1:8097/?s=' . rawurlencode($s));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $hdr,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

/** Extract the shutdown state JSON that webhook scenarios dump. */
function wp_state(array $response): array
{
    [, $body] = $response;
    $pos = strpos($body, '__HP_STATE__');
    if ($pos === false) {
        return [];
    }
    $json = substr($body, $pos + strlen('__HP_STATE__'));
    $json = trim(explode("\n", trim($json))[0]);
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}

function wp_marker(array $response, string $key): ?string
{
    [, $body] = $response;
    if (preg_match('#MARKER ' . preg_quote($key, '#') . '=(.*)#', $body, $m)) {
        return trim($m[1]);
    }
    return null;
}

$SECRET = 'whsec_test00000000000000000000000000000';
$TOKEN  = 'tok_1001aaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

function signed_event(string $secret, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ts = (string) time();
    return [$body, ['X-Peakopay-Timestamp' => $ts, 'X-Peakopay-Signature' => hash_hmac('sha256', $ts . '.' . $body, $secret)]];
}

try {
    // ── 1) WordPress loads the plugin WITHOUT WooCommerce → no fatal, notice shown
    $r = wp_scenario_get('01_load_without_woo');
    t('01 plugin loads without WooCommerce (no fatal)', $r[0] === 200 && wp_marker($r, 'no_fatal') === 'true');
    t('01 gateway class NOT loaded without WooCommerce (lazy)', wp_marker($r, 'gateway_class_exists') === 'false');
    t('01 admin notice about missing WooCommerce shown', wp_marker($r, 'admin_notice_shown') === 'true');

    // ── 2) Boot WITH WooCommerce → gateway + webhook registered
    $r = wp_scenario_get('02_boot_with_woo');
    fwrite(STDERR, "DBG02 status={$r[0]} body=" . substr($r[1], 0, 300) . "\n");
    t('02 plugin boots with WooCommerce (no fatal)', $r[0] === 200 && wp_marker($r, 'boot_with_woo_survived') === 'true');
    t('02 gateway registered in woocommerce_payment_gateways', wp_marker($r, 'gateway_registered') === 'true');
    t('02 webhook endpoint registered (wc-api/peakopay)', wp_marker($r, 'webhook_action_registered') === 'true');
    $fields = wp_marker($r, 'settings_fields') ?? '';
    foreach (['enabled', 'base_url', 'store_id', 'api_key', 'webhook_secret', 'amount_unit', 'timeout', 'debug', 'cancel_on_expire'] as $need) {
        t("02 settings field present: {$need}", str_contains($fields, $need));
    }
    t('02 double-boot is idempotent', wp_marker($r, 'boot_is_idempotent') === 'true');

    // ── 3) process_payment → creates session, redirects, idempotent retry
    $r = wp_scenario_get('03_process_payment');
    t('03 process_payment succeeds', wp_marker($r, 'result') === 'success');
    t('03 redirect points to Peakopay payment page', str_starts_with((string) wp_marker($r, 'redirect'), 'https://pay.peakora.ir/pay/'));
    t('03 order set to pending with token meta', wp_marker($r, 'order_status') === 'pending' && str_starts_with((string) wp_marker($r, 'order_token'), 'tok_'));
    t('03 amount stored in rial (IRT ×10 transparent)', wp_marker($r, 'order_amount_rial') === '2500000');
    t('03 idempotent retry returns the same payment', wp_marker($r, 'retry_result') === 'success' && wp_marker($r, 'retry_same_redirect') === 'true');
    t('03 server failure → graceful checkout error (no fatal)', wp_marker($r, 'failure_result') === 'failure' && wp_marker($r, 'failure_notice_shown') === 'true');

    // ── 4) Valid payment.paid webhook → completes the order exactly once
    [$body, $hdr] = signed_event($SECRET, [
        'event' => 'payment.paid',
        'store_id' => 'st_test123',
        'timestamp' => time(),
        'payment' => ['id' => 5, 'token' => $TOKEN, 'order_id' => '1001', 'order_key' => 'wc_order_test_1001', 'amount_rial' => 2500000, 'amount_base_rial' => 2500000, 'status' => 'paid', 'paid_at' => '2026-09-20 10:00:00', 'test' => false],
        'transaction' => ['id' => 7, 'amount_rial' => 2500000, 'reference' => 'REF123456', 'bank' => 'بانک ملت', 'card_last4' => '0019', 'occurred_at' => '2026-09-20 09:59:00'],
    ]);
    $r = wp_scenario_post('04_webhook_paid_valid', $body, $hdr);
    $state = wp_state($r);
    t('04 valid webhook accepted (HTTP 200)', $r[0] === 200);
    t('04 order completed (processing)', ($state['orders']['1001']['status'] ?? '') === 'processing');
    t('04 payment_complete called exactly once', ($state['orders']['1001']['payment_complete_count'] ?? 0) === 1);
    t('04 reference recorded on order', ($state['orders']['1001']['payment_ref'] ?? '') === 'REF123456');

    // ── 5) Duplicate webhook → no double payment
    [$body, $hdr] = signed_event($SECRET, [
        'event' => 'payment.paid',
        'payment' => ['id' => 5, 'token' => $TOKEN, 'order_id' => '1001', 'amount_rial' => 2500000, 'status' => 'paid', 'test' => false],
        'transaction' => ['reference' => 'REF123456', 'bank' => 'بانک ملت'],
    ]);
    $r = wp_scenario_post('05_webhook_paid_duplicate', $body, $hdr);
    $state = wp_state($r);
    t('05 duplicate webhook → NO second payment_complete', ($state['orders']['1001']['payment_complete_count'] ?? 0) === 0);

    // ── 6) Bad signature → 401
    $r = wp_scenario_post('06_webhook_bad_signature', '{"event":"payment.paid"}', [
        'X-Peakopay-Timestamp' => (string) time(),
        'X-Peakopay-Signature' => 'deadbeef' . str_repeat('0', 56),
    ]);
    t('06 invalid signature rejected with 401', $r[0] === 401 && str_contains($r[1], 'invalid_signature'));

    // ── 7) Test event → note only, never completes
    [$body, $hdr] = signed_event($SECRET, [
        'event' => 'payment.paid',
        'payment' => ['id' => 5, 'token' => $TOKEN, 'order_id' => '1001', 'amount_rial' => 2500000, 'status' => 'paid', 'test' => true],
        'transaction' => ['reference' => 'TST1', 'bank' => 'بانک ملت'],
    ]);
    $r = wp_scenario_post('07_webhook_test_event', $body, $hdr);
    $state = wp_state($r);
    t('07 test-flagged event never completes the order', ($state['orders']['1001']['payment_complete_count'] ?? 0) === 0 && ($state['orders']['1001']['status'] ?? '') === 'pending');

    // ── 8) Expired → pending cancelled; already-paid NOT downgraded
    [$body, $hdr] = signed_event($SECRET, [
        'event' => 'payment.expired',
        'payment' => ['id' => 5, 'token' => $TOKEN, 'order_id' => '1001', 'amount_rial' => 2500000, 'status' => 'expired', 'test' => false],
    ]);
    $r = wp_scenario_post('08_webhook_expired', $body, $hdr);
    $state = wp_state($r);
    t('08 expired → pending order cancelled', ($state['orders']['1001']['status'] ?? '') === 'cancelled');
    $r = wp_scenario_post('08b_webhook_expired_paid', $body, $hdr);
    $state = wp_state($r);
    t('08 expired → paid order NOT downgraded', ($state['orders']['1001']['status'] ?? '') === 'processing');

    // ── 9) Amount mismatch → order NOT completed
    [$body, $hdr] = signed_event($SECRET, [
        'event' => 'payment.paid',
        'payment' => ['id' => 5, 'token' => $TOKEN, 'order_id' => '1001', 'amount_rial' => 2500000, 'status' => 'paid', 'test' => false],
        'transaction' => ['reference' => 'REFX', 'bank' => 'بانک ملت'],
    ]);
    $r = wp_scenario_post('09_webhook_amount_mismatch', $body, $hdr);
    $state = wp_state($r);
    t('09 amount mismatch → order NOT completed (manual review note)', ($state['orders']['1001']['payment_complete_count'] ?? 0) === 0);

    // ── 10) complete_order() idempotent (direct calls)
    $r = wp_scenario_get('10_complete_order_idempotent');
    t('10 complete_order is idempotent', wp_marker($r, 'payment_complete_count') === '1' && wp_marker($r, 'status') === 'processing' && wp_marker($r, 'ref') === 'REF123456');

    // ── 11) Thank-you fallback poll → completes when server says paid
    $r = wp_scenario_get('11_thankyou_poll');
    t('11 thankyou fallback completes paid order once', wp_marker($r, 'status') === 'processing' && wp_marker($r, 'payment_complete_count') === '1' && wp_marker($r, 'polled_once') === 'true');

    // ── 12) AJAX test-connection (scenario self-invokes with a valid nonce)
    // نکته: استاب wp_remote_request دقیقاً مثل وردپرس واقعی رفتار می‌کند — هدرهای
    // آرایه عددی به هدر خراب «0: …» تبدیل می‌شوند؛ پس موفقیت این تست یعنی افزونه
    // واقعاً هدرها را درست می‌فرستد (باگ «کلید API ارسال نشده» بازنمی‌گردد).
    $r = wp_scenario_get('12_ajax_test_connection');
    $body12 = preg_split('/\r\n\r\n/', $r[1], 2)[1] ?? $r[1]; // جدا کردن هدر HTTP از بدنه
    $j12 = json_decode($body12, true);
    $msg12 = (string) ($j12['data']['message'] ?? '');
    t('12 AJAX test-connection returns success JSON', str_contains($r[1], '"success":true') && str_contains($r[1], '9.9.9') && str_contains($r[1], 'st_test123') === false);
    t('12 test result shows full checklist (cards/devices/currency)', str_contains($msg12, 'کارت') && str_contains($msg12, 'دستگاه') && str_contains($msg12, 'تومان'));
    t('12 no secret leaked in response', str_contains($r[1], 'hpk_testkey') === false && str_contains($r[1], 'whsec_') === false);

    // ── 13) Deactivate / reactivate cycles
    $r = wp_scenario_get('13_deactivate_reactivate');
    t('13 deactivate/reactivate cycles are safe', wp_marker($r, 'activation_survived') === 'true'
        && wp_marker($r, 'reactivation_survived') === 'true'
        && wp_marker($r, 'uninstall_option_removed') === 'true');

    // ── 14) Round 17 (WordPress.org readiness) — blocks/privacy/i18n/packaging
    $r = wp_scenario_get('02_boot_with_woo');
    t('17 checkout-blocks hook registered (woocommerce_blocks_loaded)', wp_marker($r, 'blocks_hook_registered') === 'true');
    t('17 privacy exporter registered (wp_privacy_personal_data_exporters)', wp_marker($r, 'privacy_exporter_registered') === 'true');
    t('17 privacy policy hook registered (admin_init)', wp_marker($r, 'privacy_policy_hook_registered') === 'true');
    t('17 text domain "peakopay" loaded from /languages', wp_marker($r, 'textdomain_loaded') === 'true');
    t('17 translation template (POT) ships with plugin', wp_marker($r, 'pot_file_shipped') === 'true');
    t('17 LICENSE.txt (GPL-2.0) ships with plugin', wp_marker($r, 'license_file_shipped') === 'true');
    t('17 blocks JS asset ships with plugin', wp_marker($r, 'blocks_js_shipped') === 'true');

    $r = wp_scenario_get('14_uninstall');
    t('17 uninstall removes gateway settings only', wp_marker($r, 'settings_deleted') === 'true');
    t('17 uninstall keeps order payment meta (audit trail)', wp_marker($r, 'order_meta_kept') === 'true');

    // ── 15) دور ۲۳ — راهنمای «فروشگاه دوم» در صفحهٔ تنظیمات درگاه (چندفروشگاهی)
    $r = wp_scenario_get('15_admin_second_store');
    t('23 admin settings page renders without fatal', $r[0] === 200 && wp_marker($r, 'admin_options_rendered') === 'true');
    t('23 test-connection button still present', wp_marker($r, 'test_connection_button') === 'true');
    t('23 second-store guide box rendered', wp_marker($r, 'second_store_box') === 'true' && wp_marker($r, 'second_store_title') === 'true');
    t('23 guide has exactly three steps', wp_marker($r, 'three_steps') === 'true');
    t('23 step 1 points to panel «فروشگاه‌ها»', wp_marker($r, 'second_store_step_panel') === 'true');
    t('23 step 2 lists all three credential formats', wp_marker($r, 'second_store_step_copy_keys') === 'true');
    t('23 step 3 says install plugin on second site', wp_marker($r, 'second_store_step_install') === 'true');
    t('23 isolation note (keys are store-scoped) shown', wp_marker($r, 'second_store_isolation_note') === 'true');
    t('23 guide leaks no actual secret values', wp_marker($r, 'no_actual_secret_values') === 'true');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
}
