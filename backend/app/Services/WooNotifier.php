<?php
/**
 * Peakopay — Outgoing WooCommerce webhook.
 *
 * POSTs signed JSON events to {woo_site_url}/wc-api/peakopay
 * Headers: X-Peakopay-Timestamp, X-Peakopay-Signature = HMAC-SHA256(secret, ts.body)
 * The plugin verifies both, then completes the order. Delivery is synchronous
 * (no cron): failures are recorded and can be retried from the admin panel.
 */
declare(strict_types=1);

final class WooNotifier
{
    public const SIGNATURE_HEADER = 'X-Peakopay-Signature';
    public const TIMESTAMP_HEADER = 'X-Peakopay-Timestamp';

    /** @param int|null $storeId فروشگاه صاحب رویداد — از نسخهٔ ۲.۰ الزامی برای زمینه‌های خارج از نشست */
    public static function endpoint(?int $storeId = null): string
    {
        $site = rtrim(Setting::get('woo_site_url', '', $storeId), '/');
        return $site === '' ? '' : $site . '/wc-api/peakopay';
    }

    public static function notifyPaymentPaid(array $payment): bool
    {
        $txn = $payment['transaction_id'] !== null ? Transaction::find((int) $payment['transaction_id']) : null;
        return self::send($payment, 'payment.paid', [
            'payment' => self::paymentPayload($payment),
            'transaction' => $txn === null ? null : [
                'id'         => (int) $txn['id'],
                'amount_rial' => (int) $txn['amount_rial'],
                'reference'  => (string) $txn['reference'],
                'bank'       => (string) $txn['bank'],
                'card_last4' => (string) $txn['dest_last4'],
                'occurred_at'=> (string) $txn['occurred_at'],
            ],
        ]);
    }

    public static function notifyPaymentExpired(array $payment): bool
    {
        return self::send($payment, 'payment.expired', ['payment' => self::paymentPayload($payment)]);
    }

    /**
     * دور ۲۵ — بستن پرداخت باز توسط فروشنده از اپ: رویداد cancelled به
     * ووکامرس می‌رود و سفارش (طبق تنظیم cancel_on_expire) لغو می‌شود.
     */
    public static function notifyPaymentCancelled(array $payment): bool
    {
        return self::send($payment, 'payment.cancelled', ['payment' => self::paymentPayload($payment)]);
    }

    /** "I have paid" pressed by the customer — informational note only. */
    public static function notifyPaymentClaim(array $payment): bool
    {
        return self::send($payment, 'payment.claim', ['payment' => self::paymentPayload($payment)]);
    }

    /** Admin "resend webhook" action. */
    public static function resend(int $paymentId): bool
    {
        $payment = Payment::find($paymentId);
        if ($payment === null) {
            return false;
        }
        if ($payment['status'] === 'paid') {
            return self::notifyPaymentPaid($payment);
        }
        if ($payment['status'] === 'expired') {
            return self::notifyPaymentExpired($payment);
        }
        return false;
    }

    public static function ping(): array
    {
        $payment = [
            'id' => 0, 'token' => 'ping', 'order_id' => '0', 'order_key' => '',
            'amount_rial' => 0, 'amount_base_rial' => 0, 'status' => 'ping', 'is_test' => 0,
        ];
        $ok = self::send($payment, 'ping', [], expectOk: true);
        return ['ok' => $ok];
    }

    // ------------------------------------------------------------ internals

    private static function paymentPayload(array $p): array
    {
        return [
            'id'              => (int) $p['id'],
            'token'           => (string) $p['token'],
            'order_id'        => (string) $p['order_id'],
            'order_key'       => (string) $p['order_key'],
            'amount_rial'     => (int) $p['amount_rial'],
            'amount_base_rial'=> (int) $p['amount_base_rial'],
            'status'          => (string) $p['status'],
            'paid_at'         => (string) ($p['paid_at'] ?? ''),
            'test'            => (int) ($p['is_test'] ?? 0) === 1,
        ];
    }

    private static function send(array $payment, string $event, array $extra = [], bool $expectOk = false): bool
    {
        $storeId = (int) $payment['store_id'];
        if ($storeId <= 0) {
            return false;
        }
        // چندفروشگاهی (دور ۲۳): URL/رمز/فعال‌بودنِ «همان فروشگاهِ پرداخت» —
        // قبلاً تنظیمات فروشگاه جاریِ نشست خوانده می‌شد و وب‌هوک فروشگاه B
        // با کلید فروشگاه A امضا/ارسال می‌شد.
        $url    = self::endpoint($storeId);
        // .env policy: restrict outbound webhooks to an explicit host list.
        $allowed = trim((string) Config::get('webhook_allowed_hosts', ''));
        if ($allowed !== '') {
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
            $list = array_filter(array_map('trim', explode(',', strtolower($allowed))));
            $okHost = $host !== '' && ($list === [] || in_array($host, $list, true));
            if (!$okHost) {
                Log::warn('webhook target host not allowed by WEBHOOK_ALLOWED_HOSTS: ' . $host);
                if ((int) $payment['id'] > 0) {
                    DB::update('payment_sessions', ['woo_notify_status' => 'blocked'], 'id = ?', [(int) $payment['id']]);
                }
                return false;
            }
        }
        $secret = Setting::get('woo_webhook_secret', '', $storeId);
        if (!Setting::bool('woo_enabled', true, $storeId) || $url === '' || $secret === '') {
            if ((int) $payment['id'] > 0) {
                DB::update('payment_sessions', ['woo_notify_status' => 'disabled'], 'id = ?', [(int) $payment['id']]);
            }
            return false;
        }

        $payload = array_merge([
            'event'     => $event,
            'store_id'  => (string) (DB::val('SELECT public_id FROM stores WHERE id = ?', [$storeId]) ?? ''),
            'timestamp' => time(),
        ], $extra);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts   = (string) time();
        $sig  = Crypto::hmac($ts . '.' . $body, $secret);

        $eventId = (int) $payment['id'] > 0 ? WebhookEvent::record($storeId, (int) $payment['id'], $event) : 0;

        [$ok, $message] = self::post($url, $body, $ts, $sig);
        if (!$ok) {
            // one quick retry (transient network) — still cron-free
            usleep(500000);
            [$ok, $message] = self::post($url, $body, $ts, $sig);
        }

        if ($eventId > 0) {
            $ok ? WebhookEvent::markDelivered($eventId) : WebhookEvent::markFailed($eventId, $message);
        }
        if ((int) $payment['id'] > 0) {
            DB::update('payment_sessions', [
                'woo_notify_status' => $ok ? 'sent' : 'failed',
                'woo_notified_at'   => $ok ? DB::now() : null,
            ], 'id = ?', [(int) $payment['id']]);
        }
        if (!$ok) {
            Log::error("woo webhook failed ({$event})", ['payment' => $payment['id'], 'error' => $message]);
        }
        return $ok;
    }

    /** @return array{0:bool,1:string} */
    private static function post(string $url, string $body, string $ts, string $sig): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            return [false, 'آدرس ووکامرس معتبر نیست.'];
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    self::TIMESTAMP_HEADER . ': ' . $ts,
                    self::SIGNATURE_HEADER . ': ' . $sig,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                return [false, 'cURL: ' . $err];
            }
            if ($code >= 200 && $code < 300) {
                $json = json_decode((string) $raw, true);
                if (is_array($json) && ($json['ok'] ?? null) === true) {
                    return [true, ''];
                }
                return [false, 'پاسخ ووکامرس: ' . mb_substr((string) $raw, 0, 200)];
            }
            return [false, 'HTTP ' . $code];
        }
        // fallback (rare hosts without cURL)
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" . self::TIMESTAMP_HEADER . ": {$ts}\r\n" . self::SIGNATURE_HEADER . ": {$sig}\r\n",
                'content' => $body,
                'timeout' => 12,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return [false, 'درگاه HTTP در دسترس نیست.'];
        }
        $json = json_decode($raw, true);
        return [(is_array($json) && ($json['ok'] ?? null) === true), ''];
    }
}
