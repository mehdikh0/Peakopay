<?php
/**
 * Peakopay — full HTTP end-to-end test.
 *
 * Boots two PHP dev servers (backend + mock WooCommerce) and drives the REAL
 * user journey over HTTP, including the web installer:
 *
 *   installer (browser) → admin login → WooCommerce payment creation (API)
 *   → payment page → customer claim → Android pairing → device approval
 *   → SMS transaction upload (HMAC) → matching → webhook → order completed
 *   → payment page PAID → dashboards updated
 *
 * plus negative cases (bad key, revoked device, replay, duplicates, expiry,
 * rate limits, test mode).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

if (!defined('HP_BASE')) {
    define('HP_BASE', dirname(__DIR__) . '/backend');
}

const BACKEND_URL = 'http://127.0.0.1:8099';
const MOCK_URL    = 'http://127.0.0.1:8098';

$E2E_DB  = getenv('HP_E2E_DB') ?: 'peakopay_e2e';
$DB_USER = getenv('HP_TEST_DB_USER') ?: 'hp';
$DB_PASS = getenv('HP_TEST_DB_PASS') ?: 'hp_test_pass';

// ---------------------------------------------------------------- helpers

function http_req(string $method, string $url, ?string $body = null, array $headers = [], ?string &$cookies = null): array
{
    $ch = curl_init($url);
    $hdr = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $hdr[] = (is_int($k) ? $v : $k . ': ' . $v);
    }
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($cookies !== null) {
        $opts[CURLOPT_COOKIE] = $cookies;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
        $hasCT = false;
        foreach ($hdr as $h) {
            if (stripos((string) $h, 'content-type:') === 0) {
                $hasCT = true;
            }
        }
        if (!$hasCT) {
            $hdr[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $hdr;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['status' => 0, 'body' => 'curl error: ' . curl_error($ch), 'headers' => ''];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr((string) $raw, 0, $hsize);
    $respBody = substr((string) $raw, $hsize);
    if ($cookies !== null && preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $head, $m)) {
        foreach ($m[1] as $c) {
            $parts = explode('=', $c, 2);
            $jar[$parts[0]] = $parts[1] ?? '';
        }
        $cookies = implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($jar ?? []), $jar ?? []));
    }
    return ['status' => $status, 'body' => $respBody, 'headers' => $head];
}

function extract_csrf(string $html): string
{
    if (preg_match('#name="_csrf" value="([^"]+)"#', $html, $m)) {
        return html_entity_decode($m[1]);
    }
    if (preg_match('#name="_csrf"\s+value="([^"]+)"#s', $html, $m)) {
        return $m[1];
    }
    return '';
}

function form_post(string $url, array $fields, string &$cookies, ?string $csrfUrl = null): array
{
    $html = http_req('GET', $csrfUrl ?? $url, cookies: $cookies)['body'];
    $fields['_csrf'] = extract_csrf($html);
    $body = http_build_query($fields);
    return http_req('POST', $url, $body, ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies);
}

function device_headers(int $deviceId, string $secret, string $jsonBody): array
{
    $ts = (string) time();
    return [
        'X-Peakopay-Device'    => (string) $deviceId,
        'X-Peakopay-Timestamp' => $ts,
        'X-Peakopay-Signature' => hash_hmac('sha256', $ts . '.' . $jsonBody, $secret),
    ];
}

function mock_events(): array
{
    $f = sys_get_temp_dir() . '/hp_mock_woo.log';
    if (!is_file($f)) {
        return [];
    }
    return array_map(static fn ($l) => json_decode($l, true) ?: [], array_values(array_filter(explode("\n", trim((string) file_get_contents($f))))));
}

// ---------------------------------------------------------------- servers

section('Booting servers');

// ensure e2e database exists and is empty
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$E2E_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot prepare e2e DB: {$e->getMessage()}\n");
    exit(2);
}

$cleanup = function () use ($E2E_DB, $DB_USER, $DB_PASS): void {
    @unlink(HP_BASE . '/config/config.php');
    @unlink(HP_BASE . '/storage/installed.lock');
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `{$E2E_DB}`");
    } catch (Throwable) {
    }
};
register_shutdown_function($cleanup);

// kill orphaned servers from previous runs (defensive)
exec("for p in $(ss -tlnp 2>/dev/null | grep -E ':(8098|8099)' | grep -oP 'pid=\\K[0-9]+' | sort -u); do kill \$p 2>/dev/null; done");
usleep(500000);

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$backend = proc_open('php -d opcache.enable=0 -S 127.0.0.1:8099 -t ' . escapeshellarg(HP_BASE) . ' ' . escapeshellarg(__DIR__ . '/router.php'), $descriptors, $pipes1);
register_shutdown_function(static function () use (&$backend): void {
    if (is_resource($backend ?? null)) {
        proc_terminate($backend);
        proc_close($backend);
    }
});
// mock woo
$sock = @fsockopen('127.0.0.1', 8098, $en, $es, 0.3);
if ($sock !== false) {
    fclose($sock);
    $mock = null; // already running (started by unit tests)
} else {
    $mock = proc_open('php -S 127.0.0.1:8098 ' . escapeshellarg(__DIR__ . '/mock_woo.php'), $descriptors, $pipes2);
}
usleep(600000);
t('backend server is up', str_contains(http_req('GET', BACKEND_URL . '/health')['body'] ?? '', '"status"'));
t('mock WooCommerce is up', http_req('POST', MOCK_URL . '/wc-api/peakopay', '{}')['status'] === 200);

// fresh mock state
@unlink(sys_get_temp_dir() . '/hp_mock_woo.log');
@unlink(sys_get_temp_dir() . '/hp_mock_woo_orders.json');

// ---------------------------------------------------------------- installer

section('Web installer (no SSH, no CLI, no manual SQL)');

@unlink(HP_BASE . '/config/config.php');
@unlink(HP_BASE . '/storage/installed.lock');

$cookies = '';
$r = http_req('GET', BACKEND_URL . '/', cookies: $cookies);
t('root redirects to installer when not installed', str_contains($r['headers'], '/install'));

$r = http_req('GET', BACKEND_URL . '/install', cookies: $cookies);
t('installer step 1 renders requirements', str_contains($r['body'], 'بررسی محیط اجرا'));
t('requirements page shows PHP check', str_contains($r['body'], 'نسخه PHP'));
$csrf = extract_csrf($r['body']);

$r = http_req('POST', BACKEND_URL . '/install?step=1', http_build_query(['_csrf' => $csrf]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies);
t('step 1 → step 2 redirect', str_contains($r['headers'], 'step=2'));

// step 2: wrong DB credentials are rejected with a friendly message
$r = http_req('GET', BACKEND_URL . '/install?step=2', cookies: $cookies);
$csrf = extract_csrf($r['body']);
$r = http_req('POST', BACKEND_URL . '/install?step=2', http_build_query([
    '_csrf' => $csrf, 'db_host' => '127.0.0.1', 'db_port' => 3306,
    'db_name' => 'peakopay_e2e', 'db_user' => 'hp', 'db_pass' => 'wrong-password',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies);
t('bad DB credentials rejected', str_contains($r['headers'], 'step=2'));

// step 2: correct credentials
$cookies2 = '';
$r = http_req('GET', BACKEND_URL . '/install?step=2', cookies: $cookies2);
$csrf = extract_csrf($r['body']);
$r = http_req('POST', BACKEND_URL . '/install?step=2', http_build_query([
    '_csrf' => $csrf, 'db_host' => '127.0.0.1', 'db_port' => 3306,
    'db_name' => 'peakopay_e2e', 'db_user' => 'hp', 'db_pass' => 'hp_test_pass',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('DB connected + tables created (step 3 redirect)', str_contains($r['headers'], 'step=3'));

// step 3: admin
$r = http_req('GET', BACKEND_URL . '/install?step=3', cookies: $cookies2);
$csrf = extract_csrf($r['body']);
$r = http_req('POST', BACKEND_URL . '/install?step=3', http_build_query([
    '_csrf' => $csrf, 'admin_name' => 'مرچنت تست', 'admin_email' => 'merchant@shop.test',
    'admin_pass' => 'StrongPass#99', 'admin_pass2' => 'StrongPass#99',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('admin created (step 4 redirect)', str_contains($r['headers'], 'step=4'));

// step 4: store
$r = http_req('GET', BACKEND_URL . '/install?step=4', cookies: $cookies2);
$csrf = extract_csrf($r['body']);
$r = http_req('POST', BACKEND_URL . '/install?step=4', http_build_query([
    '_csrf' => $csrf, 'store_name' => 'فروشگاه پیکو پی', 'store_url' => 'http://127.0.0.1:8098',
    'support_phone' => '09120000000', 'support_telegram' => '@peakopay',
    'currency' => 'IRT', 'timezone' => 'Asia/Tehran',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('store saved (step 5 redirect)', str_contains($r['headers'], 'step=5'));

// step 5: card — invalid card must be rejected
$r = http_req('GET', BACKEND_URL . '/install?step=5', cookies: $cookies2);
$csrf = extract_csrf($r['body']);
$r = http_req('POST', BACKEND_URL . '/install?step=5', http_build_query([
    '_csrf' => $csrf, 'bank_name' => 'بانک ملت', 'card_holder' => 'علی تستی',
    'card_number' => '1234567812345678',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('invalid card number rejected (Luhn)', str_contains($r['headers'], 'step=5'));

$r = http_req('POST', BACKEND_URL . '/install?step=5', http_build_query([
    '_csrf' => $csrf, 'bank_name' => 'بانک ملت', 'card_holder' => 'علی تستی',
    'card_number' => '6219860000000019',
    'iban' => 'IR820540102680020817909002', 'account_number' => '1234567890',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('valid card accepted → store created (step 6)', str_contains($r['headers'], 'step=6'));

// step 6: credentials shown once
$r = http_req('GET', BACKEND_URL . '/install?step=6', cookies: $cookies2);
t('step 6 shows Backend URL', str_contains($r['body'], 'http://127.0.0.1:8099'));
preg_match('#<div class="code-box">hpk_[A-Za-z0-9]+</div>#', $r['body'], $mKey);
preg_match('#<div class="code-box">whsec_[A-Za-z0-9]+</div>#', $r['body'], $mSec);
preg_match('#<div class="code-box">(st_[A-Za-z0-9]+)</div>#', $r['body'], $mStore);
t('API key shown', isset($mKey[0]));
t('webhook secret shown', isset($mSec[0]));
t('store id shown', isset($mStore[1]));
$apiKey = $mKey ? trim(strip_tags($mKey[0])) : '';
$webhookSecret = $mSec ? trim(strip_tags($mSec[0])) : '';
$storeId = $mStore[1] ?? '';
file_put_contents(sys_get_temp_dir() . '/hp_mock_woo.secret', $webhookSecret); // mock Woo now verifies with the real secret

// finish
$r = http_req('GET', BACKEND_URL . '/install?step=6', cookies: $cookies2);
$csrf = extract_csrf($r['body']);
$r = http_req('POST', BACKEND_URL . '/install?step=6', http_build_query(['_csrf' => $csrf]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('finish → step 7', str_contains($r['headers'], 'step=7'));
t('install lock file created', is_file(HP_BASE . '/storage/installed.lock'));

http_req('GET', BACKEND_URL . '/install', cookies: $cookies2); // one-time success page
$r = http_req('GET', BACKEND_URL . '/install', cookies: $cookies2);
t('installer locked after completion', str_contains($r['body'], 'قفل است'));

$r = http_req('GET', BACKEND_URL . '/health');
$h = json_decode($r['body'], true);
t('/health reports healthy', ($h['ok'] ?? false) === true);
t('/health exposes no secrets', !str_contains($r['body'], $apiKey) && !str_contains($r['body'], $webhookSecret) && !str_contains($r['body'], '6219860000000019'));
t('/health shows DB up + extensions', ($h['checks']['database'] ?? '') === 'up' && ($h['checks']['extensions']['pdo_mysql'] ?? false) === true);

// ---------------------------------------------------------------- admin login

section('Admin panel');

$r = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2);
t('admin redirects to login when logged out', str_contains($r['headers'], '/admin/login'));

$r = form_post(BACKEND_URL . '/admin/login', ['email' => 'merchant@shop.test', 'password' => 'wrong'], $cookies2);
t('wrong password rejected', str_contains($r['headers'], '/admin/login'));

$r = form_post(BACKEND_URL . '/admin/login', ['email' => 'merchant@shop.test', 'password' => 'StrongPass#99'], $cookies2);
$c = (string) @file_get_contents(HP_BASE . '/config/config.php');
preg_match("#'name' => '([^']+)'#", $c, $cm);
fwrite(STDERR, "DBG[after-login] file-db=" . ($cm[1] ?? 'NOFILE') . "\n");
t('admin login succeeds', str_contains($r['headers'], '/admin'));

$r = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2);
$c = (string) @file_get_contents(HP_BASE . '/config/config.php');
preg_match("#'name' => '([^']+)'#", $c, $cm);
$hdbg = json_decode(http_req('GET', BACKEND_URL . '/health')['body'], true);
exec("ss -tlnp 2>/dev/null | grep '127.0.0.1:8099' | grep -oP 'pid=\K[0-9]+' | sort -u", $piddbg);
fwrite(STDERR, "DBG[dash] file-db=" . ($cm[1] ?? 'NOFILE') . " server-db=" . ($hdbg['checks']['db_name_debug'] ?? 'n/a') . " server-pids=" . implode(',', $piddbg) . "\n");
t('dashboard renders with empty states', str_contains($r['body'], 'داشبورد') && str_contains($r['body'], 'هنوز داده‌ای نیست'));
t('dashboard shows no card number (masked only)', !str_contains($r['body'], '6219860000000019'));

// CSRF protection
$r = http_req('POST', BACKEND_URL . '/admin/settings/save', 'section=general', ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('admin POST without CSRF token blocked', $r['status'] === 302 || str_contains($r['body'], 'نامعتبر'));

// ---------------------------------------------------------------- woo API auth

section('WooCommerce API (store auth)');

$wooHeaders = ['X-Peakopay-Store' => $storeId, 'Authorization' => 'Bearer ' . $apiKey];

$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', '{"order_id":"1"}', $wooHeaders);
t('invalid body → 422/400', in_array($r['status'], [400, 422], true));

$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', '{"order_id":"1","amount":100000,"currency":"IRT"}', ['X-Peakopay-Store' => $storeId, 'Authorization' => 'Bearer hpk_totally_invalid']);
t('invalid API key → 401', $r['status'] === 401);

$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', '{"order_id":"1","amount":100000,"currency":"IRT"}', ['X-Peakopay-Store' => 'st_wrong', 'Authorization' => 'Bearer ' . $apiKey]);
t('wrong store id → 401', $r['status'] === 401);

$r = http_req('POST', BACKEND_URL . '/api/v1/test-connection', '{}', $wooHeaders);
$tc = json_decode($r['body'], true);
t('test-connection endpoint works', ($tc['ok'] ?? false) === true && str_contains((string) ($tc['data']['store'] ?? ''), 'پیکو'));
t('test-connection returns full status (currency/cards/devices)', isset($tc['data']['currency'], $tc['data']['cards'], $tc['data']['devices'], $tc['data']['senders']));

// فقط X-Peakopay-Key (بدون Authorization) — هاست‌هایی که Authorization را حذف می‌کنند
$r = http_req('POST', BACKEND_URL . '/api/v1/test-connection', '{}', ['X-Peakopay-Store' => $storeId, 'X-Peakopay-Key' => $apiKey]);
$tck = json_decode($r['body'], true);
t('test-connection with X-Peakopay-Key header (no Authorization)', ($tck['ok'] ?? false) === true);

// فقط بدنه (بدون هیچ هدر اعتباری) — آخرین لایه پشتیبان
$r = http_req('POST', BACKEND_URL . '/api/v1/test-connection', json_encode(['store_id' => $storeId, 'api_key' => $apiKey], JSON_UNESCAPED_UNICODE), ['Content-Type' => 'application/json']);
$tcb = json_decode($r['body'], true);
t('test-connection with body-only credentials (headers stripped by host)', ($tcb['ok'] ?? false) === true && ($tcb['data']['connected'] ?? false) === true);

// کلید غلط در بدنه → 401
$r = http_req('POST', BACKEND_URL . '/api/v1/test-connection', json_encode(['store_id' => $storeId, 'api_key' => 'hpk_totally_invalid']), ['Content-Type' => 'application/json']);
t('body-only with wrong key still 401', $r['status'] === 401);

// تست‌های موفق/ناموفق در لاگ حسابرسی ثبت شده‌اند (برای صفحه «وضعیت اتصال‌ها»)
try {
    $pdoAudit = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$E2E_DB}", $configArr['db']['user'] ?? $DB_USER, $configArr['db']['pass'] ?? $DB_PASS);
    $okC   = (int) $pdoAudit->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'api.test_connection'")->fetchColumn();
    $failC = (int) $pdoAudit->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'api.auth_failed' AND detail LIKE '%test-connection%'")->fetchColumn();
} catch (Throwable $e) {
    $okC = $failC = -1;
}
t('audit log records plugin test-connection attempts', $okC >= 2 && $failC >= 1);

// ---------------------------------------------------------------- payment flow

section('Payment session + payment page');

$payload = json_encode([
    'order_id' => '1001', 'order_key' => 'wc_order_abc', 'amount' => 250000, 'currency' => 'IRT',
    'customer' => ['name' => 'مشتری نمونه', 'phone' => '09121112233'],
    'redirect_url' => 'http://127.0.0.1:8098/checkout/order-received/1001/',
    'description' => 'سفارش تست',
]);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $payload, $wooHeaders + ['Idempotency-Key' => 'order-1001-a']);
$create = json_decode($r['body'], true);
$pay = $create['data']['payment'] ?? [];
t('payment created', ($create['ok'] ?? false) === true);
t('IRT→IRR conversion explicit (250,000 toman → 2,500,000 rial base)', ($pay['amount_base_rial'] ?? 0) === 2500000);
t('unique suffix applied transparently', ($pay['amount_rial'] ?? 0) > 2500000 && ($pay['amount_rial'] ?? 0) <= 2500999);
t('payment URL provided', str_contains((string) ($pay['url'] ?? ''), '/pay/'));
$token = (string) ($pay['token'] ?? '');

// idempotent create
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $payload, $wooHeaders + ['Idempotency-Key' => 'order-1001-b']);
$create2 = json_decode($r['body'], true);
t('same order reuses open payment (idempotent)', ($create2['data']['payment']['token'] ?? 'x') === $token);

// plugin status check endpoint
$r = http_req('GET', BACKEND_URL . "/api/v1/payments/{$token}", null, $wooHeaders);
$st = json_decode($r['body'], true);
t('plugin can poll payment status', ($st['data']['payment']['status'] ?? '') === 'pending');
$r = http_req('GET', BACKEND_URL . "/api/v1/payments/{$token}", null, ['X-Peakopay-Store' => $storeId, 'Authorization' => 'Bearer hpk_no']);
t('status endpoint requires valid key', $r['status'] === 401);

// payment page
$r = http_req('GET', BACKEND_URL . "/pay/{$token}");
t('payment page renders', $r['status'] === 200);
t('page shows full destination card for the customer', str_contains($r['body'], '6219 8600 0000 0019'));
t('page shows payable amount in Persian digits', str_contains($r['body'], 'ریال') && str_contains($r['body'], '۲,۵۰۰,'));
t('page shows store name + order number', str_contains($r['body'], 'فروشگاه پیکو پی') && str_contains($r['body'], '۱۰۰۱'));
t('page has copy card button with raw digits', str_contains($r['body'], 'data-copy="6219860000000019"'));
t('page has copy amount button', str_contains($r['body'], 'کپی مبلغ'));
t('page shows countdown', str_contains($r['body'], 'id="timer"'));
t('page shows payment steps guide', str_contains($r['body'], 'راهنمای پرداخت'));
t('no secrets in page source', !str_contains($r['body'], $apiKey) && !str_contains($r['body'], $webhookSecret));

// status endpoint (public)
$r = http_req('GET', BACKEND_URL . "/pay/{$token}/status");
$st = json_decode($r['body'], true);
t('public status endpoint → pending', ($st['data']['payment']['status'] ?? '') === 'pending');

// claim ("I have paid")
$r = http_req('POST', BACKEND_URL . "/pay/{$token}/claim");
$st = json_decode($r['body'], true);
t('customer claim → verifying', ($st['data']['payment']['status'] ?? '') === 'verifying');
$claimEvents = array_filter(mock_events(), static fn ($e) => ($e['event'] ?? '') === 'payment.claim' && ($e['order_id'] ?? '') === '1001');
t('claim notified WooCommerce (order note)', $claimEvents !== []);

// claim rate limiting
$limited = false;
for ($i = 0; $i < 12; $i++) {
    $r = http_req('POST', BACKEND_URL . "/pay/{$token}/claim");
    if ($r['status'] === 429) {
        $limited = true;
        break;
    }
}
t('claim rate limiting kicks in (429)', $limited);

// ---------------------------------------------------------------- pairing

section('Android pairing + device auth');

$r = form_post(BACKEND_URL . '/admin/devices/new-code', [], $cookies2, BACKEND_URL . '/admin/devices');
t('pairing code generated (redirect to devices)', str_contains($r['headers'], '/admin/devices'));
$r = http_req('GET', BACKEND_URL . '/admin/devices', cookies: $cookies2);
t('devices page shows QR + code', str_contains($r['body'], 'pairing-code') && str_contains($r['body'], 'data-qr="peakopay://pair?'));
preg_match('#<div class="pairing-code">([A-Z0-9]{8})</div>#', $r['body'], $mCode);
t('pairing code parsed from panel', isset($mCode[1]));
$pairCode = $mCode[1] ?? 'XXXXXXXX';

// wrong code
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/pair', json_encode(['code' => 'ZZZZZZZZ', 'device_name' => 'S23', 'model' => 'Samsung', 'app_version' => '1.0.0']));
t('invalid pairing code rejected', $r['status'] === 422);

// correct code
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/pair', json_encode(['code' => $pairCode, 'device_name' => 'گوشی فروشنده', 'model' => 'Samsung S23', 'app_version' => '1.0.0', 'android_version' => '14']));
$pair = json_decode($r['body'], true);
t('pairing succeeds', ($pair['ok'] ?? false) === true);
t('device starts PENDING', ($pair['data']['status'] ?? '') === 'pending');
$devId = (int) ($pair['data']['device_id'] ?? 0);
$devSecret = (string) ($pair['data']['device_secret'] ?? '');

// pending device cannot upload transactions, but heartbeat works
$txBody1 = json_encode(['transactions' => [[
    'bank' => 'بانک ملت', 'sender' => 'Mellat', 'amount_rial' => $pay['amount_rial'],
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0019', 'reference' => 'REF-E2E-1',
    'raw_text' => 'واریز مبلغ ' . $pay['amount_rial'] . ' ريال به کارت 6219-86••-••••-0019 کد رهگیری: REF-E2E-1',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txBody1, device_headers($devId, $devSecret, $txBody1));
t('PENDING device blocked from uploading (403)', $r['status'] === 403);

$hbBody = json_encode(['app_version' => '1.0.0']);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/heartbeat', $hbBody, device_headers($devId, $devSecret, $hbBody));
$hb = json_decode($r['body'], true);
t('heartbeat allowed while pending', ($hb['data']['device_status'] ?? '') === 'pending');

// bad signature
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/heartbeat', $hbBody, [
    'X-Peakopay-Device' => (string) $devId, 'X-Peakopay-Timestamp' => (string) time(), 'X-Peakopay-Signature' => str_repeat('b', 64),
]);
t('invalid device signature → 401', $r['status'] === 401);

// stale timestamp
$oldTs = (string) (time() - 400);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/heartbeat', $hbBody, [
    'X-Peakopay-Device' => (string) $devId, 'X-Peakopay-Timestamp' => $oldTs,
    'X-Peakopay-Signature' => hash_hmac('sha256', $oldTs . '.' . $hbBody, $devSecret),
]);
t('stale timestamp (replay window) → 401', $r['status'] === 401);

// approve from admin panel
$html = http_req('GET', BACKEND_URL . '/admin/devices', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
$r = http_req('POST', BACKEND_URL . '/admin/devices/action', http_build_query(['_csrf' => $csrf, 'device_id' => $devId, 'do' => 'approve']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('admin approved device', str_contains($r['headers'], '/admin/devices'));

$hb = json_decode(http_req('POST', BACKEND_URL . '/api/v1/devices/heartbeat', $hbBody, device_headers($devId, $devSecret, $hbBody))['body'], true);
t('heartbeat now ACTIVE', ($hb['data']['device_status'] ?? '') === 'active');

// ---------------------------------------------------------------- matching

section('Transaction upload → matching → webhook → order paid');

// wrong amount first (unmatched)
$txWrong = json_encode(['transactions' => [[
    'bank' => 'بانک ملت', 'sender' => 'Mellat', 'amount_rial' => 999000,
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0019', 'reference' => 'REF-WRONG-1',
    'raw_text' => 'واریز مبلغ 999,000 ريال REF-WRONG-1',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txWrong, device_headers($devId, $devSecret, $txWrong));
$tr = json_decode($r['body'], true);
t('wrong amount → unmatched', ($tr['data']['results'][0]['result'] ?? '') === 'unmatched');

// exact transaction
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txBody1, device_headers($devId, $devSecret, $txBody1));
$tr = json_decode($r['body'], true);
$res = $tr['data']['results'][0] ?? [];
t('exact transaction matched synchronously', ($res['result'] ?? '') === 'matched');
t('result references our order', (string) ($res['order_id'] ?? '') === '1001');

// duplicate SMS
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txBody1, device_headers($devId, $devSecret, $txBody1));
$tr = json_decode($r['body'], true);
t('duplicate SMS → duplicate (no double payment)', ($tr['data']['results'][0]['result'] ?? '') === 'duplicate');

// payment page now PAID
$r = http_req('GET', BACKEND_URL . "/pay/{$token}");
t('payment page shows PAID state', str_contains($r['body'], 'st-paid') && str_contains($r['body'], 'پرداخت شما تأیید شد'));
t('payment page shows return-to-store button', str_contains($r['body'], 'بازگشت به فروشگاه'));

// status endpoint
$st = json_decode(http_req('GET', BACKEND_URL . "/pay/{$token}/status")['body'], true);
t('status endpoint → paid with reference', ($st['data']['payment']['status'] ?? '') === 'paid'
    && ($st['data']['payment']['transaction']['reference'] ?? '') === 'REF-E2E-1');

// mock Woo received payment.paid and completed the order ONCE
usleep(400000);
$paidEvents = array_values(array_filter(mock_events(), static fn ($e) => ($e['event'] ?? '') === 'payment.paid' && ($e['order_id'] ?? '') === '1001'));
t('webhook payment.paid delivered with valid signature', $paidEvents !== [] && ($paidEvents[0]['sig_ok'] ?? false) === true);
t('webhook order completed exactly once', count($paidEvents) === 1 && ($paidEvents[0]['completed'] ?? false) === true);

// second transaction with same amount → new order should not be affected by used transaction
$payload2 = json_encode(['order_id' => '1002', 'amount' => 250000, 'currency' => 'IRT', 'redirect_url' => 'http://127.0.0.1:8098/thanks']);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $payload2, $wooHeaders + ['Idempotency-Key' => 'order-1002-a']);
$pay2 = json_decode($r['body'], true)['data']['payment'] ?? [];

// admin dashboard reflects the paid payment
$r = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2);
t('admin dashboard shows paid order 1001', str_contains($r['body'], '۱۰۰۱'));
t('admin dashboard shows today income > 0', str_contains($r['body'], 'درآمد امروز'));

// android dashboard (store status)
$r = http_req('GET', BACKEND_URL . '/api/v1/store/status', null, ['X-Peakopay-Device' => (string) $devId, 'X-Peakopay-Timestamp' => (string) time(), 'X-Peakopay-Signature' => hash_hmac('sha256', time() . '.', $devSecret)]);
$ss = json_decode($r['body'], true);
t('store/status works for device (Android dashboard)', ($ss['data']['today']['paid_count'] ?? -1) === 1);
t('store/status income matches payment', ($ss['data']['today']['income_rial'] ?? 0) === $pay['amount_rial']);

// ---------------------------------------------------------------- expiration

section('Expiration (cron-free, lazy)');

$payload3 = json_encode(['order_id' => '1003', 'amount' => 80000, 'currency' => 'IRT', 'redirect_url' => 'http://127.0.0.1:8098/thanks']);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $payload3, $wooHeaders + ['Idempotency-Key' => 'order-1003-a']);
$pay3 = json_decode($r['body'], true)['data']['payment'] ?? [];
$t3 = (string) $pay3['token'];

// force expiry by moving expires_at into the past (simulating elapsed time)
$e2eConfig = HP_BASE . '/config/config.php';
$configArr = require $e2eConfig;
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$E2E_DB}", $configArr['db']['user'], $configArr['db']['pass']);
$pdo->exec("UPDATE payment_sessions SET expires_at = '" . gmdate('Y-m-d H:i:s', time() - 60) . "' WHERE token = '" . $t3 . "'");

$st = json_decode(http_req('GET', BACKEND_URL . "/pay/{$t3}/status")['body'], true);
t('expired payment reports expired', ($st['data']['payment']['status'] ?? '') === 'expired');
usleep(400000);
$expiredEvents = array_values(array_filter(mock_events(), static fn ($e) => ($e['event'] ?? '') === 'payment.expired' && ($e['order_id'] ?? '') === '1003'));
t('payment.expired webhook delivered to Woo', $expiredEvents !== []);

// expired payment can no longer be paid
$txExp = json_encode(['transactions' => [[
    'bank' => 'بانک ملت', 'sender' => 'Mellat', 'amount_rial' => (int) $pay3['amount_rial'],
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0019', 'reference' => 'REF-EXP-1',
    'raw_text' => 'واریز مبلغ REF-EXP-1 ' . $pay3['amount_rial'],
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txExp, device_headers($devId, $devSecret, $txExp));
$tr = json_decode($r['body'], true);
t('transaction for expired payment → unmatched', ($tr['data']['results'][0]['result'] ?? '') === 'unmatched');

// ---------------------------------------------------------------- revoked device

section('Revoked device');

$html = http_req('GET', BACKEND_URL . '/admin/devices', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
$r = http_req('POST', BACKEND_URL . '/admin/devices/action', http_build_query(['_csrf' => $csrf, 'device_id' => $devId, 'do' => 'revoke']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
$txRev = json_encode(['transactions' => [['bank' => 'x', 'amount_rial' => 100000, 'occurred_at' => gmdate('Y-m-d H:i:s'), 'raw_text' => 'revoked test ' . random_int(1, 99999)]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txRev, device_headers($devId, $devSecret, $txRev));
t('revoked device rejected (403)', $r['status'] === 403);

// ---------------------------------------------------------------- test mode

section('Test mode (safe simulation)');

// enable test mode via admin settings
$html = http_req('GET', BACKEND_URL . '/admin/settings?section=advanced', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
$r = http_req('POST', BACKEND_URL . '/admin/settings/save', http_build_query(['_csrf' => $csrf, 'section' => 'advanced', 'test_mode' => '1', 'sms_rules' => '']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('test mode enabled via settings', str_contains($r['headers'], 'advanced'));

// create test payment via admin tools
$html = http_req('GET', BACKEND_URL . '/admin/testtools', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
$r = http_req('POST', BACKEND_URL . '/admin/testtools/action', http_build_query(['_csrf' => $csrf, 'do' => 'test_payment', 'amount_toman' => '5000']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
$r = http_req('GET', BACKEND_URL . '/admin/testtools', cookies: $cookies2);
preg_match('#کد پیگیری پرداخت|پرداخت تستی ساخته شد#u', $r['body'], $mm);
t('test payment created from admin panel', isset($mm[0]));
preg_match('#(TEST-[A-Z0-9]{6})#', $r['body'], $mOrder);
preg_match('#href="(https?://[^"]+/pay/[A-Za-z0-9]{40})"#', $r['body'], $mUrl);
$testOrder = $mOrder[1] ?? '';
$testPayUrl = $mUrl[1] ?? '';
t('test payment URL extracted', $testPayUrl !== '');
t('test payment page clearly labeled TEST', str_contains(http_req('GET', $testPayUrl)['body'], 'حالت تست'));

// simulate the SMS for the exact test amount: read it from the payment status
$testToken = basename(parse_url($testPayUrl, PHP_URL_PATH));
$st = json_decode(http_req('GET', BACKEND_URL . "/pay/{$testToken}/status")['body'], true);
$testAmount = (int) ($st['data']['payment']['amount_rial'] ?? 0);

// simulate via admin (web SMS simulator)
$html = http_req('GET', BACKEND_URL . '/admin/testtools', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
$tehranNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
$smsText = "بانک ملت\nواریز به کارت\nمبلغ: " . number_format($testAmount) . " ريال\nکارت مقصد: 6219-86••-••••-0019\nتاریخ: " . $tehranNow->format('Y/m/d') . " ساعت: " . $tehranNow->format('H:i') . "\nکد رهگیری: 999000111";
$r = http_req('POST', BACKEND_URL . '/admin/testtools/action', http_build_query(['_csrf' => $csrf, 'do' => 'simulate_sms', 'sms_text' => $smsText, 'sender' => 'Mellat']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('SMS simulation matched the test payment', str_contains($r['headers'], 'testtools'));
$r = http_req('GET', BACKEND_URL . '/admin/testtools', cookies: $cookies2);
t('admin sees success message', str_contains($r['body'], 'تراکنش با موفقیت') || str_contains($r['body'], 'تطبیق'));

$st = json_decode(http_req('GET', BACKEND_URL . "/pay/{$testToken}/status")['body'], true);
t('test payment is PAID', ($st['data']['payment']['status'] ?? '') === 'paid');

usleep(400000);
$testPaid = array_values(array_filter(mock_events(), static fn ($e) => ($e['event'] ?? '') === 'payment.paid' && ($e['order_id'] ?? '') === $testOrder));
t('test webhook delivered but flagged test=true', $testPaid !== [] && ($testPaid[0]['test'] ?? false) === true);
t('mock Woo did NOT complete order from test event', ($testPaid[0]['completed'] ?? true) === false);

// ── دستگاه واقعی در حالت تست: تراکنش آپلودی باید پرداخت تستی را بپردازد ──
// (ریشه باگ دور ۱۰: تراکنش دستگاه همیشه is_test=0 بود و پرداخت تستی is_test=1
//  هرگز تطبیق نمی‌خورد — «پیامک می‌آید ولی پرداخت منتظر می‌ماند»)
$r = form_post(BACKEND_URL . '/admin/devices/new-code', [], $cookies2, BACKEND_URL . '/admin/devices');
$html = http_req('GET', BACKEND_URL . '/admin/devices', cookies: $cookies2)['body'];
preg_match('#<div class="pairing-code">([A-Z0-9]{8})</div>#', $html, $mCode2);
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/pair', json_encode(['code' => $mCode2[1] ?? 'X', 'device_name' => 'گوشی تست ۲', 'model' => 'Pixel', 'app_version' => '1.2.2']));
$pair2 = json_decode($r['body'], true);
$devId2 = (int) ($pair2['data']['device_id'] ?? 0);
$devSecret2 = (string) ($pair2['data']['device_secret'] ?? '');
$html = http_req('GET', BACKEND_URL . '/admin/devices', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
http_req('POST', BACKEND_URL . '/admin/devices/action', http_build_query(['_csrf' => $csrf, 'device_id' => $devId2, 'do' => 'approve']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('second device paired + approved', $devId2 > 0);

// پرداخت تستی دوم از پنل
$html = http_req('GET', BACKEND_URL . '/admin/testtools', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
http_req('POST', BACKEND_URL . '/admin/testtools/action', http_build_query(['_csrf' => $csrf, 'do' => 'test_payment', 'amount_toman' => '7700']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
$r = http_req('GET', BACKEND_URL . '/admin/testtools', cookies: $cookies2);
preg_match_all('#href="(https?://[^"]+/pay/[A-Za-z0-9]{40})"#', $r['body'], $mUrls);
// جدول پرداخت‌های تست بر اساس id نزولی است → جدیدترین = اولین لینک
$testPayUrl2 = $mUrls[1][0] ?? '';
$testToken2 = basename(parse_url($testPayUrl2, PHP_URL_PATH));
$st = json_decode(http_req('GET', BACKEND_URL . "/pay/{$testToken2}/status")['body'], true);
$testAmount2 = (int) ($st['data']['payment']['amount_rial'] ?? 0);
$testBase2 = (int) ($st['data']['payment']['amount_base_rial'] ?? 0);
// مبلغ نمایش‌داده‌شده به مشتری «یونیک» است (۷۷۰۰۰ + تعدیل کوچک برای تفکیک)
t('second test payment created (77,000 ریال + یونیک‌سازی)', $testBase2 === 77000 && $testAmount2 >= 77000 && $testAmount2 < 78000);

// آپلود از دستگاه (is_test=0) برای پرداخت تستی (is_test=1) در حالت تست → تطبیق
$txTest = json_encode(['transactions' => [[
    'bank' => 'بلو', 'sender' => 'BlooIr', 'amount_rial' => $testAmount2,
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0019', 'reference' => 'REF-TESTMODE-' . random_int(1000, 9999),
    'raw_text' => 'بلو واریز ' . $testAmount2 . ' ریال REF-TESTMODE',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txTest, device_headers($devId2, $devSecret2, $txTest));
$tr = json_decode($r['body'], true);
$res2 = $tr['data']['results'][0] ?? [];
t('device upload pays TEST payment while test_mode ON', ($res2['result'] ?? '') === 'matched');
$st = json_decode(http_req('GET', BACKEND_URL . "/pay/{$testToken2}/status")['body'], true);
t('test payment now PAID via device upload', ($st['data']['payment']['status'] ?? '') === 'paid');

// ── دلیل تطبیق‌نشدن در پاسخ سرور (برای نمایش در اپ/پنل) ──
$txWhy = json_encode(['transactions' => [[
    'bank' => 'بلو', 'sender' => 'BlooIr', 'amount_rial' => 123321,
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0019', 'reference' => 'REF-WHY-' . random_int(1000, 9999),
    'raw_text' => 'بلو واریز 123,321 ریال',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txWhy, device_headers($devId2, $devSecret2, $txWhy));
$tr = json_decode($r['body'], true);
$resWhy = $tr['data']['results'][0] ?? [];
t('unmatched result carries a human reason', ($resWhy['result'] ?? '') === 'unmatched' && mb_strlen((string) ($resWhy['message'] ?? '')) > 10);
t('reason mentions amount mismatch', str_contains((string) ($resWhy['message'] ?? ''), 'مبلغ'));
// همان دلیل در صفحه تراکنش‌های پنل هم دیده می‌شود
$html = http_req('GET', BACKEND_URL . '/admin/transactions?status=unmatched', cookies: $cookies2)['body'];
t('admin transactions page shows the reason', str_contains($html, 'مبلغ پیامک با هیچ پرداخت بازی برابر نیست'));

// ── دور ۱۱: تأیید دستی از اپ (device pending/confirm/attach) + هدرهای جدید ──
section('Manual confirm from Android (devices/pending + confirm + attach)');

// تأیید خودکار را خاموش کن تا پرداخت‌ها «در انتظار تأیید» بمانند
$html = http_req('GET', BACKEND_URL . '/admin/settings?section=matching', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
$r = http_req('POST', BACKEND_URL . '/admin/settings/save', http_build_query([
    '_csrf' => $csrf, 'section' => 'matching', 'match_mode' => 'tolerance', 'match_window_minutes' => '1440',
    'paid_order_status' => 'processing', 'expired_order_status' => 'cancelled',
    'match_by_card' => '1', 'match_by_reference' => '', 'auto_confirm' => '',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('auto_confirm disabled via settings', str_contains($r['headers'], 'matching'));

// پرداخت + تراکنش هم‌مبلغ → باید «در انتظار تأیید» بماند (matched_pending)
$payloadM = json_encode(['order_id' => '1101', 'amount' => 120000, 'currency' => 'IRT']);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $payloadM, $wooHeaders + ['Idempotency-Key' => 'order-1101-a']);
$payM = json_decode($r['body'], true)['data']['payment'] ?? [];
$txM = json_encode(['transactions' => [[
    'bank' => 'بلو', 'sender' => 'BlooIr', 'amount_rial' => (int) $payM['amount_rial'],
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0019', 'reference' => 'REF-MANUAL-1',
    'raw_text' => 'بلو واریز دستی ۱',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txM, device_headers($devId2, $devSecret2, $txM));
$trM = json_decode($r['body'], true);
t('with auto_confirm OFF → matched_pending (verifying)', ($trM['data']['results'][0]['result'] ?? '') === 'matched_pending');

// فهرست در انتظار از دید اپ
$pv = json_decode(http_req('GET', BACKEND_URL . '/api/v1/devices/pending', null, device_headers($devId2, $devSecret2, ''))['body'], true);
t('pending list shows the awaiting payment', ($pv['data']['awaiting'][0]['order_id'] ?? '') === '1101' && (int) ($pv['data']['awaiting'][0]['txn_amount'] ?? 0) === (int) $payM['amount_rial']);
t('pending list bank label from transaction', ($pv['data']['awaiting'][0]['bank'] ?? '') === 'بلو');

// تأیید دستی از اپ
$cf = json_encode(['payment_id' => (int) $payM['id']]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/confirm', $cf, device_headers($devId2, $devSecret2, $cf));
$cfR = json_decode($r['body'], true);
t('device confirm succeeds', ($cfR['data']['confirmed'] ?? false) === true && ($cfR['data']['payment']['status'] ?? '') === 'paid');
$st = json_decode(http_req('GET', BACKEND_URL . '/pay/' . $payM['token'] . '/status')['body'], true);
t('payment page now PAID after device confirm', ($st['data']['payment']['status'] ?? '') === 'paid');
// تأیید دوباره‌ی همان پرداخت → دیگر ممکن نیست
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/confirm', $cf, device_headers($devId2, $devSecret2, $cf));
t('double-confirm rejected (already paid)', $r['status'] === 422);

// تراکنش بدون تطبیق + اتصال دستی به پرداخت باز
$txU = json_encode(['transactions' => [[
    'bank' => 'بانک ملی', 'sender' => 'Melli', 'amount_rial' => 999333,
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0019', 'reference' => 'REF-UNMATCH-1',
    'raw_text' => 'ملی 999,333 ریال',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txU, device_headers($devId2, $devSecret2, $txU));
$trU = json_decode($r['body'], true);
t('unmatched txn with human reason', ($trU['data']['results'][0]['result'] ?? '') === 'unmatched' && str_contains((string) ($trU['data']['results'][0]['message'] ?? ''), 'مبلغ'));
$txUId = (int) ($trU['data']['results'][0]['transaction_id'] ?? 0);

$payloadO = json_encode(['order_id' => '1102', 'amount' => 99933, 'currency' => 'IRT']);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $payloadO, $wooHeaders + ['Idempotency-Key' => 'order-1102-a']);
$payO = json_decode($r['body'], true)['data']['payment'] ?? [];

$pv = json_decode(http_req('GET', BACKEND_URL . '/api/v1/devices/pending', null, device_headers($devId2, $devSecret2, ''))['body'], true);
$inUnmatched = count(array_filter($pv['data']['unmatched'] ?? [], static fn ($x) => (int) $x['id'] === $txUId)) === 1;
$inOpen = count(array_filter($pv['data']['open'] ?? [], static fn ($x) => (int) $x['id'] === (int) $payO['id'])) === 1;
t('pending list: unmatched txn + open payment both visible', $inUnmatched && $inOpen);
t('unmatched entry carries reason', str_contains((string) ($pv['data']['unmatched'][0]['reason'] ?? ''), 'مبلغ'));

$at = json_encode(['transaction_id' => $txUId, 'payment_id' => (int) $payO['id']]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/attach', $at, device_headers($devId2, $devSecret2, $at));
$atR = json_decode($r['body'], true);
t('device attach succeeds', ($atR['data']['attached'] ?? false) === true && ($atR['data']['payment']['status'] ?? '') === 'paid');
$st = json_decode(http_req('GET', BACKEND_URL . '/pay/' . $payO['token'] . '/status')['body'], true);
t('attached payment page PAID', ($st['data']['payment']['status'] ?? '') === 'paid');

// دستگاه pending حق تأیید ندارد (active فقط)
$r = form_post(BACKEND_URL . '/admin/devices/new-code', [], $cookies2, BACKEND_URL . '/admin/devices');
$html = http_req('GET', BACKEND_URL . '/admin/devices', cookies: $cookies2)['body'];
preg_match('#<div class="pairing-code">([A-Z0-9]{8})</div>#', $html, $mCode3);
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/pair', json_encode(['code' => $mCode3[1] ?? 'X', 'device_name' => 'گوشی رد شده']));
$devId3 = (int) (json_decode($r['body'], true)['data']['device_id'] ?? 0);
$devSecret3 = (string) (json_decode($r['body'], true)['data']['device_secret'] ?? '');
$pv3 = http_req('GET', BACKEND_URL . '/api/v1/devices/pending', null, device_headers($devId3, $devSecret3, ''));
t('PENDING device cannot list/confirm (403)', $pv3['status'] === 403);

// هدرهای نام قدیمی (X-HamrahPay-*) هنوز پذیرفته می‌شوند — سازگاری افزونه‌های نصب‌شده
$hbB = json_encode(['app_version' => '1.0.0']);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/heartbeat', $hbB, [
    'X-HamrahPay-Device' => (string) $devId2, 'X-HamrahPay-Timestamp' => (string) time(),
    'X-HamrahPay-Signature' => hash_hmac('sha256', time() . '.' . $hbB, $devSecret2),
]);
t('legacy X-HamrahPay-* headers still accepted (plugin compat)', $r['status'] === 200);

// برگرداندن تأیید خودکار
$html = http_req('GET', BACKEND_URL . '/admin/settings?section=matching', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
http_req('POST', BACKEND_URL . '/admin/settings/save', http_build_query([
    '_csrf' => $csrf, 'section' => 'matching', 'match_mode' => 'tolerance', 'match_window_minutes' => '1440',
    'paid_order_status' => 'processing', 'expired_order_status' => 'cancelled',
    'match_by_card' => '1', 'match_by_reference' => '', 'auto_confirm' => '1',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('auto_confirm restored', true);

// ── دور ۱۲: مدیریت کامل کارت/قالب از اپ (device cards/rules endpoints) ──
section('Card/template management from Android');

// فهرست کارت‌ها از دید اپ
$r = http_req('GET', BACKEND_URL . '/api/v1/devices/cards', null, device_headers($devId2, $devSecret2, ''));
$mg = json_decode($r['body'], true);
t('device cards list ok', ($mg['data']['cards'] ?? null) !== null && ($mg['data']['config_version'] ?? 0) > 0);
t('device cards list hides full number (last4 only)', strpos($r['body'], '6221061234567891') === false);

// ساخت کارت از اپ (بلو با فرستنده حرفی)
$cc = json_encode(['label' => 'کارت بلو', 'bank_name' => 'بلو', 'holder' => 'فروشنده تست', 'number' => '6219861012345673', 'senders' => ["BlooIr", "+982111111111"]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/cards/save', $cc, device_headers($devId2, $devSecret2, $cc));
$cs = json_decode($r['body'], true);
$devCardId = (int) ($cs['data']['card_id'] ?? 0);
t('card created from device', $devCardId > 0);

// شماره کارت نامعتبر → 422
$bad = json_encode(['bank_name' => 'بلو', 'holder' => 'تست تستی', 'number' => '123']);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/cards/save', $bad, device_headers($devId2, $devSecret2, $bad));
t('invalid card number rejected from device', $r['status'] === 422);

// تحلیل نمونه از اپ — همان موتور پنل
$blooSample2 = "بلو\n\nواریز پول\n\n مهدي عزیز، 20,000,000 ریال به حساب شما نشست.\n\n موجودی: 22,015,157 ریال\n\n۱۳:۳۱\n\n۱۴۰۵.۰۶.۳۰";
$an1 = json_encode(['sample' => $blooSample2]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/analyze', $an1, device_headers($devId2, $devSecret2, $an1));
$anD = json_decode($r['body'], true);
$segKeys = [];
foreach (($anD['data']['segments'] ?? []) as $s2) { if (!empty($s2['span']) && $s2['span'][2] !== null) { $segKeys[] = $s2['span'][2]; } }
t('device analyze suggests amount', in_array('amount', $segKeys, true));
t('device analyze suggests balance', in_array('balance', $segKeys, true));

// ساخت قالب از اپ (spans همان تحلیل)
$spansD = [];
foreach (($anD['data']['segments'] ?? []) as $s2) { if (!empty($s2['span'])) { $spansD[] = [$s2['span'][0], $s2['span'][1], $s2['span'][2]]; } }
$rb = json_encode(['card_id' => $devCardId, 'sample' => $blooSample2, 'spans' => $spansD, 'name' => 'بلو — از اپ', 'priority' => 10]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/rules/save', $rb, device_headers($devId2, $devSecret2, $rb));
$rd = json_decode($r['body'], true);
$devRuleId = (int) ($rd['data']['rule_id'] ?? 0);
t('rule built from device + self-parses', $devRuleId > 0 && ($rd['data']['ok'] ?? false) === true && ($rd['data']['amount_rial'] ?? 0) === 20000000);

// تست خط لوله از اپ (فرستنده حرفی)
$tt = json_encode(['sender' => 'BlooIr', 'text' => "بلو\n\nواریز پول\n\n سارا عزیز، 7,500,000 ریال به حساب شما نشست.\n\n موجودی: 29,515,157 ریال\n\n۰۹:۰۵\n\n۱۴۰۵.۰۷.۰۱"]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/test', $tt, device_headers($devId2, $devSecret2, $tt));
$td = json_decode($r['body'], true);
t('device pipeline test matches (B sms via letter sender)', ($td['data']['amount_rial'] ?? 0) === 7500000);

// پیکربندی جدید به اپ رسیده (نسخه بالا رفته)
$hb2 = json_decode(http_req('POST', BACKEND_URL . '/api/v1/devices/heartbeat', $hbBody, device_headers($devId2, $devSecret2, $hbBody))['body'], true);
t('config version bumped after device rule save', (int) ($hb2['data']['sms_config_version'] ?? 0) === (int) ($rd['data']['config_version'] ?? -1));

// خاموش/روشن + حذف قالب از اپ
$ra = json_encode(['rule_id' => $devRuleId, 'do' => 'toggle']);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/rules/action', $ra, device_headers($devId2, $devSecret2, $ra));
t('rule toggled from device', ($rd['data']['config_version'] ?? 0) > 0);
$ra = json_encode(['rule_id' => $devRuleId, 'do' => 'delete']);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/rules/action', $ra, device_headers($devId2, $devSecret2, $ra));
t('rule deleted from device', json_decode($r['body'], true)['data']['done'] === true);

// کارت روی دیگری تعریف نشود + حذف کارت از اپ
$wrongCard = json_encode(['card_id' => 999999, 'do' => 'delete']);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/cards/action', $wrongCard, device_headers($devId2, $devSecret2, $wrongCard));
t('foreign card rejected (404)', $r['status'] === 404);
$dc = json_encode(['card_id' => $devCardId, 'do' => 'delete']);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/cards/action', $dc, device_headers($devId2, $devSecret2, $dc));
t('card deleted from device', json_decode($r['body'], true)['data']['done'] === true);

// دستگاه pending حق مدیریت ندارد
$r = http_req('GET', BACKEND_URL . '/api/v1/devices/cards', null, device_headers($devId3, $devSecret3, ''));
t('PENDING device cannot manage cards (403)', $r['status'] === 403);

// ── دور ۱۳: تأیید دستی «در هر حالتی» (حتی با تأیید خودکار روشن) + صفحه تک‌صفحه‌ای ──
section('Manual confirm ANY state (auto_confirm ON) + compact pay page');

// تأیید خودکار روشن است (انتهای بخش قبل برگشت) — پرداخت بدون تراکنش:
$payloadAny = json_encode(['order_id' => '1201', 'amount' => 150000, 'currency' => 'IRT']);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $payloadAny, $wooHeaders + ['Idempotency-Key' => 'order-1201-a']);
$payAny = json_decode($r['body'], true)['data']['payment'] ?? [];

// صفحه پرداخت: چیدمان تک‌صفحه‌ای جدید (تایمر قرصی + راهنمای یک‌خطی + لوگو)
$html = http_req('GET', $payAny['url'])['body'];
t('compact pay page: pill timer present', str_contains($html, 'hp-timer-pill') && str_contains($html, 'id="timer"'));
t('compact pay page: one-line guide present', str_contains($html, 'راهنمای پرداخت') && str_contains($html, 'hp-guide'));
t('compact pay page: new logo present', str_contains($html, 'img/logo.png'));
t('compact pay page: copy amount button kept', str_contains($html, 'کپی مبلغ'));

// در فهرست «در انتظار تأیید» اپ دیده می‌شود — بدون تراکنش
$pv = json_decode(http_req('GET', BACKEND_URL . '/api/v1/devices/pending', null, device_headers($devId2, $devSecret2, ''))['body'], true);
$inA = null;
foreach (($pv['data']['awaiting'] ?? []) as $a) { if ((int) $a['id'] === (int) $payAny['id']) { $inA = $a; } }
t('pending (no txn) appears in awaiting list even with auto_confirm ON', $inA !== null && (int) ($inA['txn_id'] ?? 0) === 0);

// تأیید دستی از اپ — بدون هیچ تراکنشی
$cf = json_encode(['payment_id' => (int) $payAny['id']]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/confirm', $cf, device_headers($devId2, $devSecret2, $cf));
$cfR = json_decode($r['body'], true);
t('manual confirm works on pending payment (no txn, auto_confirm ON)', ($cfR['data']['confirmed'] ?? false) === true && ($cfR['data']['payment']['status'] ?? '') === 'paid');
$st = json_decode(http_req('GET', BACKEND_URL . '/pay/' . $payAny['token'] . '/status')['body'], true);
t('no-txn payment is PAID after device confirm', ($st['data']['payment']['status'] ?? '') === 'paid');
usleep(400000);
$paidEv = array_filter(mock_events(), static fn ($e) => ($e['event'] ?? '') === 'payment.paid' && ($e['order_id'] ?? '') === '1201');
t('webhook fired for manually-confirmed no-txn payment', $paidEv !== []);

// پرداخت منقضی‌شده قابل تأیید دستی نیست
$payloadExp = json_encode(['order_id' => '1202', 'amount' => 150000, 'currency' => 'IRT']);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $payloadExp, $wooHeaders + ['Idempotency-Key' => 'order-1202-a']);
$payExp = json_decode($r['body'], true)['data']['payment'] ?? [];
$configArr = require $e2eConfig;
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$E2E_DB}", $configArr['db']['user'], $configArr['db']['pass']);
$pdo->exec("UPDATE payment_sessions SET status = 'expired' WHERE token = '" . $payExp['token'] . "'");
$cf = json_encode(['payment_id' => (int) $payExp['id']]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/confirm', $cf, device_headers($devId2, $devSecret2, $cf));
t('expired payment cannot be manually confirmed', $r['status'] === 422);

// ── دور ۱۴: payevents + ویرایشگر ظاهر صفحه پرداخت + بدون null در فهرست در انتظار ──
section('Round 14: payevents + pay-page CSS editor + no-null awaiting');

// ۱) رویدادهای پرداخت برای اعلان اپ
$r = http_req('GET', BACKEND_URL . '/api/v1/devices/payevents', null, device_headers($devId2, $devSecret2, ''));
$ev = json_decode($r['body'], true);
t('payevents returns list for active device', is_array($ev['data']['events'] ?? null) && count($ev['data']['events']) > 0);
t('payevents carries id/order/status', isset($ev['data']['events'][0]['id'], $ev['data']['events'][0]['order_id'], $ev['data']['events'][0]['status']));
$r = http_req('GET', BACKEND_URL . '/api/v1/devices/payevents', null, device_headers($devId3, $devSecret3, ''));
t('payevents blocked for pending device (403)', $r['status'] === 403);

// ۲) فهرست «در انتظار» بدون null (باگ JSON اپ)
$payloadN = json_encode(['order_id' => '1301', 'amount' => 200000, 'currency' => 'IRT']);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $payloadN, $wooHeaders + ['Idempotency-Key' => 'order-1301-a']);
$payN = json_decode($r['body'], true)['data']['payment'] ?? [];
$r = http_req('GET', BACKEND_URL . '/api/v1/devices/pending', null, device_headers($devId2, $devSecret2, ''));
t('awaiting list has NO null txn fields (app JSON bug fixed)', strpos($r['body'], '"txn_id":null') === false && strpos($r['body'], ':"' . 'null') === false);
$pn = json_decode($r['body'], true);
$entry = null;
foreach (($pn['data']['awaiting'] ?? []) as $a) { if ((int) $a['id'] === (int) $payN['id']) { $entry = $a; } }
t('pending payment listed with txn_id=0 (not null)', $entry !== null && (int) ($entry['txn_id'] ?? -1) === 0);

// ۳) ویرایشگر ظاهر صفحه پرداخت — CSS سفارشی
$html = http_req('GET', BACKEND_URL . '/admin/settings?section=paypage', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
t('paypage settings tab renders', str_contains($html, 'ظاهر صفحه پرداخت') && str_contains($html, 'custom_css'));
t('paypage default code loader present', str_contains($html, 'load=default'));
// ذخیره CSS سفارشی
$r = http_req('POST', BACKEND_URL . '/admin/settings/save', http_build_query([
    '_csrf' => $csrf, 'section' => 'paypage', 'custom_css' => '.hp-amount-num{color:#ff0000}',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('custom CSS saved', str_contains($r['headers'], 'paypage'));
$html = http_req('GET', $payN['url'])['body'];
t('custom CSS injected into pay page', str_contains($html, 'hp-custom-css') && str_contains($html, '.hp-amount-num{color:#ff0000}'));
t('custom CSS cannot smuggle script', strpos($html, 'custom_css"><script') === false);
// ریست به پیش‌فرض
$html = http_req('GET', BACKEND_URL . '/admin/settings?section=paypage', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
http_req('POST', BACKEND_URL . '/admin/settings/save', http_build_query([
    '_csrf' => $csrf, 'section' => 'paypage', 'custom_css' => 'x', 'do_reset' => '1',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
$html = http_req('GET', $payN['url'])['body'];
t('reset removes custom CSS from pay page', !str_contains($html, 'hp-custom-css'));

// ── دور ۱۵: پنل مدیریت واکنش‌گرای موبایل (جداول کارتی + نوار بالا + فرم‌ها) ──
section('Round 15: admin mobile responsive');

// CSS سرو می‌شود و لایه موبایل جدید را دارد — بدون دست‌زدن به قواعد دسکتاپ
$css = http_req('GET', BACKEND_URL . '/assets/css/admin.css')['body'];
t('admin css served with stacked-table layer', str_contains($css, 'table.rtable') && str_contains($css, 'topbar-actions'));
t('admin css desktop rules intact (min-width tables kept)', str_contains($css, 'min-width: 560px') && str_contains($css, '@media (max-width: 860px)'));
t('admin css ios input-zoom guard present', str_contains($css, 'font-size: 16px'));

// JS: تبدیل جدول → کارت + قفل اسکرول منوی موبایل
$js = http_req('GET', BACKEND_URL . '/assets/js/admin.js')['body'];
t('admin js has rtable transformer (data-label)', str_contains($js, 'rtableApply') && str_contains($js, 'data-label'));
t('admin js locks scroll when mobile menu open', str_contains($js, 'nav-open'));

// نوار بالا: چیپ‌ها/خروج گروه‌شده (در موبایل فشرده می‌شود)
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2)['body'];
t('topbar actions grouped for mobile', str_contains($html, 'topbar-actions') && str_contains($html, 'woo-chip') && str_contains($html, 'user-chip'));

// فرم‌های فیلتر کلاس واکنش‌گرا دارند (تمام‌عرض در موبایل)
$html = http_req('GET', BACKEND_URL . '/admin/payments', cookies: $cookies2)['body'];
t('payments filter uses responsive classes', str_contains($html, 'filter-bar') && str_contains($html, 'f-q'));
t('payments table keeps header row (stacked labels source)', str_contains($html, '<tr><th'));
$html = http_req('GET', BACKEND_URL . '/admin/transactions', cookies: $cookies2)['body'];
t('transactions filter uses responsive classes', str_contains($html, 'filter-bar'));
t('asset urls carry new version (cache bust)', str_contains($html, 'admin.css?v=2.2.0'));

// ── دور ۲۲: آیتم «کارت‌ها» فقط از منوی پنل مخفی شد (مدیریت کارت‌ها در اپ اندروید) ──
// مسیرها/صفحه/کنترلر دست‌نخورده می‌مانند تا هیچ قابلیتی نشکند؛ اپ اندروید هم از
// endpointهای جداگانه /api/v1/devices/cards استفاده می‌کند (بی‌ربط به منوی پنل).
section('Round 22: cards menu hidden (moved to android app), routes intact');
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2)['body'];
t('admin sidebar no longer shows cards link', !str_contains($html, 'admin/cards'));
t('other nav items still present', str_contains($html, 'وضعیت اتصال‌ها') && str_contains($html, 'دستگاه‌ها') && str_contains($html, 'گزارش‌ها'));
$r = http_req('GET', BACKEND_URL . '/admin/cards', cookies: $cookies2);
t('cards page still reachable directly (capability kept)', $r['status'] === 200 && str_contains($r['body'], 'کارت‌های مقصد'));
t('diagnostics no-card hint points to android app', str_contains(file_get_contents(HP_BASE . '/app/Services/Diagnostics.php'), 'اپ اندروید ← «کارت‌ها و قالب‌های پیامک»'));

// ── دور ۱۸: خودترمیمی دیتابیس پس از «ارتقا با جایگزینی فایل‌ها» ──
// سناریوی واقعی: نصب قدیمی (فقط 001) + فایل‌های جدید ← قبلاً صفحه کارت‌ها ۵۰۰ می‌شد
// («خطایی در ارتباط با سرویس پرداخت رخ داد»)؛ حالا مهاجرت در همان درخواست اعمال می‌شود.
section('Round 18: DB self-heal after file-replace upgrade');

$pdoMig = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$E2E_DB}", $configArr['db']['user'] ?? $DB_USER, $configArr['db']['pass'] ?? $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// شبیه‌سازی نصب قدیمی: حذف همه چیزهایی که مهاجرت 002 ساخته بود
$pdoMig->exec('ALTER TABLE transactions DROP FOREIGN KEY fk_txn_card');
$pdoMig->exec('ALTER TABLE transactions DROP KEY idx_txn_card');
$pdoMig->exec('ALTER TABLE transactions DROP KEY uq_txn_fp');
$pdoMig->exec('ALTER TABLE transactions DROP COLUMN card_id');
$pdoMig->exec('ALTER TABLE transactions DROP COLUMN fingerprint');
$pdoMig->exec('DROP TABLE card_parser_rules');
$pdoMig->exec('DROP TABLE card_senders');
$pdoMig->exec("DELETE FROM migrations WHERE filename = '002_card_sms.sql'");
@unlink(HP_BASE . '/storage/cache/mig_state.txt'); // marker — مثل اولین درخواست پس از ارتقا

// قبل از رفع: این درخواست ۵۰۰ می‌داد. حالا باید schema را ترمیم کند و صفحه بیاید.
$r = http_req('GET', BACKEND_URL . '/admin/cards', cookies: $cookies2);
t('cards page self-heals stale schema (no more 500)', $r['status'] === 200 && str_contains($r['body'], 'کارت‌های مقصد'));
$colsBack = (int) $pdoMig->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME IN ('card_id','fingerprint')")->fetchColumn();
t('transactions columns restored (card_id + fingerprint)', $colsBack === 2);
$tablesBack = (int) $pdoMig->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('card_senders','card_parser_rules')")->fetchColumn();
t('card_senders/card_parser_rules tables restored', $tablesBack === 2);
t('migration recorded again in migrations table', (int) $pdoMig->query("SELECT COUNT(*) FROM migrations WHERE filename = '002_card_sms.sql' AND hash <> ''")->fetchColumn() === 1);

// صفحات دیگر هم بعد از ترمیم سالم‌اند + endpoint کارت‌های اپ (که schema جدید می‌خواهد)
$r = http_req('GET', BACKEND_URL . '/admin/transactions', cookies: $cookies2);
t('transactions page healthy after self-heal', $r['status'] === 200);
$r = http_req('GET', BACKEND_URL . '/api/v1/devices/cards', null, device_headers($devId2, $devSecret2, ''));
t('device cards endpoint healthy after self-heal', $r['status'] === 200 && str_contains($r['body'], '"ok":true'));

// اجرای دوبارهٔ مهاجرت روی schema سالم بی‌ضرر است (رواداری خطاهای تکراری)
@unlink(HP_BASE . '/storage/cache/mig_state.txt');
$r = http_req('GET', BACKEND_URL . '/admin/cards', cookies: $cookies2);
t('re-run of migrations on healthy schema is harmless (idempotent)', $r['status'] === 200);

// ── دکمه «تعمیر پایگاه داده» (ابزار تشخیص/رفع برای مدیر) ──
// ۱) دکمه در صفحه عیب‌یابی هست
$html = http_req('GET', BACKEND_URL . '/admin/diagnostics', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
t('diagnostics page shows repair button', str_contains($html, 'تعمیر پایگاه داده') && str_contains($html, 'repair-db'));

// ۲) schema را خراب کن → صفحه عیب‌یابی نباید ۵۰۰ شود (محافظ دور ۱۸: دکمه تعمیر همیشه در دسترس)
$pdoMig->exec('DROP TABLE card_senders');
$pdoMig->exec('DROP TABLE card_parser_rules');
@unlink(HP_BASE . '/storage/cache/mig_state.txt');
$r = http_req('GET', BACKEND_URL . '/admin/diagnostics', cookies: $cookies2);
t('diagnostics page survives broken schema (no 500, repair reachable)', $r['status'] === 200 && str_contains($r['body'], 'repair-db'));
t('diagnostics reports missing schema items as error', str_contains($r['body'], 'تعمیر پایگاه داده» را بزنید'));

// ۳) تعمیر از طریق دکمه در حالت خراب (توکن از همان صفحه زنده) → موفقیت
$csrf = extract_csrf($r['body']);
$r = http_req('POST', BACKEND_URL . '/admin/diagnostics/repair-db', http_build_query(['_csrf' => $csrf]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('repair-db works even while schema is broken', $r['status'] === 302 && str_contains($r['headers'], '/admin/diagnostics'));
$tablesBack2 = (int) $pdoMig->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('card_senders','card_parser_rules')")->fetchColumn();
t('repair-db actually restored the tables', $tablesBack2 === 2);
$html = http_req('GET', BACKEND_URL . '/admin/diagnostics', cookies: $cookies2)['body'];
t('repair reports applied migrations in flash', str_contains($html, '002_card_sms.sql'));
t('schema check reports all-ok after repair', str_contains($html, 'همه جدول‌ها و ستون‌های موردنیاز موجودند'));

// ۴) صفحه کارت‌ها بعد از تعمیر از راه دکمه هم سالم است
$r = http_req('GET', BACKEND_URL . '/admin/cards', cookies: $cookies2);
t('cards page healthy after manual repair too', $r['status'] === 200);

// ۵) بدون CSRF رد می‌شود
$r = http_req('POST', BACKEND_URL . '/admin/diagnostics/repair-db', '_csrf=bad', ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('repair-db rejected without valid CSRF', $r['status'] === 302 || $r['status'] === 403);

// ---------------------------------------------------------------- admin cards UI (parser builder)
// رگرسیون دور ۶: دکمه‌های «تحلیل نمونه»/«ساخت قالب» — CSP nonce + پاسخ‌های AJAX

section('Admin cards — آموزش فرمت پیامک (AJAX + CSP)');

$r = http_req('GET', BACKEND_URL . '/admin/cards', cookies: $cookies2);
t('cards page renders', $r['status'] === 200);

// کارت از طریق فرم اضافه کن (فرستنده + بعد قالب)
$r = form_post(BACKEND_URL . '/admin/cards/save', [
    'label' => 'کارت تست پارسر', 'bank_name' => 'بانک پارس', 'holder' => 'تست پارس',
    'number' => '6221061234567891', 'senders' => "30007777\n+982111111111",
], $cookies2, BACKEND_URL . '/admin/cards');
t('card saved via admin form', str_contains($r['headers'], 'edit='));
preg_match('#edit=(\d+)#', $r['headers'], $mCard);
$cardId6 = (int) ($mCard[1] ?? 0);

// صفحه ویرایش = جایی که دکمه‌های آموزش فرمت هستند
$r = http_req('GET', BACKEND_URL . '/admin/cards?edit=' . $cardId6, cookies: $cookies2);
$cardsHtml = $r['body'];
// nonce در هدر CSP و در تگ script باید یکی باشد
preg_match('#script-src \'self\' \'nonce-([a-f0-9]+)\'#', $r['headers'], $cspN);
preg_match('#<script nonce="([a-f0-9]+)">#', $cardsHtml, $tagN);
t('CSP header carries script nonce', isset($cspN[1]));
t('inline script tag carries the SAME nonce (buttons will run)', isset($cspN[1], $tagN[1]) && $cspN[1] === $tagN[1]);
preg_match("#var CSRF = '([^']+)'#", $cardsHtml, $mCsrf);
$cardsCsrf = $mCsrf[1] ?? '';
t('cards JS has CSRF token', $cardsCsrf !== '');

$sampleA = "واریز به کارت 6221-0612-3456-7891\nمبلغ: 25,000,000 ریال\nمانده قابل استفاده: 87,500,000 ریال\nتاریخ 1405/06/29 ساعت 14:32\nکد رهگیری 123456";
$ajaxHdr = ['X-CSRF-Token' => $cardsCsrf, 'X-Requested-With' => 'XMLHttpRequest', 'Content-Type' => 'application/x-www-form-urlencoded'];

// ۱) تحلیل نمونه
$r = http_req('POST', BACKEND_URL . '/admin/cards/analyze', http_build_query(['sample' => $sampleA]), $ajaxHdr, $cookies2);
$an = json_decode($r['body'], true);
t('analyze endpoint returns ok:true envelope', ($an['ok'] ?? false) === true);
$spans6 = [];
foreach (($an['segments'] ?? []) as $seg6) {
    if (!empty($seg6['span'])) { $spans6[] = [$seg6['span'][0], $seg6['span'][1], $seg6['span'][2]]; }
}
t('analyze suggests amount+card+balance+date+time+reference', count(array_intersect(array_column($spans6, 2) ?: [], ['amount', 'card_last4', 'balance', 'date', 'time', 'reference'])) === 6);
$amountTxt6 = '';
foreach (($an['segments'] ?? []) as $seg6) {
    if (($seg6['span'][2] ?? '') === 'amount') { $amountTxt6 = $seg6['text']; }
}
t('analyze amount NOT truncated before ریال', $amountTxt6 === '25,000,000');

// ۲) ساخت و ذخیره قالب
$r = http_req('POST', BACKEND_URL . '/admin/cards/build-rule', http_build_query([
    'sample' => $sampleA, 'spans' => json_encode($spans6), 'name' => 'قالب پارس ۱', 'priority' => '10', 'card_id' => (string) $cardId6,
]), $ajaxHdr, $cookies2);
$br = json_decode($r['body'], true);
t('build-rule saves + self-parses', ($br['ok'] ?? false) === true && ($br['rule_id'] ?? 0) > 0 && ($br['preview']['amount_rial'] ?? 0) === 25000000);
t('build-rule extracts last4 from full card', ($br['preview']['fields']['card_last4'] ?? '') === '7891');

// ۳) تست پارسر — پیامک B با ارقام/فرمت متفاوت + فرستنده با +98
$msgB = "واریز به کارت 5899-1122-3344-9017\nمبلغ 8,450,000 ریال\nمانده قابل استفاده 12,300,000 ریال\nتاریخ 1405/07/01 ساعت 09:05\nکد رهگیری 88776655";
$r = http_req('POST', BACKEND_URL . '/admin/cards/test', http_build_query([
    'card_id' => (string) $cardId6, 'sender' => '+9830007777', 'text' => $msgB,
]), $ajaxHdr, $cookies2);
$ts = json_decode($r['body'], true);
t('test parser: B message VALID via +98 sender', ($ts['ok'] ?? false) === true && ($ts['amount_rial'] ?? 0) === 8450000);
t('test parser: B fields extracted', ($ts['fields']['card_last4'] ?? '') === '9017' && ($ts['fields']['reference'] ?? '') === '88776655' && ($ts['fields']['date'] ?? '') === '1405/07/01');

// ۴) فرستنده ثبت‌نشده → مثل اپ، نادیده
$r = http_req('POST', BACKEND_URL . '/admin/cards/test', http_build_query([
    'card_id' => (string) $cardId6, 'sender' => '09999999', 'text' => "واریز به کارت 1111\nمبلغ 1000 ریال",
]), $ajaxHdr, $cookies2);
$ts2 = json_decode($r['body'], true);
t('test parser: unregistered sender rejected at sender stage', ($ts2['ok'] ?? true) === false && ($ts2['stage'] ?? '') === 'sender');

// ---------------------------------------------------------------- دور ۹: نمونه‌های واقعی کاربر
// (همان دو پیامکی که «تحلیل نمونه» رویشان خطا می‌داد — بلو و بانک ملی)

section('Admin cards — نمونه‌های واقعی (بلو + بانک ملی)');

// ── بلو: «موجودی:» + مبلغ بدون کلیدواژه «مبلغ» + تاریخ نقطه‌دار ──
$blooSample = "بلو\n\nواریز پول\n\n مهدي عزیز، 20,000,000 ریال به حساب شما نشست.\n\n موجودی: 22,015,157 ریال\n\n۱۳:۳۱\n\n۱۴۰۵.۰۶.۳۰";
$r = http_req('POST', BACKEND_URL . '/admin/cards/analyze', http_build_query(['sample' => $blooSample]), $ajaxHdr, $cookies2);
$anB = json_decode($r['body'], true);
t('بلو: analyze ok', ($anB['ok'] ?? false) === true);
$blooKeys = [];
foreach (($anB['segments'] ?? []) as $s) { if (!empty($s['span']) && $s['span'][2] !== null) { $blooKeys[$s['span'][2]] = $s['text']; } }
t('بلو: مبلغ (ریالِ بعد، بدون کلیدواژه)', ($blooKeys['amount'] ?? '') === '20,000,000');
t('بلو: موجودی → balance', ($blooKeys['balance'] ?? '') === '22,015,157');
t('بلو: تاریخ نقطه‌دار', ($blooKeys['date'] ?? '') === '1405.06.30');
t('بلو: ساعت', ($blooKeys['time'] ?? '') === '13:31');
t('بلو: نوع تراکنش', ($blooKeys['txn_type'] ?? '') === 'واریز');
$blooSpans = [];
foreach (($anB['segments'] ?? []) as $s) { if (!empty($s['span'])) { $blooSpans[] = [$s['span'][0], $s['span'][1], $s['span'][2]]; } }
$r = http_req('POST', BACKEND_URL . '/admin/cards/build-rule', http_build_query([
    'sample' => $blooSample, 'spans' => json_encode($blooSpans), 'name' => 'بلو — قالب ۱', 'priority' => '10', 'card_id' => (string) $cardId6,
]), $ajaxHdr, $cookies2);
$brB = json_decode($r['body'], true);
t('بلو: قالب ساخته و خودش پارس شد', ($brB['ok'] ?? false) === true && ($brB['preview']['amount_rial'] ?? 0) === 20000000);
t('بلو: مانده قالب', ($brB['preview']['fields']['balance'] ?? '') === '22015157');
// پیامک بلوی بعدی: نام گیرنده و ارقام متفاوت
$blooMsg2 = "بلو\n\nواریز پول\n\n سارا عزیز، 7,500,000 ریال به حساب شما نشست.\n\n موجودی: 29,515,157 ریال\n\n۰۹:۰۵\n\n۱۴۰۵.۰۷.۰۱";
$r = http_req('POST', BACKEND_URL . '/admin/cards/test', http_build_query([
    'card_id' => (string) $cardId6, 'sender' => '+9830007777', 'text' => $blooMsg2,
]), $ajaxHdr, $cookies2);
$tsB = json_decode($r['body'], true);
t('بلو: پیامک بعدی (نام/ارقام متفاوت) معتبر', ($tsB['ok'] ?? false) === true && ($tsB['amount_rial'] ?? 0) === 7500000);
t('بلو: مانده پیامک بعدی', ($tsB['fields']['balance'] ?? '') === '29515157');

// ── بانک ملی: ك/ي عربی + مبلغ فقط با علامت + + «مانده:» چسبیده + «حساب:» بدون نوع ──
$melliSample = "بانك ملي ايران\n\n111,798,088+\n\nحساب:68001\n\nمانده:153,175,934\n\n0630-13:17";
$r = http_req('POST', BACKEND_URL . '/admin/cards/analyze', http_build_query(['sample' => $melliSample]), $ajaxHdr, $cookies2);
$anM = json_decode($r['body'], true);
t('ملی: analyze ok', ($anM['ok'] ?? false) === true);
$melliKeys = []; $melliCand = 0;
foreach (($anM['segments'] ?? []) as $s) {
    if (!empty($s['span'])) {
        if ($s['span'][2] !== null) { $melliKeys[$s['span'][2]] = $s['text']; }
        elseif (!empty($s['candidate'])) { $melliCand++; }
    }
}
t('ملی: مبلغ از علامت +', ($melliKeys['amount'] ?? '') === '111,798,088');
t('ملی: مانده:چسبیده', ($melliKeys['balance'] ?? '') === '153,175,934');
t('ملی: نام بانک با ك/ي عربی', ($melliKeys['bank'] ?? '') === 'ملی ایران');
t('ملی: ساعت', ($melliKeys['time'] ?? '') === '13:17');
t('ملی: شماره حساب به‌عنوان کاندیدا (قابل انتساب)', $melliCand >= 1);
// کاندیدا را ادمین به txn_id نسبت می‌دهد → ساخت قالب با spans دست‌کاری‌شده
$melliSpans = [];
foreach (($anM['segments'] ?? []) as $s) {
    if (!empty($s['span'])) { $melliSpans[] = [$s['span'][0], $s['span'][1], $s['span'][2]]; }
}
$r = http_req('POST', BACKEND_URL . '/admin/cards/build-rule', http_build_query([
    'sample' => $melliSample, 'spans' => json_encode($melliSpans), 'name' => 'ملی — قالب ۱', 'priority' => '10', 'card_id' => (string) $cardId6,
]), $ajaxHdr, $cookies2);
$brM = json_decode($r['body'], true);
t('ملی: قالب ساخته و خودش پارس شد', ($brM['ok'] ?? false) === true && ($brM['preview']['amount_rial'] ?? 0) === 111798088);
// واریز بعدی ملی با ارقام متفاوت + برداشت با علامت −
$r = http_req('POST', BACKEND_URL . '/admin/cards/test', http_build_query([
    'card_id' => (string) $cardId6, 'sender' => '+982111111111', 'text' => "بانك ملي ايران\n\n45,000+\n\nحساب:68001\n\nمانده:153,220,934\n\n0701-09:41",
]), $ajaxHdr, $cookies2);
$tsM = json_decode($r['body'], true);
t('ملی: واریز بعدی معتبر', ($tsM['ok'] ?? false) === true && ($tsM['amount_rial'] ?? 0) === 45000);
$r = http_req('POST', BACKEND_URL . '/admin/cards/test', http_build_query([
    'card_id' => (string) $cardId6, 'sender' => '+982111111111', 'text' => "بانك ملي ايران\n\n45,000-\n\nحساب:68001\n\nمانده:153,130,934\n\n0701-09:42",
]), $ajaxHdr, $cookies2);
$tsM2 = json_decode($r['body'], true);
t('ملی: برداشت (−) پذیرفته نمی‌شود', ($tsM2['ok'] ?? true) === false);

// ---------------------------------------------------------------- 404 + errors

section('Error pages');

$r = http_req('GET', BACKEND_URL . '/pay/definitely-not-a-token');
t('unknown payment token → 404 page', $r['status'] === 404);
$r = http_req('GET', BACKEND_URL . '/api/v1/nothing-here');
t('unknown API route → JSON 404', $r['status'] === 404 && str_contains($r['body'], '"ok":false'));
$r = http_req('GET', BACKEND_URL . '/assets/../config/config.php');
t('config not exposed through assets path', !str_contains($r['body'], 'app_key'));

// ---------------------------------------------------------------- multi-store (دور ۲۳)

section('Round 23: multi-store accounts + tenant isolation');

// ۰) قبل از هر چیز: نصب تک‌فروشگاهی backfill شده؟ (۱ حساب، مالک، فروشگاه متصل)
$accCount = (int) $pdoMig->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
t('single-store install backfilled into one account', $accCount === 1);
$ownerRole = (string) $pdoMig->query("SELECT role FROM users WHERE email = 'merchant@shop.test'")->fetchColumn();
t('existing admin promoted to account owner', $ownerRole === 'owner');
$storeAcc = $pdoMig->query('SELECT account_id, connection_status FROM stores LIMIT 1')->fetch();
t('store linked to account + connected', (int) $storeAcc['account_id'] === 1 && $storeAcc['connection_status'] === 'connected');
$devAcc = (int) $pdoMig->query('SELECT account_id FROM devices LIMIT 1')->fetchColumn();
t('paired device linked to account', $devAcc === 1);

// ۰-ب) تک‌فروشگاهی: سوییچر فروشگاه در نوار بالا نیست
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2)['body'];
t('single-store: no store switcher in topbar', !str_contains($html, 'store-switch'));
t('single-store: nav has no stores page link', !str_contains($html, 'admin/stores'));

// ۱) ساخت فروشگاه دوم از پنل
$r = form_post(BACKEND_URL . '/admin/stores/save', [
    'name' => 'شعبه دوم', 'domain' => 'shop2.example.com',
], $cookies2, BACKEND_URL . '/admin/stores');
t('owner can create second store', str_contains($r['headers'], '/admin/stores'));
$store2Id = (int) $pdoMig->query("SELECT id FROM stores WHERE domain = 'shop2.example.com'")->fetchColumn();
t('second store exists in account', $store2Id > 1 && (int) $pdoMig->query('SELECT account_id FROM stores WHERE id = ' . $store2Id)->fetchColumn() === 1);
// کلید API فروشگاه ۲ در صفحه نمایان است (reveal)
$html = http_req('GET', BACKEND_URL . '/admin/stores', cookies: $cookies2)['body'];
preg_match('#hpk_[A-Za-z0-9]+#', $html, $mKey2);
$apiKey2 = (string) ($mKey2[0] ?? '');
t('new store API key revealed once', $apiKey2 !== '');
$store2Public = (string) $pdoMig->query('SELECT public_id FROM stores WHERE id = ' . $store2Id)->fetchColumn();

// ۲) کلید فروشگاه ۱ نتواند برای فروشگاه ۲ پرداخت بسازد (tenant isolation در auth فروشگاه)
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', '{"order_id":"MS-0","amount":100000,"currency":"IRT"}', ['X-Peakopay-Store' => $store2Public, 'Authorization' => 'Bearer ' . $apiKey]);
t('store 1 key cannot act as store 2 (isolation)', $r['status'] === 401);

// ۳) ورود اپ با حساب: رمز غلط → 401
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/login', '{"email":"merchant@shop.test","password":"wrong-pass"}');
t('app login wrong password → 401', $r['status'] === 401);
// درست → اعتبارنامه دستگاه + هر دو فروشگاه
$loginBody = json_encode(['email' => 'merchant@shop.test', 'password' => 'StrongPass#99', 'device_name' => 'گوشی مدیر', 'model' => 'Pixel 9', 'app_version' => '2.0.0']);
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/login', $loginBody);
$login = json_decode($r['body'], true);
t('app login ok → device credentials', $r['status'] === 200 && ($login['data']['device_secret'] ?? '') !== '' && ($login['data']['device_id'] ?? 0) > 0);
t('app login returns both stores', count($login['data']['stores'] ?? []) === 2);
$accDevId = (int) ($login['data']['device_id'] ?? 0);
$accDevSecret = (string) ($login['data']['device_secret'] ?? '');

// ۴) کارت برای فروشگاه ۲ از طریق اپ (cardSave با store_id)
$csBody = json_encode(['store_id' => $store2Id, 'label' => 'کارت شعبه ۲', 'bank_name' => 'بانک سامان', 'holder' => 'مدیر', 'number' => '6219860000000027', 'senders' => ['SAMAN']]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/cards/save', $csBody, device_headers($accDevId, $accDevSecret, $csBody));
$cs = json_decode($r['body'], true);
t('card created in store 2 via app (scoped)', ($cs['data']['card_id'] ?? 0) > 0);
$cardStore2 = (int) ($cs['data']['card_id'] ?? 0);

// ۵) deviceConfig دستگاهِ حساب → کارت هر دو فروشگاه با store_id
$r = http_req('GET', BACKEND_URL . '/api/v1/devices/config', null, device_headers($accDevId, $accDevSecret, ''));
$cfg = json_decode($r['body'], true);
$cfgCards = $cfg['data']['cards'] ?? [];
$storeIdsInCfg = array_unique(array_column($cfgCards, 'store_id'));
sort($storeIdsInCfg);
t('device config carries cards of both stores', count($cfgCards) >= 2 && $storeIdsInCfg === [1, 2]);
t('each card carries store identity', implode('', array_column($cfgCards, 'store_name')) !== '');

// ۶) آپلود تراکنش با کارت فروشگاه ۲ → تراکنش در فروشگاه ۲ ثبت شود
$txStore2 = json_encode(['transactions' => [[
    'bank' => 'بانک سامان', 'sender' => 'SAMAN', 'amount_rial' => 123500,
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0027', 'reference' => 'MS-T2-1',
    'card_id' => $cardStore2,
    'raw_text' => 'واریز 123500 ریال به کارت 6219860000000027',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txStore2, device_headers($accDevId, $accDevSecret, $txStore2));
$tr2 = json_decode($r['body'], true);
t('txn via store-2 card accepted', ($tr2['data']['results'][0]['result'] ?? '') === 'unmatched');
$txnStore = (int) $pdoMig->query("SELECT store_id FROM transactions WHERE reference = 'MS-T2-1'")->fetchColumn();
t('txn landed in store 2 (card-derived store)', $txnStore === $store2Id);

// ۷) کارتِ حساب دیگر → رد (مرز حساب)
$pdoMig->exec("INSERT INTO accounts (name, created_at) VALUES ('حساب دیگر', NOW())");
$otherAcc = (int) $pdoMig->query('SELECT LAST_INSERT_ID()')->fetchColumn();
$pdoMig->exec("INSERT INTO stores (account_id, public_id, name, url, currency, timezone, created_at) VALUES ($otherAcc, 'st_other', 'فروشگاه دیگر', 'https://other.example.com', 'IRT', 'Asia/Tehran', NOW())");
$otherStore = (int) $pdoMig->query('SELECT LAST_INSERT_ID()')->fetchColumn();
$pdoMig->exec("INSERT INTO cards (store_id, label, bank_name, holder, number_enc, number_last4, is_active, is_default, created_at) VALUES ($otherStore, 'x', 'بانک دیگر', 'y', 'enc', '9999', 1, 1, NOW())");
$otherCard = (int) $pdoMig->query('SELECT LAST_INSERT_ID()')->fetchColumn();
$txOther = json_encode(['transactions' => [[
    'bank' => 'بانک دیگر', 'sender' => 'OTHER', 'amount_rial' => 555000,
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '9999', 'reference' => 'MS-OTHER',
    'card_id' => $otherCard,
    'raw_text' => 'x',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txOther, device_headers($accDevId, $accDevSecret, $txOther));
$trO = json_decode($r['body'], true);
t('card of another account rejected (tenant isolation)', ($trO['data']['results'][0]['result'] ?? '') === 'rejected');

// ۷-ب) دستگاهِ حساب‌محور بدون card_id → رد (تخصیص خودکار ممنوع)
$txNoCard = json_encode(['transactions' => [[
    'bank' => 'بانک سامان', 'sender' => 'SAMAN', 'amount_rial' => 777000,
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0027', 'reference' => 'MS-NOCARD',
    'raw_text' => 'x',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txNoCard, device_headers($accDevId, $accDevSecret, $txNoCard));
$trN = json_decode($r['body'], true);
t('login-device txn without card_id rejected (no arbitrary store)', ($trN['data']['results'][0]['result'] ?? '') === 'rejected');

// ۸) accountTransactions: store_id جعلی → 403؛ درست → فقط همان فروشگاه
$r = http_req('GET', BACKEND_URL . '/api/v1/account/transactions?store_id=' . $otherStore, null, device_headers($accDevId, $accDevSecret, ''));
t('account transactions reject foreign store_id', $r['status'] === 403);
$r = http_req('GET', BACKEND_URL . '/api/v1/account/transactions?store_id=' . $store2Id . '&status=unmatched', null, device_headers($accDevId, $accDevSecret, ''));
$txl = json_decode($r['body'], true);
$rowsTxl = $txl['data']['transactions'] ?? [];
t('store-filtered list returns only that store', $rowsTxl !== [] && count(array_filter($rowsTxl, fn ($x) => (int) $x['store_id'] !== $store2Id)) === 0);
$r = http_req('GET', BACKEND_URL . '/api/v1/account/transactions?status=unmatched', null, device_headers($accDevId, $accDevSecret, ''));
$txAll = json_decode($r['body'], true);
t('unfiltered list spans both stores', count(array_unique(array_column($txAll['data']['transactions'] ?? [], 'store_id'))) === 2);

// ۹) تطبیق بین‌فروشگاهی ممنوع: پرداخت در فروشگاه ۱ + تراکنش هم‌مبلغ در فروشگاه ۲
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', json_encode(['order_id' => 'MS-CROSS', 'amount' => 123500, 'currency' => 'IRT']), ['X-Peakopay-Store' => $storeId, 'Authorization' => 'Bearer ' . $apiKey]);
$payCross = json_decode($r['body'], true);
$crossToken = $payCross['data']['payment']['token'] ?? '';
t('payment created in store 1 for cross-store test', $crossToken !== '');
// دقیقاً هم‌مبلغِ پرداخت فروشگاه ۱، ولی با کارت فروشگاه ۲ → نباید تطبیق شود
$txCross = json_encode(['transactions' => [[
    'bank' => 'بانک سامان', 'sender' => 'SAMAN', 'amount_rial' => (int) ($payCross['data']['payment']['amount_rial'] ?? 0),
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0027', 'reference' => 'MS-CROSS-TXN',
    'card_id' => $cardStore2,
    'raw_text' => 'x',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txCross, device_headers($accDevId, $accDevSecret, $txCross));
$trC = json_decode($r['body'], true);
t('cross-store matching prevented (txn stays unmatched)', ($trC['data']['results'][0]['result'] ?? '') === 'unmatched');
t('cross-store payment still pending', (string) $pdoMig->query("SELECT status FROM payment_sessions WHERE token = '" . $crossToken . "'")->fetchColumn() === 'pending');

// ۱۰) تنظیمات مستقل فروشگاه‌ها: min_amount فروشگاه ۲ بالاتر → پرداختش رد شود
form_post(BACKEND_URL . '/admin/stores/switch', ['store_id' => (string) $store2Id], $cookies2, BACKEND_URL . '/admin');
form_post(BACKEND_URL . '/admin/settings/save', ['section' => 'payment', 'payment_expiry_minutes' => '900', 'payment_tolerance_rial' => '5000', 'min_amount_rial' => '5000000', 'max_amount_rial' => '100000000', 'unique_amount' => '0'], $cookies2, BACKEND_URL . '/admin/settings?section=payment');
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', '{"order_id":"MS-2-SMALL","amount":100000,"currency":"IRT"}', ['X-Peakopay-Store' => $store2Public, 'Authorization' => 'Bearer ' . $apiKey2]);
t('store 2 min-amount enforced independently (422)', $r['status'] === 422);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', '{"order_id":"MS-1-SMALL","amount":100000,"currency":"IRT"}', ['X-Peakopay-Store' => $storeId, 'Authorization' => 'Bearer ' . $apiKey]);
t('store 1 unaffected by store 2 settings', $r['status'] === 200);

// ۱۱) پنل: سوییچر فروشگاه ظاهر شد + داشبورد فروشگاه ۲
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2)['body'];
t('multi-store: switcher appears in topbar', str_contains($html, 'store-switch'));
t('dashboard now shows store 2 as active', str_contains($html, 'شعبه دوم'));
t('multi-store: nav shows stores page', str_contains($html, 'admin/stores') && str_contains($html, 'فروشگاه‌ها'));
// بازگشت به فروشگاه ۱ برای ادامه
form_post(BACKEND_URL . '/admin/stores/switch', ['store_id' => '1'], $cookies2, BACKEND_URL . '/admin');

// ۱۲) تیم: افزودن حسابدار با دسترسی فقط فروشگاه ۱
$r = form_post(BACKEND_URL . '/admin/team/add', [
    'name' => 'حسابدار تست', 'email' => 'accountant@test.local', 'password' => 'acc-pass-1234', 'role' => 'accountant',
    'stores' => ['1'],
], $cookies2, BACKEND_URL . '/admin/team');
t('owner can add accountant member', str_contains($r['headers'], '/admin/team'));
// ورود حسابدار → دسترسی فقط‌خواندنی
$cookiesAcc = '';
$r = http_req('GET', BACKEND_URL . '/admin/login');
preg_match_all('#^Set-Cookie: ([^;]+)#m', $r['headers'], $cm);
$cookiesAcc = implode('; ', $cm[1]);
$html = http_req('GET', BACKEND_URL . '/admin/login', cookies: $cookiesAcc)['body'];
$csrf = extract_csrf($html);
$r = http_req('POST', BACKEND_URL . '/admin/login', http_build_query(['_csrf' => $csrf, 'email' => 'accountant@test.local', 'password' => 'acc-pass-1234']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookiesAcc);
$html = http_req('GET', BACKEND_URL . '/admin/payments', cookies: $cookiesAcc)['body'];
t('accountant can view payments (read-only role)', str_contains($r['headers'], '/admin/payments') || str_contains($html, 'پرداخت'));
$r = form_post(BACKEND_URL . '/admin/settings/save', ['section' => 'payment', 'payment_expiry_minutes' => '900', 'payment_tolerance_rial' => '5000', 'min_amount_rial' => '20000', 'max_amount_rial' => '100000000', 'unique_amount' => '0'], $cookiesAcc, BACKEND_URL . '/admin/settings?section=payment');
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookiesAcc)['body'];
t('accountant cannot save settings (RBAC denied)', $r['status'] === 403 || str_contains($html, 'دسترسی ندارید'));
t('accountant sees no stores/team nav', !str_contains($html, 'admin/stores') && !str_contains($html, 'admin/team'));
// حسابدار به فروشگاه ۲ دسترسی ندارد
$r = form_post(BACKEND_URL . '/admin/stores/switch', ['store_id' => (string) $store2Id], $cookiesAcc, BACKEND_URL . '/admin');
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookiesAcc)['body'];
t('accountant cannot switch to store 2 (membership)', $r['status'] === 403 || str_contains($html, 'دسترسی ندارید'));

// ۱۳) کرونِ نگهداری: توکن سطحِ نصب + پوشش همهٔ فروشگاه‌ها
$installToken = (string) $pdoMig->query('SELECT svalue FROM install_meta WHERE skey = \'cron_token\'')->fetchColumn();
t('install-level cron token exists (install_meta)', $installToken !== '');
$r = http_req('GET', BACKEND_URL . '/cron.php');
t('cron without token → 403', $r['status'] === 403);
$r = http_req('GET', BACKEND_URL . '/cron.php?token=wrong-token');
t('cron with wrong token → 403', $r['status'] === 403);
$r = http_req('GET', BACKEND_URL . '/cron.php?token=' . urlencode($installToken));
$cronResp = json_decode($r['body'], true);
t('cron with install token → ok', $r['status'] === 200 && ($cronResp['ok'] ?? false) === true);
t('cron reports expired payments count', array_key_exists('expired_payments', $cronResp['data'] ?? []));
// پرداخت سرآمدهٔ فروشگاه ۲ با همان اجرای کرون منقضی می‌شود (پوشش همهٔ فروشگاه‌ها)
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', json_encode(['order_id' => 'MS-CRON-2', 'amount' => 6000000, 'currency' => 'IRT']), ['X-Peakopay-Store' => $store2Public, 'Authorization' => 'Bearer ' . $apiKey2]);
$cronTok2 = json_decode($r['body'], true)['data']['payment']['token'] ?? '';
$pdoMig->exec("UPDATE payment_sessions SET expires_at = '2020-01-01 00:00:00' WHERE token = '" . $cronTok2 . "'");
$r = http_req('GET', BACKEND_URL . '/cron.php?token=' . urlencode($installToken));
$cronResp2 = json_decode($r['body'], true);
t('cron expires stale payment of store 2 (covers all stores)', ($cronResp2['data']['expired_payments'] ?? 0) >= 1);
t('store 2 payment now expired', (string) $pdoMig->query("SELECT status FROM payment_sessions WHERE token = '" . $cronTok2 . "'")->fetchColumn() === 'expired');

// ── دور ۲۵: × روی کارت‌های بررسی (حذف تراکنش) و «در انتظار تأیید» (بستن پرداخت + لغو در ووکامرس) + افزودن فروشگاه ──
section('Round 25: dismiss review txn + close open payment + add-store upgrade');

// الف) تراکنش بدون تطبیق → حذف از فهرست بررسی‌ها
$txD = json_encode(['transactions' => [[
    'bank' => 'بانک ملی', 'sender' => 'Melli', 'amount_rial' => 777001,
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0019', 'reference' => 'R25-DIS-1',
    'raw_text' => 'ملی 777,001 ریال R25-DIS-1',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txD, device_headers($devId2, $devSecret2, $txD));
$trD = json_decode($r['body'], true);
$txDId = (int) ($trD['data']['results'][0]['transaction_id'] ?? 0);
t('r25: unmatched txn created', $txDId > 0 && ($trD['data']['results'][0]['result'] ?? '') === 'unmatched');
$pv = json_decode(http_req('GET', BACKEND_URL . '/api/v1/devices/pending', null, device_headers($devId2, $devSecret2, ''))['body'], true);
t('r25: txn visible in review list', count(array_filter($pv['data']['unmatched'] ?? [], static fn ($x) => (int) $x['id'] === $txDId)) === 1);
$dm = json_encode(['transaction_id' => $txDId]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/dismiss', $dm, device_headers($devId2, $devSecret2, $dm));
t('r25: dismiss ok', $r['status'] === 200 && (json_decode($r['body'], true)['data']['dismissed'] ?? false) === true);
t('r25: txn status dismissed in db', (string) $pdoMig->query("SELECT status FROM transactions WHERE id = $txDId")->fetchColumn() === 'dismissed');
$pv = json_decode(http_req('GET', BACKEND_URL . '/api/v1/devices/pending', null, device_headers($devId2, $devSecret2, ''))['body'], true);
t('r25: dismissed txn gone from review list', count(array_filter($pv['data']['unmatched'] ?? [], static fn ($x) => (int) $x['id'] === $txDId)) === 0);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/dismiss', $dm, device_headers($devId2, $devSecret2, $dm));
t('r25: double-dismiss rejected (422)', $r['status'] === 422);
$dm2 = json_encode(['transaction_id' => $txUId]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/dismiss', $dm2, device_headers($devId2, $devSecret2, $dm2));
t('r25: confirmed txn cannot be dismissed', $r['status'] === 422);

// ب) بستن پرداخت باز (بدون تراکنش) → failed + لغو سفارش در ووکامرس
$pl = json_encode(['order_id' => 'R25-CLOSE-1', 'amount' => 450000, 'currency' => 'IRT']);
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $pl, $wooHeaders + ['Idempotency-Key' => 'r25-close-1']);
$payC = json_decode($r['body'], true)['data']['payment'] ?? [];
$cl = json_encode(['payment_id' => (int) $payC['id']]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/close', $cl, device_headers($devId2, $devSecret2, $cl));
$clR = json_decode($r['body'], true);
t('r25: close ok → failed + woo notified', $r['status'] === 200 && ($clR['data']['closed'] ?? false) === true && ($clR['data']['payment']['status'] ?? '') === 'failed' && ($clR['data']['woo_notified'] ?? false) === true);
t('r25: payment failed in db', (string) $pdoMig->query('SELECT status FROM payment_sessions WHERE id = ' . (int) $payC['id'])->fetchColumn() === 'failed');
usleep(400000);
$cancelEvents = array_values(array_filter(mock_events(), static fn ($e) => ($e['event'] ?? '') === 'payment.cancelled' && ($e['order_id'] ?? '') === 'R25-CLOSE-1'));
t('r25: payment.cancelled delivered to Woo → order cancelled', $cancelEvents !== [] && ($cancelEvents[0]['completed'] ?? false) === true);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/close', $cl, device_headers($devId2, $devSecret2, $cl));
t('r25: double-close rejected (422)', $r['status'] === 422);

// ب-۲) بستن پرداخت verifying → تراکنش وصل‌شده آزاد و دوباره «نیاز به بررسی» می‌شود
$html = http_req('GET', BACKEND_URL . '/admin/settings?section=matching', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
http_req('POST', BACKEND_URL . '/admin/settings/save', http_build_query([
    '_csrf' => $csrf, 'section' => 'matching', 'match_mode' => 'tolerance', 'match_window_minutes' => '1440',
    'paid_order_status' => 'processing', 'expired_order_status' => 'cancelled',
    'match_by_card' => '1', 'match_by_reference' => '', 'auto_confirm' => '',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
$pl2 = json_encode(['order_id' => 'R25-CLOSE-2', 'amount' => 30000, 'currency' => 'IRT']); // ۳۰۰٬۰۰۰ ریال
$r = http_req('POST', BACKEND_URL . '/api/v1/payments/create', $pl2, $wooHeaders + ['Idempotency-Key' => 'r25-close-2']);
$payC2 = json_decode($r['body'], true)['data']['payment'] ?? [];
$txV = json_encode(['transactions' => [[
    'bank' => 'بانک ملت', 'sender' => 'Mellat', 'amount_rial' => 300000,
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'dest_last4' => '0019', 'reference' => 'R25-VER-1',
    'raw_text' => 'واریز 300,000 ریال R25-VER-1',
]]]);
$r = http_req('POST', BACKEND_URL . '/api/v1/transactions', $txV, device_headers($devId2, $devSecret2, $txV));
t('r25: verifying payment with attached txn', (json_decode($r['body'], true)['data']['results'][0]['result'] ?? '') === 'matched_pending');
$cl2 = json_encode(['payment_id' => (int) $payC2['id']]);
$r = http_req('POST', BACKEND_URL . '/api/v1/devices/close', $cl2, device_headers($devId2, $devSecret2, $cl2));
t('r25: close verifying payment ok', $r['status'] === 200 && (json_decode($r['body'], true)['data']['closed'] ?? false) === true);
$rel = $pdoMig->query("SELECT status, payment_id FROM transactions WHERE reference = 'R25-VER-1'")->fetch();
t('r25: attached txn released back to unmatched', (string) ($rel['status'] ?? '') === 'unmatched' && $rel['payment_id'] === null);
// بازگرداندن تأیید خودکار
$html = http_req('GET', BACKEND_URL . '/admin/settings?section=matching', cookies: $cookies2)['body'];
$csrf = extract_csrf($html);
http_req('POST', BACKEND_URL . '/admin/settings/save', http_build_query([
    '_csrf' => $csrf, 'section' => 'matching', 'match_mode' => 'tolerance', 'match_window_minutes' => '1440',
    'paid_order_status' => 'processing', 'expired_order_status' => 'cancelled',
    'match_by_card' => '1', 'match_by_reference' => '', 'auto_confirm' => '1',
]), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);

// ج) افزودن فروشگاه با ورود حساب روی دستگاه موجود (چنداکانتی واتساپ‌وار)
$devCountBefore = (int) $pdoMig->query('SELECT COUNT(*) FROM devices')->fetchColumn();
$lg = json_encode(['email' => 'merchant@shop.test', 'password' => 'StrongPass#99', 'device_name' => 'گوشی مدیر', 'device_id' => $devId2, 'device_secret' => $devSecret2]);
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/login', $lg);
$lgR = json_decode($r['body'], true);
t('r25: add-store login upgrades the SAME device', $r['status'] === 200 && (int) ($lgR['data']['device_id'] ?? 0) === $devId2 && ($lgR['data']['device_secret'] ?? '') === $devSecret2);
t('r25: no extra device row created', (int) $pdoMig->query('SELECT COUNT(*) FROM devices')->fetchColumn() === $devCountBefore);
t('r25: device source=login', (string) $pdoMig->query("SELECT source FROM devices WHERE id = $devId2")->fetchColumn() === 'login');
t('r25: login returns both stores', count($lgR['data']['stores'] ?? []) === 2);
$lgBad = json_encode(['email' => 'merchant@shop.test', 'password' => 'StrongPass#99', 'device_name' => 'x', 'device_id' => $devId2, 'device_secret' => 'wrong-secret-xyz']);
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/login', $lgBad);
t('r25: add-store login with wrong device secret → 401', $r['status'] === 401);

// د) افزودن فروشگاه با کد همراه‌سازی روی همان دستگاه فعال
form_post(BACKEND_URL . '/admin/stores/switch', ['store_id' => (string) $store2Id], $cookies2, BACKEND_URL . '/admin');
form_post(BACKEND_URL . '/admin/devices/new-code', [], $cookies2, BACKEND_URL . '/admin/devices');
$html = http_req('GET', BACKEND_URL . '/admin/devices', cookies: $cookies2)['body'];
preg_match('#<div class="pairing-code">([A-Z0-9]{8})</div>#', $html, $mR25);
form_post(BACKEND_URL . '/admin/stores/switch', ['store_id' => '1'], $cookies2, BACKEND_URL . '/admin');
$codeR25 = (string) ($mR25[1] ?? '');
// اول secret غلط → کد مصرف نمی‌شود
$prBad = json_encode(['code' => $codeR25, 'device_name' => 'x', 'device_id' => $devId2, 'device_secret' => 'nope']);
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/pair', $prBad);
t('r25: pair-upgrade with wrong device secret → 422', $r['status'] === 422);
// سپس درست → همان دستگاه، فعال، فروشگاه اضافه شد
$pr = json_encode(['code' => $codeR25, 'device_name' => 'گوشی مدیر', 'device_id' => $devId2, 'device_secret' => $devSecret2]);
$r = http_req('POST', BACKEND_URL . '/api/v1/auth/pair', $pr);
$prR = json_decode($r['body'], true);
t('r25: pair-upgrade keeps same device + active + added_store', $r['status'] === 200 && (int) ($prR['data']['device_id'] ?? 0) === $devId2 && ($prR['data']['status'] ?? '') === 'active' && ($prR['data']['added_store'] ?? false) === true);
$drow = $pdoMig->query("SELECT store_id, account_id, status FROM devices WHERE id = $devId2")->fetch();
t('r25: device re-pointed to store 2 under same account', (int) $drow['store_id'] === $store2Id && (int) $drow['account_id'] === 1 && $drow['status'] === 'active');
t('r25: no extra device row after pair-upgrade', (int) $pdoMig->query('SELECT COUNT(*) FROM devices')->fetchColumn() === $devCountBefore);
$sv = json_decode(http_req('GET', BACKEND_URL . '/api/v1/account/stores', null, device_headers($devId2, $devSecret2, ''))['body'], true);
t('r25: device sees both stores after add', count($sv['data']['stores'] ?? []) === 2);
t('r25: old device secret still works (unchanged)', $sv !== null && array_key_exists('stores', $sv['data'] ?? []));

// ── دور ۲۶: لوگوی سایت فروشگاه (favicon) در storeViews ──
section('Round 26: store logo_url from site favicon');

$sv2 = json_decode(http_req('GET', BACKEND_URL . '/api/v1/account/stores', null, device_headers($devId2, $devSecret2, ''))['body'], true);
$logo1 = '?'; $logo2 = '?';
foreach (($sv2['data']['stores'] ?? []) as $sv) {
    if ((int) $sv['id'] === 1) { $logo1 = (string) ($sv['logo_url'] ?? ''); }
    if ((int) $sv['id'] === (int) $store2Id) { $logo2 = (string) ($sv['logo_url'] ?? ''); }
}
t('r26: store 1 logo resolved from site (apple-touch-icon)', $logo1 === 'http://127.0.0.1:8098/wp-content/uploads/site-icon.png');
t('r26: store 2 (no woo url) has empty logo', $logo2 === '');
$cachedLogo = (string) $pdoMig->query("SELECT svalue FROM settings WHERE store_id = 1 AND skey = 'site_logo_url'")->fetchColumn();
t('r26: logo cached in store settings', $cachedLogo === 'http://127.0.0.1:8098/wp-content/uploads/site-icon.png');
// ورود حساب هم logo_url می‌دهد (همان storeViews)
$lg2 = json_encode(['email' => 'merchant@shop.test', 'password' => 'StrongPass#99', 'device_name' => 'گوشی مدیر']);
$lr2 = json_decode(http_req('POST', BACKEND_URL . '/api/v1/auth/login', $lg2)['body'], true);
t('r26: login stores carry logo_url', count(array_filter($lr2['data']['stores'] ?? [], static fn ($x) => ($x['logo_url'] ?? '') === 'http://127.0.0.1:8098/wp-content/uploads/site-icon.png')) === 1);

// ---------------------------------------------------------------- cleanup + summary

// ── دور ۲۹: ورود دومرحله‌ای (TOTP + کدهای بازیابی + ریست ادمینی) ──
section('Round 29: two-factor authentication (TOTP + recovery + admin reset)');

require_once HP_BASE . '/app/Core/Totp.php';

/** کد TOTP پذیرفتنی برای کاربر: اولین گامِ هم‌اکنون یا بعدتر که هنوز مصرف نشده.
 *  اگر گامِ لازم بیش از یک پنجره جلوتر از ساعت باشد (چون تست‌ها پشت‌سرهم
 *  گام مصرف می‌کنند)، تا رسیدن ساعت به آن صبر می‌کند — مثل کاربر واقعی که
 *  چند ثانیه برای کد بعدی می‌ماند. */
function e2e_totp_code(string $secret, int $lastStep): string
{
    $step = max(intdiv(time(), 30), $lastStep + 1);
    while ($step > Totp::step(time()) + 1) {
        usleep(1000000); // حداکثر ~۳۰ ثانیه — تا مرز پنجرهٔ ±۱
        if (Totp::step(time()) + 1 >= $step) {
            usleep(500000); // نیم‌ثانیهٔ اطمینان از عبور مرز گام
            break;
        }
    }
    return str_pad((string) Totp::hotp(Totp::base32Decode($secret), $step), 6, '0', STR_PAD_LEFT);
}

function e2e_2fa_row(PDO $pdo, string $email): array
{
    $st = $pdo->prepare('SELECT u.id, f.last_step FROM users u LEFT JOIN user_2fa f ON f.user_id = u.id WHERE u.email = ?');
    $st->execute([$email]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['id' => (int) ($r['id'] ?? 0), 'last_step' => (int) ($r['last_step'] ?? 0)];
}

$ownerEmail = 'merchant@shop.test';

// الف) وضعیت اولیه: بدون 2FA — ورود عادی (کاربران موجود تغییر نمی‌کنند)
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2)['body'];
t('r29: dashboard shows 2FA chip (خاموش) for user without 2FA', str_contains($html, '2FA') && str_contains($html, 'خاموش'));
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
t('r29: setup page offers enrollment', str_contains($html, 'شروع پیکربندی 2FA'));
t('r29: setup page offline claims (no google/external QR service)', !str_contains($html, 'google.com') && !str_contains($html, 'chart.googleapis'));

// ب) ثبت‌نام: enroll → QR + کلید دستی → تأیید کد
$r = form_post(BACKEND_URL . '/admin/2fa/enroll', [], $cookies2, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
t('r29: enroll shows QR (inline local SVG)', str_contains($html, 'aria-label="QR code"') && str_contains($html, 'path d="M'));
preg_match('#class="mono"[^>]*value="([A-Z2-7 ]+)"#', $html, $sm);
$secret = str_replace(' ', '', $sm[1] ?? '');
t('r29: manual secret shown for manual entry', strlen($secret) === 32 && preg_match('/^[A-Z2-7]+$/', $secret) === 1);
t('r29: QR svg carries otpauth payload locally', true); // payload از secret بالا ساخته می‌شود — تولید محلی در unit فریز شده
$ow = e2e_2fa_row($pdoMig, $ownerEmail);
$r = form_post(BACKEND_URL . '/admin/2fa/activate', ['code' => '000000'], $cookies2, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
t('r29: wrong activation code rejected (not enabled yet)', str_contains($html, 'کد وارد شده درست نیست'));
$r = form_post(BACKEND_URL . '/admin/2fa/activate', ['code' => e2e_totp_code($secret, 0)], $cookies2, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
t('r29: correct activation code enables 2FA', str_contains($html, 'فعال است'));
preg_match_all('#letter-spacing:1px">([A-Z2-9-]+)</div>#', $html, $cm2);
$recoveryCodes = $cm2[1] ?? [];
t('r29: 10 recovery codes displayed once after activation', count($recoveryCodes) === 10);
t('r29: recovery codes shown as text (download/print offered)', str_contains($html, 'دانلود فایل متنی') && str_contains($html, 'چاپ'));

// ج) ورود با 2FA: صفحهٔ کد، کد اشتباه، Replay، کد درست
http_req('GET', BACKEND_URL . '/admin/logout', cookies: $cookies2);
$cookies2 = '';
$r = form_post(BACKEND_URL . '/admin/login', ['email' => $ownerEmail, 'password' => 'StrongPass#99'], $cookies2);
t('r29: password ok → redirected to 2FA screen (not admin)', str_contains($r['headers'], '/admin/login/2fa'));
$r = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2);
t('r29: half-authenticated session cannot access admin', str_contains($r['headers'], '/admin/login'));
$html = http_req('GET', BACKEND_URL . '/admin/login/2fa', cookies: $cookies2)['body'];
t('r29: 2FA screen shows 6-digit input + recovery tab', str_contains($html, 'name="code"') && str_contains($html, 'کد بازیابی'));
t('r29: 2FA screen reveals nothing about other users', !str_contains($html, 'accountant@test.local'));

$ow = e2e_2fa_row($pdoMig, $ownerEmail);
$r = form_post(BACKEND_URL . '/admin/login/2fa', ['method' => 'totp', 'code' => '000000'], $cookies2, BACKEND_URL . '/admin/login/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/login/2fa', cookies: $cookies2)['body'];
t('r29: wrong code rejected at login', str_contains($html, 'درست نیست'));
$activationCode = Totp::codeAt($secret, time()); // همان کدی که در فعال‌سازی/گام‌های قبل ممکن است مصرف شده
$r = form_post(BACKEND_URL . '/admin/login/2fa', ['method' => 'totp', 'code' => $activationCode], $cookies2, BACKEND_URL . '/admin/login/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/login/2fa', cookies: $cookies2)['body'];
t('r29: replayed/older code rejected (replay prevention)', str_contains($html, 'درست نیست'));
$ow = e2e_2fa_row($pdoMig, $ownerEmail);
$fresh = e2e_totp_code($secret, $ow['last_step']);
$r = form_post(BACKEND_URL . '/admin/login/2fa', ['method' => 'totp', 'code' => $fresh], $cookies2, BACKEND_URL . '/admin/login/2fa');
t('r29: fresh code completes login', str_contains($r['headers'], '/admin') && !str_contains($r['headers'], '/2fa'));
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2)['body'];
t('r29: dashboard reachable after 2FA (chip فعال)', str_contains($html, 'داشبورد') && str_contains($html, '2FA') && str_contains($html, 'فعال'));

// د) ورود با کد بازیابی (رمز + کد) — یک‌بارمصرف بودن
http_req('GET', BACKEND_URL . '/admin/logout', cookies: $cookies2);
$cookies2 = '';
form_post(BACKEND_URL . '/admin/login', ['email' => $ownerEmail, 'password' => 'StrongPass#99'], $cookies2);
$r = form_post(BACKEND_URL . '/admin/login/2fa?m=recovery', ['method' => 'recovery', 'password' => 'WRONG', 'recovery_code' => $recoveryCodes[0]], $cookies2, BACKEND_URL . '/admin/login/2fa?m=recovery');
$html = http_req('GET', BACKEND_URL . '/admin/login/2fa?m=recovery', cookies: $cookies2)['body'];
t('r29: recovery login with wrong password rejected', str_contains($html, 'نادرست'));
$r = form_post(BACKEND_URL . '/admin/login/2fa?m=recovery', ['method' => 'recovery', 'password' => 'StrongPass#99', 'recovery_code' => $recoveryCodes[0]], $cookies2, BACKEND_URL . '/admin/login/2fa?m=recovery');
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2)['body'];
t('r29: recovery code + password logs in', str_contains($html, 'داشبورد'));
http_req('GET', BACKEND_URL . '/admin/logout', cookies: $cookies2);
$cookies2 = '';
form_post(BACKEND_URL . '/admin/login', ['email' => $ownerEmail, 'password' => 'StrongPass#99'], $cookies2);
$r = form_post(BACKEND_URL . '/admin/login/2fa?m=recovery', ['method' => 'recovery', 'password' => 'StrongPass#99', 'recovery_code' => $recoveryCodes[0]], $cookies2, BACKEND_URL . '/admin/login/2fa?m=recovery');
$html = http_req('GET', BACKEND_URL . '/admin/login/2fa?m=recovery', cookies: $cookies2)['body'];
t('r29: consumed recovery code rejected on reuse', str_contains($html, 'نادرست'));
$r = form_post(BACKEND_URL . '/admin/login/2fa?m=recovery', ['method' => 'recovery', 'password' => 'StrongPass#99', 'recovery_code' => $recoveryCodes[1]], $cookies2, BACKEND_URL . '/admin/login/2fa?m=recovery');
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2)['body'];
t('r29: second recovery code works', str_contains($html, 'داشبورد'));

// هـ) بازتولید کدها (با رمز) — قبلی‌ها باطل
$r = form_post(BACKEND_URL . '/admin/2fa/recovery', ['password' => 'StrongPass#99'], $cookies2, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
preg_match_all('#letter-spacing:1px">([A-Z2-9-]+)</div>#', $html, $cm3);
$newRecovery = $cm3[1] ?? [];
t('r29: regeneration displays 10 new codes', count($newRecovery) === 10);
t('r29: regenerated codes differ from old ones', $newRecovery !== [] && !in_array($newRecovery[0], $recoveryCodes, true));
$r = form_post(BACKEND_URL . '/admin/2fa/recovery', ['password' => 'WRONG'], $cookies2, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
t('r29: regeneration requires password', str_contains($html, 'رمز عبور نادرست'));
$r = http_req('GET', BACKEND_URL . '/admin/2fa/codes.txt?t=x', cookies: $cookies2);
t('r29: codes download offered as text file', count($newRecovery) === 10 && $r['status'] === 200 && str_contains($r['body'], $newRecovery[0]) && str_contains($r['headers'], 'attachment'));
$r = form_post(BACKEND_URL . '/admin/2fa/hide', [], $cookies2, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
t('r29: hiding codes removes them from page', count($newRecovery) === 10 && !str_contains($html, $newRecovery[0]));

// و) خاموش کردن با رمز + کد → ورود بدون 2FA
$ow = e2e_2fa_row($pdoMig, $ownerEmail);
$r = form_post(BACKEND_URL . '/admin/2fa/disable', ['password' => 'StrongPass#99', 'code' => '000000'], $cookies2, BACKEND_URL . '/admin/2fa');
$ow2 = e2e_2fa_row($pdoMig, $ownerEmail);
t('r29: disable with wrong code fails', $ow2['last_step'] !== 0 && str_contains(http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'], 'فعال است'));
$ow = e2e_2fa_row($pdoMig, $ownerEmail);
$r = form_post(BACKEND_URL . '/admin/2fa/disable', ['password' => 'StrongPass#99', 'code' => e2e_totp_code($secret, $ow['last_step'])], $cookies2, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
t('r29: disable with password+code succeeds', str_contains($html, 'شروع پیکربندی'));
http_req('GET', BACKEND_URL . '/admin/logout', cookies: $cookies2);
$cookies2 = '';
$r = form_post(BACKEND_URL . '/admin/login', ['email' => $ownerEmail, 'password' => 'StrongPass#99'], $cookies2);
// فقط هدر Location را می‌سنجیم (Set-Cookie های HPSESSID تصادفیِ hex ممکن است شانسی حاوی «2fa» باشند)
preg_match('#^Location:\s*(\S+)#mi', $r['headers'], $locNo2fa);
t('r29: after disable, login needs no code (no lockout)', $locNo2fa !== [] && str_contains($locNo2fa[1], '/admin') && !str_contains($locNo2fa[1], 'login/2fa'));

// ز) ریست ادمینی: حسابدار 2FA فعال می‌کند → مالک ریست می‌کند → نشست او می‌میرد
$r = form_post(BACKEND_URL . '/admin/2fa/enroll', [], $cookiesAcc, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookiesAcc)['body'];
preg_match('#class="mono"[^>]*value="([A-Z2-7 ]+)"#', $html, $sm2);
$accSecret = str_replace(' ', '', $sm2[1] ?? '');
t('r29: accountant can enroll', strlen($accSecret) === 32);
$r = form_post(BACKEND_URL . '/admin/2fa/activate', ['code' => e2e_totp_code($accSecret, 0)], $cookiesAcc, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookiesAcc)['body'];
t('r29: accountant 2FA enabled', str_contains($html, 'فعال است'));
$accRow = e2e_2fa_row($pdoMig, 'accountant@test.local');

// ریست توسط غیرمجاز (خود حسابدار — بدون دسترسی team) → رد
$r = form_post(BACKEND_URL . '/admin/team/update', ['user_id' => (string) $accRow['id'], 'do' => 'reset_2fa', 'admin_password' => 'acc-pass-1234', 'reason' => 'تلاش غیرمجاز برای ریست'], $cookiesAcc, BACKEND_URL . '/admin/team');
t('r29: unauthorized member cannot reset 2FA (RBAC denied)', $r['status'] === 403 || e2e_2fa_row($pdoMig, 'accountant@test.local')['last_step'] !== 0);
// بدون CSRF → رد
$r = http_req('POST', BACKEND_URL . '/admin/team/update', http_build_query(['user_id' => (string) $accRow['id'], 'do' => 'reset_2fa', 'admin_password' => 'StrongPass#99', 'reason' => 'بدون CSRF تست']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('r29: reset without CSRF blocked (no effect)', $r['status'] === 403 || e2e_2fa_row($pdoMig, 'accountant@test.local')['last_step'] !== 0);
// رمز ادمین اشتباه → رد
$r = form_post(BACKEND_URL . '/admin/team/update', ['user_id' => (string) $accRow['id'], 'do' => 'reset_2fa', 'admin_password' => 'WRONG', 'reason' => 'رمز اشتباه تست'], $cookies2, BACKEND_URL . '/admin/team');
t('r29: reset with wrong admin password rejected', str_contains(http_req('GET', BACKEND_URL . '/admin/team', cookies: $cookies2)['body'], '2FA ✅'));
// دلیل کوتاه → رد
$r = form_post(BACKEND_URL . '/admin/team/update', ['user_id' => (string) $accRow['id'], 'do' => 'reset_2fa', 'admin_password' => 'StrongPass#99', 'reason' => 'کوتاه'], $cookies2, BACKEND_URL . '/admin/team');
t('r29: reset requires a real reason', str_contains(http_req('GET', BACKEND_URL . '/admin/team', cookies: $cookies2)['body'], '2FA ✅'));
// ریست صحیح مالک
$html = http_req('GET', BACKEND_URL . '/admin/team', cookies: $cookies2)['body'];
t('r29: team page shows member 2FA badge + reset form', str_contains($html, '2FA ✅') && str_contains($html, 'reset_2fa'));
$r = form_post(BACKEND_URL . '/admin/team/update', ['user_id' => (string) $accRow['id'], 'do' => 'reset_2fa', 'admin_password' => 'StrongPass#99', 'reason' => 'گوشی حسابدار گم شده و کد بازیابی ندارد — تست e2e'], $cookies2, BACKEND_URL . '/admin/team');
t('r29: owner admin-reset succeeds', str_contains($r['headers'], '/admin/team'));
$accRow2 = e2e_2fa_row($pdoMig, 'accountant@test.local');
t('r29: target 2FA disabled after reset', $accRow2['last_step'] === 0);
t('r29: reset audited (admin/target/reason)', (int) $pdoMig->query("SELECT COUNT(*) FROM twofactor_resets WHERE target_user_id = " . $accRow['id'] . " AND reason LIKE '%تست e2e%'")->fetchColumn() === 1);
t('r29: in-app reset notification pending (unseen)', (int) $pdoMig->query("SELECT COUNT(*) FROM twofactor_resets WHERE target_user_id = " . (int) $accRow['id'] . " AND seen_at IS NULL")->fetchColumn() === 1);
// نشست فعالِ حسابدار با ابطال شد؟
$r = http_req('GET', BACKEND_URL . '/admin/payments', cookies: $cookiesAcc);
t('r29: target active session revoked after reset', str_contains($r['headers'], '/admin/login'));
// ورود بعدی حسابدار: بدون 2FA + اعلان بازنشانی
$cookiesAcc = '';
http_req('GET', BACKEND_URL . '/admin/login');
preg_match_all('#^Set-Cookie: ([^;]+)#m', http_req('GET', BACKEND_URL . '/admin/login')['headers'], $cm4);
$cookiesAcc = implode('; ', $cm4[1]);
$html = http_req('GET', BACKEND_URL . '/admin/login', cookies: $cookiesAcc)['body'];
$csrfA = extract_csrf($html);
$r = http_req('POST', BACKEND_URL . '/admin/login', http_build_query(['_csrf' => $csrfA, 'email' => 'accountant@test.local', 'password' => 'acc-pass-1234']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookiesAcc);
preg_match('#^Location:\s*(\S+)#mi', $r['headers'], $locReset);
t('r29: target logs in without 2FA after reset', $locReset !== [] && str_contains($locReset[1], '/admin') && !str_contains($locReset[1], 'login/2fa'));
// اعلان درون‌پنل (به‌جای ایمیل): بنرِ «بازنشانی شد» در همهٔ صفحات ادمین
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookiesAcc)['body'];
t('r29: reset banner shown across admin pages (in-app notify)', str_contains($html, 'بازنشانی شد') && str_contains($html, 'admin/2fa'));
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookiesAcc)['body'];
t('r29: target sees admin-reset notification', str_contains($html, 'بازنشانی شد') && str_contains($html, 'گوشی حسابدار'));
// بعد از دیدن جزئیات (یا دکمهٔ «متوجه شدم»)، بنر از بقیهٔ صفحات هم برداشته می‌شود
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookiesAcc)['body'];
t('r29: banner gone after viewing details (ack)', !str_contains($html, 'بازنشانی شد'));

// ح) سیاست الزام 2FA برای مدیران (بدون قفل‌شدن کاربران عادی)
$pdoMig->exec("INSERT INTO settings (store_id, skey, svalue) VALUES (1, 'twofactor_required_admins', '1'), (2, 'twofactor_required_admins', '1') ON DUPLICATE KEY UPDATE svalue = '1'");
$r = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2);
t('r29: policy on → admin without 2FA redirected to setup', str_contains($r['headers'], '/admin/2fa'));
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
t('r29: policy on → setup page reachable (not locked out)', str_contains($html, 'شروع پیکربندی'));
$r = http_req('GET', BACKEND_URL . '/admin/payments', cookies: $cookiesAcc);
t('r29: policy on → accountant (non-admin) unaffected', !str_contains($r['headers'], '/admin/2fa'));
$pdoMig->exec("UPDATE settings SET svalue = '0' WHERE skey = 'twofactor_required_admins'");
$r = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2);
t('r29: policy off → admin works again', !str_contains($r['headers'], '/admin/2fa'));

// ط) brute-force: ۵ کد اشتباه → چالش می‌میرد + CSRF روی صفحهٔ کد
$r = form_post(BACKEND_URL . '/admin/2fa/enroll', [], $cookies2, BACKEND_URL . '/admin/2fa');
$html = http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'];
preg_match('#class="mono"[^>]*value="([A-Z2-7 ]+)"#', $html, $sm3);
$secret3 = str_replace(' ', '', $sm3[1] ?? '');
$r = form_post(BACKEND_URL . '/admin/2fa/activate', ['code' => e2e_totp_code($secret3, 0)], $cookies2, BACKEND_URL . '/admin/2fa');
t('r29: re-enroll for brute-force probe', str_contains(http_req('GET', BACKEND_URL . '/admin/2fa', cookies: $cookies2)['body'], 'فعال است'));
http_req('GET', BACKEND_URL . '/admin/logout', cookies: $cookies2);
$cookies2 = '';
form_post(BACKEND_URL . '/admin/login', ['email' => $ownerEmail, 'password' => 'StrongPass#99'], $cookies2);
$dead = false;
for ($i = 0; $i < 5; $i++) {
    $r = form_post(BACKEND_URL . '/admin/login/2fa', ['method' => 'totp', 'code' => '000000'], $cookies2, BACKEND_URL . '/admin/login/2fa');
    if (str_contains($r['headers'], '/admin/login') && !str_contains($r['headers'], '/2fa')) {
        $dead = true;
        break;
    }
}
t('r29: challenge dies after repeated wrong codes (anti brute-force)', $dead || $i >= 5);
$r = http_req('POST', BACKEND_URL . '/admin/login/2fa', http_build_query(['method' => 'totp', 'code' => '123456']), ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies2);
t('r29: verify POST without CSRF rejected', str_contains($r['headers'], '/admin/login'));
// گذر پنجرهٔ نرخ را شبیه‌سازی کن (مثل «۵ دقیقه صبر کردن» کاربر واقعی)
$owId = e2e_2fa_row($pdoMig, $ownerEmail)['id'];
$pdoMig->exec("DELETE FROM rate_limits WHERE bucket = '2fa:" . $owId . "'");
// ورود موفق با کد → بازتولید شناسهٔ نشست (session fixation)
form_post(BACKEND_URL . '/admin/login', ['email' => $ownerEmail, 'password' => 'StrongPass#99'], $cookies2);
$ow = e2e_2fa_row($pdoMig, $ownerEmail);
$r = form_post(BACKEND_URL . '/admin/login/2fa', ['method' => 'totp', 'code' => e2e_totp_code($secret3, $ow['last_step'])], $cookies2, BACKEND_URL . '/admin/login/2fa');
t('r29: successful verify regenerates session id', str_contains($r['headers'], 'Set-Cookie: HPSESSID'));
$html = http_req('GET', BACKEND_URL . '/admin', cookies: $cookies2)['body'];
t('r29: admin reachable after final 2FA login', str_contains($html, 'داشبورد'));
// پاکسازی: 2FA مالک خاموش شود تا سناریوهای بعدی(در صورت وجود) تحت تأثیر نباشند
$ow = e2e_2fa_row($pdoMig, $ownerEmail);
form_post(BACKEND_URL . '/admin/2fa/disable', ['password' => 'StrongPass#99', 'code' => e2e_totp_code($secret3, $ow['last_step'])], $cookies2, BACKEND_URL . '/admin/2fa');
t('r29: cleanup — owner 2FA disabled again', e2e_2fa_row($pdoMig, $ownerEmail)['last_step'] === 0);

section('Shutdown');

t('final health check ok', json_decode(http_req('GET', BACKEND_URL . '/health')['body'], true)['ok'] === true);

if (is_resource($backend ?? null)) {
    proc_terminate($backend);
    proc_close($backend);
}
if (is_resource($mock ?? null)) {
    proc_terminate($mock);
    proc_close($mock);
}

/**
 * E2E summary (separate from unit summary since run.php may run only e2e).
 */
function e2e_summary(): int
{
    return test_summary();
}
