<?php
/**
 * Peakopay — کلاینت REST برای ارتباط با سرور Peakopay.
 *
 * احراز هویت فروشگاه (آرایه انجمنی هدر — هرگز آرایه عددی از رشته‌ها):
 *   X-Peakopay-Store: st_… (شناسه عمومی فروشگاه)
 *   Authorization: Bearer hpk_… (کلید API)
 *   X-Peakopay-Key: hpk_… (پشتیبان برای هاست‌های حذف‌کننده Authorization)
 * پاکت پاسخ: {"ok":true,"data":{…}} یا {"ok":false,"error":{"code","message"}}
 *
 * همه درخواست‌ها از طریق WordPress HTTP API (wp_remote_*) انجام می‌شوند —
 * بدون cURL مستقیم؛ timeout قابل تنظیم از صفحه تنظیمات درگاه است.
 *
 * PHP 7.4 compatible (توابع arrow، typed properties و ?string مجاز؛
 * بدون never / match / union types).
 *
 * @package Peakopay
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (class_exists('Peakopay_API')) {
    return; // never declare twice
}

final class Peakopay_API
{
    /**
     * ساخت/بازیابی نشست پرداخت برای یک سفارش.
     *
     * @param string $baseUrl  آدرس سرور Peakopay (بدون / انتها)
     * @param string $storeId  شناسه عمومی فروشگاه (st_…)
     * @param string $apiKey   کلید API (hpk_…)
     * @param array  $payload  بدنه درخواست
     * @param string $idemKey  کلید تکرارناپذیری (اختیاری)
     * @param int    $timeout  مهلت پاسخ سرور (ثانیه)
     * @return array{ok: bool, data?: array, error?: array{code: string, message: string}, status: int}
     */
    public static function create_payment($baseUrl, $storeId, $apiKey, array $payload, $idemKey = '', $timeout = 20)
    {
        $headers = self::auth_headers($storeId, $apiKey);
        if ($idemKey !== '') {
            $headers['Idempotency-Key'] = $idemKey;
        }

        return self::request(
            'POST',
            $baseUrl . '/api/v1/payments/create',
            wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $headers,
            $timeout
        );
    }

    /**
     * هدرهای احراز هویت — حتماً آرایه انجمنی (name => value).
     *
     * نکته حیاتی: WordPress HTTP API (WpOrg\Requests) آرایه‌های عددی از رشته‌های
     * «Name: value» را پشتیبانی نمی‌کند و هر عنصر به هدر خراب «0: کل رشته» تبدیل
     * می‌شود. (این دقیقاً علت خطای «شناسه فروشگاه یا کلید API ارسال نشده است» در
     * نسخه‌های قبل بود.)
     *
     * X-Peakopay-Key به‌عنوان پشتیبانِ Authorization فرستاده می‌شود چون برخی
     * هاست‌های CGI/FastCGI هدر Authorization را حذف می‌کنند.
     *
     * @return array<string, string>
     */
    private static function auth_headers($storeId, $apiKey)
    {
        return array(
            'Content-Type'  => 'application/json; charset=utf-8',
            'Accept'        => 'application/json',
            'X-Peakopay-Store' => (string) $storeId,
            'Authorization' => 'Bearer ' . $apiKey,
            'X-Peakopay-Key'  => (string) $apiKey,
        );
    }

    /**
     * استعلام وضعیت پرداخت (fallback صفحه تشکر).
     */
    public static function get_payment($baseUrl, $storeId, $apiKey, $token, $timeout = 15)
    {
        $headers = self::auth_headers($storeId, $apiKey);

        return self::request('GET', $baseUrl . '/api/v1/payments/' . rawurlencode($token), null, $headers, $timeout);
    }

    /**
     * آزمایش اتصال (دکمه «تست اتصال» در تنظیمات).
     */
    public static function test_connection($baseUrl, $storeId, $apiKey, $timeout = 15)
    {
        $headers = self::auth_headers($storeId, $apiKey);

        // اعتبارنامه‌ها در بدنه هم فرستاده می‌شوند (پشتیبان نهایی برای هاست‌هایی
        // که هر دو هدر سفارشی را حذف می‌کنند). سرور هر منبعی که معتبر باشد را می‌پذیرد.
        $body = wp_json_encode(array(
            'store_id' => (string) $storeId,
            'api_key'  => (string) $apiKey,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::request('POST', $baseUrl . '/api/v1/test-connection', $body, $headers, $timeout);
    }

    /**
     * اجرای درخواست HTTP با WordPress HTTP API.
     *
     * @return array{ok: bool, data?: array, error?: array{code: string, message: string}, status: int}
     */
    private static function request($method, $url, $body, array $headers, $timeout)
    {
        $args = array(
            'method'     => $method,
            'headers'    => $headers,
            'timeout'    => max(5, (int) $timeout),
            'sslverify'  => true, // HTTPS اجباری؛ در production هرگز خاموش نشود
            'user-agent' => 'Peakopay-WooCommerce/' . PEAKOPAY_VERSION . ' (WordPress)',
        );
        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // هیچ کلید یا مقدار محرمانه‌ای لاگ نمی‌شود — فقط پیام عمومی.
            return self::err('http_error', __("ارتباط با سرور Peakopay برقرار نشد:", 'peakopay') . ' ' . $response->get_error_message(), 0);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $json   = json_decode($raw, true);

        if (!is_array($json)) {
            return self::err('bad_response', __('پاسخ سرور Peakopay قابل خواندن نبود.', 'peakopay'), $status);
        }
        if (!empty($json['ok'])) {
            return array('ok' => true, 'data' => (array) ($json['data'] ?? array()), 'status' => $status);
        }
        $error = (array) ($json['error'] ?? array());

        return self::err((string) ($error['code'] ?? 'unknown'), (string) ($error['message'] ?? __('خطای نامشخص از سرور Peakopay.', 'peakopay')), $status);
    }

    private static function err($code, $message, $status)
    {
        return array('ok' => false, 'error' => array('code' => (string) $code, 'message' => (string) $message), 'status' => (int) $status);
    }
}
