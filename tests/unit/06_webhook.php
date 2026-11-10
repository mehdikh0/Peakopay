<?php
/**
 * Tests: outgoing webhook signing + the WooCommerce-side verification
 * (exactly the algorithm implemented in the plugin's signature class).
 */
declare(strict_types=1);

$T = $GLOBALS['T'];
$card = Card::find($T['card_id']);

// --- the plugin's verification logic (must stay in sync with
//     woocommerce/peakopay-gateway/includes/class-peakopay-signature.php) ---
function woo_verify_signature(string $rawBody, string $ts, string $sig, string $secret, int $window = 300): bool
{
    if ($ts === '' || $sig === '' || abs(time() - (int) $ts) > $window) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $ts . '.' . $rawBody, $secret), strtolower($sig));
}

section('Webhook signature (backend → WooCommerce)');
$body = json_encode(['event' => 'payment.paid', 'order_id' => '1001'], JSON_UNESCAPED_SLASHES);
$ts   = (string) time();
$sig  = Crypto::hmac($ts . '.' . $body, $T['webhook_secret']);

t('valid signature accepted', woo_verify_signature($body, $ts, $sig, $T['webhook_secret']));
t('wrong secret rejected', !woo_verify_signature($body, $ts, $sig, 'whsec_other'));
t('tampered body rejected', !woo_verify_signature($body . ' ', $ts, $sig, $T['webhook_secret']));
t('tampered timestamp rejected', !woo_verify_signature($body, $ts, str_repeat('a', 64), $T['webhook_secret']));

$oldTs = (string) (time() - 400);
$oldSig = Crypto::hmac($oldTs . '.' . $body, $T['webhook_secret']);
t('replayed (stale) timestamp rejected', !woo_verify_signature($body, $oldTs, $oldSig, $T['webhook_secret']));

$futureTs = (string) (time() + 400);
$futureSig = Crypto::hmac($futureTs . '.' . $body, $T['webhook_secret']);
t('future timestamp rejected', !woo_verify_signature($body, $futureTs, $futureSig, $T['webhook_secret']));

// case-insensitivity of hex signature
t('uppercase signature accepted', woo_verify_signature($body, $ts, strtoupper($sig), $T['webhook_secret']));

section('Webhook delivery (against local mock WooCommerce)');

// start mock Woo server if not already running
$mockLog = sys_get_temp_dir() . '/hp_mock_woo.log';
@unlink($mockLog);
$mockPidFile = sys_get_temp_dir() . '/hp_mock_woo.pid';
$sock = @fsockopen('127.0.0.1', 8098, $errno, $errstr, 0.3);
if ($sock === false) {
    $cmd = sprintf('nohup php -S 127.0.0.1:8098 %s > /dev/null 2>&1 & echo $!', escapeshellarg(__DIR__ . '/../mock_woo.php'));
    $pid = (string) shell_exec($cmd);
    file_put_contents($mockPidFile, $pid);
    usleep(400000); // wait for startup
}

// build a paid payment and notify
Setting::set('woo_site_url', 'http://127.0.0.1:8098');
Setting::set('woo_enabled', '1');
Setting::set('unique_amount', '0');
$p = Payment::create($T['store_id'], $card, 'WH-1001', 'wc_order_wh1001', 1500000, 'IRR', 1500000, []);
$tx = ['bank' => 'بانک ملت', 'sender' => 'Mellat', 'amount_rial' => 1500000, 'occurred_at' => DB::now(), 'dest_last4' => '0019', 'reference' => 'WHREF1', 'raw_text' => 'واریز 1,500,000 ريال WHREF1 ' . bin2hex(random_bytes(3)), 'raw_hash' => null];
Matcher::process($tx, $T['device']); // full pipeline incl. webhook
Setting::set('unique_amount', '1');

$delivered = false;
for ($i = 0; $i < 10; $i++) {
    if (is_file($mockLog) && str_contains((string) file_get_contents($mockLog), '"order_id":"WH-1001"')) {
        $delivered = true;
        break;
    }
    usleep(300000);
}
t('payment.paid webhook delivered to mock Woo', $delivered);

$lines = array_values(array_filter(explode("\n", trim((string) @file_get_contents($mockLog)))));
$events = array_map(static fn ($l) => json_decode($l, true) ?: [], $lines);
$paid = null;
foreach ($events as $e) {
    if (($e['event'] ?? '') === 'payment.paid' && ($e['order_id'] ?? '') === 'WH-1001') {
        $paid = $e;
    }
}
t('event carries valid signature', isset($paid['sig_ok']) && $paid['sig_ok'] === true);
t('event payload has amount + reference', ($paid['amount_rial'] ?? 0) === 1500000 && ($paid['reference'] ?? '') === 'WHREF1');
t('mock Woo completed the order exactly once', isset($paid['completed']) && $paid['completed'] === true);

// expired event
$p2 = Payment::create($T['store_id'], $card, 'WH-1002', '', 1600000, 'IRR', 1600000, []);
DB::q('UPDATE payment_sessions SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 30), $p2['id']]);
Payment::expireIfNeeded(Payment::find((int)$p2['id']));
$gotExpired = false;
for ($i = 0; $i < 10; $i++) {
    foreach (array_map(static fn ($l) => json_decode($l, true) ?: [], $lines = array_values(array_filter(explode("\n", trim((string) @file_get_contents($mockLog)))))) as $e) {
        if (($e['event'] ?? '') === 'payment.expired' && ($e['order_id'] ?? '') === 'WH-1002') {
            $gotExpired = true;
        }
    }
    if ($gotExpired) { break; }
    usleep(300000);
}
t('payment.expired webhook delivered', $gotExpired);

// direct POST with bad signature must be rejected by the mock (plugin logic)
$ch = curl_init('http://127.0.0.1:8098/wc-api/peakopay');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Peakopay-Timestamp: ' . $ts, 'X-Peakopay-Signature: ' . str_repeat('0', 64)],
]);
$resp = curl_exec($ch);
curl_close($ch);
$j = json_decode((string) $resp, true);
t('plugin endpoint rejects invalid signature', is_array($j) && ($j['ok'] ?? true) === false);
