<?php
/**
 * Peakopay — امضای دیجیتال وب‌هوک (HMAC-SHA256).
 *
 * قرارداد دقیقاً برابر پیاده‌سازی سمت سرور پیکو پی است:
 *   امضا = HMAC-SHA256(key = secret, data = timestamp . "." . rawBody)
 *   هدرها: X-Peakopay-Timestamp و X-Peakopay-Signature
 *   پنجره زمانی مجاز: ±۳۰۰ ثانیه (حفاظت در برابر replay)
 *
 * @package Peakopay
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (class_exists('Peakopay_Signature')) {
    return; // never declare twice
}

final class Peakopay_Signature
{
    /** حداکثر اختلاف مجاز زمان سرور ووکامرس با زمان امضا (ثانیه). */
    const MAX_SKEW = 300;

    /**
     * ساخت امضا (برای ارسال رویداد به سمت پیکو پی، در صورت نیاز).
     */
    public static function sign($timestamp, $rawBody, $secret)
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * اعتبارسنجی امضای دریافتی از پیکو پی.
     *
     * @param string $timestamp مقدار هدر X-Peakopay-Timestamp
     * @param string $signature مقدار هدر X-Peakopay-Signature
     * @param string $rawBody   بدنه خام درخواست (قبل از هرگونه decode)
     * @param string $secret    کلید وب‌هوک (whsec_…)
     * @return bool
     */
    public static function verify($timestamp, $signature, $rawBody, $secret)
    {
        $timestamp = (string) $timestamp;
        $signature = (string) $signature;
        if ($timestamp === '' || $signature === '' || $secret === '') {
            return false;
        }
        // پنجره زمانی — درخواست‌های قدیمی/آینده رد می‌شوند (replay protection).
        if (!ctype_digit($timestamp)) {
            return false;
        }
        $ts = (int) $timestamp;
        if (abs(time() - $ts) > self::MAX_SKEW) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($expected, strtolower($signature));
    }
}
