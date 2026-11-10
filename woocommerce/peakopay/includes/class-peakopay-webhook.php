<?php
/**
 * Peakopay — دریافت وب‌هوک از سرور Peakopay.
 *
 * نقطه اتصال: {آدرس فروشگاه}/wc-api/peakopay
 * امنیت: امضای HMAC-SHA256 روی «timestamp . "." . rawBody» + پنجره ±۳۰۰ ثانیه.
 * رویدادها: ping، payment.paid، payment.expired، payment.failed (اختیاری)،
 * payment.cancelled (اختیاری)، payment.claim.
 *
 * قواعد:
 *   - «پرداخت کردم» هرگز مبنای تأیید نیست؛ فقط وب‌هوک معتبر از سرور.
 *   - هر سفارش حداکثر یک‌بار تکمیل می‌شود (idempotent).
 *   - رویدادهای تستی سفارش واقعی را تکمیل نمی‌کنند.
 *   - مبلغ وب‌هوک باید با مبلغ نشستِ ساخته‌شده برای همان سفارش برابر باشد.
 *
 * PHP 7.4 compatible (بدون never).
 *
 * @package Peakopay
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (class_exists('Peakopay_Webhook')) {
    return; // never declare twice
}

final class Peakopay_Webhook
{
    /** نقطه ورود wc-api. */
    public static function handle()
    {
        $raw = (string) file_get_contents('php://input');
        // هدرهای جدید X-Peakopay-* + پذیرش نام قدیمی X-HamrahPay-* (سازگاری سرورهای قدیمی)
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below, only compared via hash_equals
        $ts  = isset($_SERVER['HTTP_X_PEAKOPAY_TIMESTAMP']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_X_PEAKOPAY_TIMESTAMP'])) : (isset($_SERVER['HTTP_X_HAMRAHPAY_TIMESTAMP']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_X_HAMRAHPAY_TIMESTAMP'])) : '');
        $sig = isset($_SERVER['HTTP_X_PEAKOPAY_SIGNATURE']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_X_PEAKOPAY_SIGNATURE'])) : (isset($_SERVER['HTTP_X_HAMRAHPAY_SIGNATURE']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_X_HAMRAHPAY_SIGNATURE'])) : '');
        // phpcs:enable

        $secret = self::webhook_secret();

        if (!Peakopay_Signature::verify($ts, $sig, $raw, $secret)) {
            self::respond(array(
                'ok'    => false,
                'error' => array('code' => 'invalid_signature', 'message' => __('امضای وب‌هوک معتبر نیست.', 'peakopay')),
            ), 401);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['event'])) {
            self::respond(array(
                'ok'    => false,
                'error' => array('code' => 'bad_payload', 'message' => __('بدنه وب‌هوک معتبر نیست.', 'peakopay')),
            ), 400);
        }

        $event   = (string) $payload['event'];
        $payment = (array) ($payload['payment'] ?? array());

        switch ($event) {
            case 'ping':
                self::respond(array('ok' => true, 'pong' => true));
                break;

            case 'payment.paid':
                self::on_paid($payment, (array) ($payload['transaction'] ?? array()));
                break;

            case 'payment.expired':
            case 'payment.cancelled':
                self::on_expired_or_cancelled($payment, $event);
                break;

            case 'payment.failed':
                self::on_failed($payment);
                break;

            case 'payment.claim':
                self::on_claim($payment);
                break;

            default:
                self::log(__('رویداد ناشناخته دریافت شد:', 'peakopay') . ' ' . $event);
                self::respond(array('ok' => true, 'note' => 'unhandled_event_ignored'));
        }
        self::respond(array('ok' => true));
    }

    // ---------------------------------------------------------- رویدادها

    private static function on_paid(array $payment, array $transaction)
    {
        $orderId = (string) ($payment['order_id'] ?? '');
        $token   = (string) ($payment['token'] ?? '');
        $test    = !empty($payment['test']);
        $order   = $orderId !== '' ? wc_get_order((int) $orderId) : false;

        if (!($order instanceof WC_Order)) {
            /* translators: %s: order id */
            self::log(sprintf(__('وب‌هوک payment.paid برای سفارش ناشناخته #%s دریافت شد.', 'peakopay'), $orderId));
            self::respond(array('ok' => true, 'note' => 'order_not_found'));
        }

        // تراکنش تستی هرگز سفارش واقعی را تکمیل نمی‌کند.
        if ($test) {
            $order->add_order_note(__('Peakopay: یک پرداخت «تستی» برای این سفارش تأیید شد؛ وضعیت سفارش تغییر نکرد.', 'peakopay'));
            self::respond(array('ok' => true, 'note' => 'test_event_not_completed'));
        }

        // نشست باید متعلق به همین سفارش باشد.
        $storedToken = (string) $order->get_meta('_peakopay_token');
        if ($storedToken !== '' && $token !== '' && !hash_equals($storedToken, $token)) {
            /* translators: %s: order id */
            self::log(sprintf(__('توکن وب‌هوک با نشست سفارش #%s هم‌خوانی ندارد.', 'peakopay'), $orderId));
            self::respond(array('ok' => true, 'note' => 'token_mismatch_ignored'));
        }

        // مبلغ باید دقیقاً با مبلغ نشستِ ساخته‌شده برابر باشد.
        $expected = (int) $order->get_meta('_peakopay_amount_rial');
        $amount   = (int) ($payment['amount_rial'] ?? 0);
        if ($expected > 0 && $amount > 0 && $expected !== $amount) {
            /* translators: 1: webhook amount, 2: order id, 3: session amount */
            self::log(sprintf(__('مبلغ وب‌هوک (%1$d ریال) با مبلغ نشست سفارش #%2$s (%3$d ریال) برابر نیست — سفارش تکمیل نشد.', 'peakopay'), $amount, $orderId, $expected));
            /* translators: 1: expected amount, 2: received amount */
            $order->add_order_note(sprintf(__('Peakopay: عدم تطابق مبلغ — انتظار %1$s ریال، دریافتی %2$s ریال. بررسی دستی لازم است.', 'peakopay'), number_format($expected), number_format($amount)));
            $order->save();
            self::respond(array('ok' => true, 'note' => 'amount_mismatch_ignored'));
        }

        self::complete_order((int) $order->get_id(), $payment, $transaction, __('وب‌هوک', 'peakopay'));
        self::respond(array('ok' => true));
    }

    private static function on_expired_or_cancelled(array $payment, $event)
    {
        $orderId = (string) ($payment['order_id'] ?? '');
        $order   = $orderId !== '' ? wc_get_order((int) $orderId) : false;

        if (!($order instanceof WC_Order)) {
            self::respond(array('ok' => true, 'note' => 'order_not_found'));
        }
        // سفارش پرداخت‌شده هرگز تنزل نمی‌کند.
        if ($order->has_status(array('processing', 'completed'))) {
            self::respond(array('ok' => true, 'note' => 'paid_order_not_downgraded'));
        }
        $cancelOnExpire = self::gateway_option('cancel_on_expire', 'yes') !== 'no';
        if ($cancelOnExpire && $order->has_status(array('pending', 'on-hold', 'failed'))) {
            $note = $event === 'payment.cancelled'
                ? __('Peakopay: نشست پرداخت لغو شد.', 'peakopay')
                : __('Peakopay: مهلت پرداخت به پایان رسید و وجهی وصول نشد.', 'peakopay');
            $order->update_status('cancelled', $note);
        } else {
            $order->add_order_note(__('Peakopay: مهلت پرداخت به پایان رسید.', 'peakopay'));
            $order->save();
        }
        self::respond(array('ok' => true));
    }

    private static function on_failed(array $payment)
    {
        $orderId = (string) ($payment['order_id'] ?? '');
        $order   = $orderId !== '' ? wc_get_order((int) $orderId) : false;
        if ($order instanceof WC_Order) {
            if (!$order->has_status(array('processing', 'completed'))) {
                $order->update_status('failed', __('Peakopay: پرداخت ناموفق بود (تأیید سرور).', 'peakopay'));
            }
        }
        self::respond(array('ok' => true, 'note' => 'failed_recorded'));
    }

    private static function on_claim(array $payment)
    {
        $orderId = (string) ($payment['order_id'] ?? '');
        $order   = $orderId !== '' ? wc_get_order((int) $orderId) : false;
        if ($order instanceof WC_Order) {
            // صرفاً اطلاع‌رسانی؛ مبنای تأیید نیست.
            $order->add_order_note(__('Peakopay: مشتری اعلام کرد وجه را منتقل کرده است؛ در انتظار تطبیق تراکنش بانکی.', 'peakopay'));
            $order->save();
        }
        self::respond(array('ok' => true, 'note' => 'customer_claimed'));
    }

    // ---------------------------------------------------------- تکمیل سفارش

    /**
     * تکمیل idempotent سفارش — هم از وب‌هوک و هم از استعلام صفحه تشکر صدا زده می‌شود.
     *
     * @return bool
     */
    public static function complete_order($orderId, array $payment, array $transaction, $source = '')
    {
        $order = wc_get_order((int) $orderId);
        if (!($order instanceof WC_Order)) {
            return false;
        }
        if ($source === '') {
            $source = __('وب‌هوک', 'peakopay');
        }
        // از قبل پرداخت‌شده — فقط یک بار (حفاظت در برابر وب‌هوک تکراری)
        if ((string) $order->get_meta('_peakopay_paid') === '1' || $order->has_status(array('processing', 'completed'))) {
            return true;
        }

        $reference = (string) ($transaction['reference'] ?? '');
        $bank      = (string) ($transaction['bank'] ?? '');

        $order->update_meta_data('_peakopay_paid', '1');
        $order->update_meta_data('_peakopay_reference', $reference);
        $order->update_meta_data('_peakopay_bank', $bank);

        // payment_complete ووکامرس وضعیت نهایی را بر اساس نوع محصولات انتخاب می‌کند
        // (processing برای فیزیکی، completed برای مجازی) و idempotent است.
        $order->payment_complete($reference !== '' ? $reference : ('peakopay-' . ((string) ($payment['token'] ?? ''))));

        /* translators: 1: verification source, 2: bank name, 3: tracking code */
        $order->add_order_note(sprintf(
            __('Peakopay: پرداخت تأیید شد (%1$s) — بانک: %2$s — کد رهگیری: %3$s', 'peakopay'),
            $source,
            $bank !== '' ? $bank : __('نامشخص', 'peakopay'),
            $reference !== '' ? $reference : __('ندارد', 'peakopay')
        ));
        $order->save();

        return true;
    }

    // ---------------------------------------------------------- کمکی

    private static function gateway()
    {
        if (function_exists('WC') && WC() && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (is_array($gateways) && isset($gateways['peakopay'])) {
                return $gateways['peakopay'];
            }
        }
        return null;
    }

    private static function gateway_option($key, $default = '')
    {
        $gw = self::gateway();
        if ($gw instanceof Peakopay_Gateway) {
            return (string) $gw->get_option($key, $default);
        }
        // خواندن مستقیم از settings (وقتی WC() هنوز در دسترس نیست)
        $settings = get_option('woocommerce_peakopay_settings', array());
        return (string) (is_array($settings) && isset($settings[$key]) ? $settings[$key] : $default);
    }

    private static function webhook_secret()
    {
        return trim(self::gateway_option('webhook_secret', ''));
    }

    private static function respond(array $body, $status = 200)
    {
        if (!headers_sent()) {
            if (function_exists('status_header')) {
                status_header((int) $status);
            } else {
                http_response_code((int) $status);
            }
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** لاگ امن — بدون هیچ کلید/مقدار محرمانه. */
    private static function log($message)
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning($message, array('source' => 'peakopay'));
        }
    }
}
