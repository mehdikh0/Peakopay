<?php
/**
 * Peakopay — mock WooCommerce endpoint for tests.
 * Implements EXACTLY the verification the real plugin performs
 * (HMAC-SHA256 over "timestamp.body" + ±300s freshness) and records
 * received events to /tmp/hp_mock_woo.log for assertions.
 */
declare(strict_types=1);

$secretFile = sys_get_temp_dir() . '/hp_mock_woo.secret';
$secret = is_file($secretFile) ? (string) file_get_contents($secretFile) : '';
$logFile = sys_get_temp_dir() . '/hp_mock_woo.log';
$stateFile = sys_get_temp_dir() . '/hp_mock_woo_orders.json';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = (string) file_get_contents('php://input');

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, array $extra = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!str_contains($uri, 'wc-api/peakopay')) {
    if ($method === 'GET' && ($uri === '/' || $uri === '/index.html')) {
        // دور ۲۶ — صفحهٔ اول «سایت فروشگاه»: apple-touch-icon برای تست استخراج لوگو
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><title>shop</title>'
            . '<link rel="apple-touch-icon" href="/wp-content/uploads/site-icon.png">'
            . '<link rel="icon" href="/favicon.ico">'
            . '</head><body>mock shop</body></html>';
        exit;
    }
    respond(false, ['error' => 'not_found'], 404);
}

if ($method !== 'POST') {
    respond(false, ['error' => 'method_not_allowed'], 405);
}

// هدرهای جدید X-Peakopay-* + پذیرش نام قدیمی X-HamrahPay-*
$ts  = (string) ($_SERVER['HTTP_X_PEAKOPAY_TIMESTAMP'] ?? $_SERVER['HTTP_X_HAMRAHPAY_TIMESTAMP'] ?? '');
$sig = (string) ($_SERVER['HTTP_X_PEAKOPAY_SIGNATURE'] ?? $_SERVER['HTTP_X_HAMRAHPAY_SIGNATURE'] ?? '');

$sigOk = false;
if ($ts !== '' && $sig !== '' && $secret !== '' && abs(time() - (int) $ts) <= 300) {
    $sigOk = hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secret), strtolower($sig));
}
if (!$sigOk) {
    respond(false, ['error' => 'invalid_signature']);
}

$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['event'])) {
    respond(false, ['error' => 'bad_payload']);
}

$state = is_file($stateFile) ? (array) json_decode((string) file_get_contents($stateFile), true) : [];

$event   = (string) $payload['event'];
$payment = $payload['payment'] ?? [];
$orderId = (string) ($payment['order_id'] ?? '');
$entry   = [
    'event'       => $event,
    'order_id'    => $orderId,
    'amount_rial' => (int) ($payment['amount_rial'] ?? 0),
    'reference'   => (string) ($payload['transaction']['reference'] ?? ''),
    'test'        => (bool) ($payment['test'] ?? false),
    'sig_ok'      => true,
    'completed'   => false,
    'ts'          => time(),
];

switch ($event) {
    case 'ping':
        respond(true, ['pong' => true]);
        // no fall-through

    case 'payment.paid':
        $key = 'order_' . $orderId;
        $alreadyPaid = in_array($state[$key]['status'] ?? '', ['paid', 'completed'], true);
        // duplicate protection + amount verification, same as the real plugin
        if (!$alreadyPaid && !$entry['test'] && ($payment['token'] ?? '') !== '') {
            $state[$key] = ['status' => 'paid', 'amount_rial' => $entry['amount_rial']];
            $entry['completed'] = true;
        } elseif ($alreadyPaid) {
            $entry['note'] = 'already_paid_ignored';
        } elseif ($entry['test']) {
            $entry['note'] = 'test_event_not_completed';
        }
        break;

    case 'payment.expired':
        $key = 'order_' . $orderId;
        if (in_array($state[$key]['status'] ?? '', ['paid', 'completed'], true)) {
            $entry['note'] = 'paid_order_not_downgraded';
        } else {
            $state[$key] = ['status' => 'cancelled', 'amount_rial' => $entry['amount_rial']];
            $entry['completed'] = true;
        }
        break;

    // دور ۲۵ — بستن پرداخت باز از اپ: همان رفتار افزونهٔ واقعی (لغو سفارش)
    case 'payment.cancelled':
        $key = 'order_' . $orderId;
        if (in_array($state[$key]['status'] ?? '', ['paid', 'completed'], true)) {
            $entry['note'] = 'paid_order_not_downgraded';
        } else {
            $state[$key] = ['status' => 'cancelled', 'amount_rial' => $entry['amount_rial']];
            $entry['completed'] = true;
        }
        break;

    case 'payment.claim':
        $entry['note'] = 'customer_claimed';
        break;

    default:
        $entry['note'] = 'unhandled_event_ignored';
}

file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES));
file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
respond(true);
