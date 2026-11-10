<?php
/**
 * WooCommerce plugin — signature contract test.
 * Ensures the plugin's HMAC verification accepts exactly what the backend
 * WooNotifier sends (and rejects everything else).
 */
declare(strict_types=1);

section('WooCommerce plugin signature (backend ↔ plugin contract)');

$secret = 'whsec_live_abcdef0123456789abcdef0123456789';
$body   = json_encode(['event' => 'payment.paid', 'payment' => ['order_id' => '1001', 'amount_rial' => 2500000, 'token' => str_repeat('a', 40), 'test' => false]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// خارج از وردپرس: ABSPATH ساختگی تا گارد افزونه require را متوقف نکند
if (!defined('ABSPATH')) {
    define('ABSPATH', HP_BASE . '/');
}
require HP_BASE . '/../woocommerce/peakopay/includes/class-peakopay-signature.php';


$ts  = (string) time();
$sig = Crypto::hmac($ts . '.' . $body, $secret);
t('plugin verify accepts backend signature', Peakopay_Signature::verify($ts, $sig, $body, $secret));
t('plugin verify accepts uppercase signature', Peakopay_Signature::verify($ts, strtoupper($sig), $body, $secret));
t('plugin verify rejects wrong secret', !Peakopay_Signature::verify($ts, $sig, $body, 'whsec_other'));
t('plugin verify rejects tampered body', !Peakopay_Signature::verify($ts, $sig, $body . ' ', $secret));
t('plugin verify rejects tampered timestamp', !Peakopay_Signature::verify((string) ((int) $ts + 1), $sig, $body, $secret));
$oldTs = (string) (time() - 400);
t('plugin verify rejects replayed timestamp', !Peakopay_Signature::verify($oldTs, Crypto::hmac($oldTs . '.' . $body, $secret), $body, $secret));
$futTs = (string) (time() + 400);
t('plugin verify rejects future timestamp', !Peakopay_Signature::verify($futTs, Crypto::hmac($futTs . '.' . $body, $secret), $body, $secret));
t('plugin verify rejects empty headers/secret', !Peakopay_Signature::verify('', $sig, $body, $secret) && !Peakopay_Signature::verify($ts, '', $body, $secret) && !Peakopay_Signature::verify($ts, $sig, $body, ''));
t('plugin sign() matches backend hmac', Peakopay_Signature::sign($ts, $body, $secret) === $sig);
