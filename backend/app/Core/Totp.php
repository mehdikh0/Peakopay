<?php
/**
 * Peakopay — RFC 6238 TOTP (Time-based One-Time Password), کاملاً محلی.
 *
 * سازگار با Google Authenticator / Microsoft Authenticator / Aegis و هر
 * احرازگر سازگار با RFC 6238. هیچ سرویس خارجی (گوگل/اینترنت) در تولید یا
 * اعتبارسنجی کد نقشی ندارد — همه‌چیز با hash_hmac محلی محاسبه می‌شود.
 *
 * جزئیات استاندارد:
 *  - HMAC-SHA1، بازهٔ ۳۰ ثانیه، ۶ رقم (default های Google Authenticator).
 *  - تلورانس درفتم ساعت: پنجرهٔ ±۱ گام (کدِ «الان»، «۳۰ ثانیه قبل» و
 *    «۳۰ ثانیه بعد» پذیرفته می‌شود) — RFC 6238 §5.2.
 *  - جلوگیری از Replay: هر گامِ زمانی فقط یک بار پذیرفته می‌شود؛ شمارهٔ
 *    آخرین گامِ پذیرفته‌شده توسط مدل (TwoFactor) ذخیره و پاس می‌شود.
 */
declare(strict_types=1);

final class Totp
{
    public const STEP   = 30;  // ثانیه — استاندارد
    public const DIGITS = 6;   // رقم — استاندارد Google Authenticator
    public const WINDOW = 1;   // ±۱ گام تلورانس درفت ساعت

    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // ------------------------------------------------------------ Base32

    /** رمزگذاری Base32 (RFC 4648) بدون padding. */
    public static function base32Encode(string $bytes): string
    {
        $out  = '';
        $bits = 0;
        $val  = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $val  = ($val << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out  .= self::B32_ALPHABET[($val >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $out .= self::B32_ALPHABET[($val << (5 - $bits)) & 0x1F];
        }
        return $out;
    }

    /** رمزگشایی Base32 — فاصله‌ها/حروف کوچک/padding تحمل می‌شود. */
    public static function base32Decode(string $b32): string
    {
        $b32  = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        $out  = '';
        $bits = 0;
        $val  = 0;
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $v = strpos(self::B32_ALPHABET, $b32[$i]);
            if ($v === false) {
                continue;
            }
            $val  = ($val << 5) | $v;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out  .= chr(($val >> $bits) & 0xFF);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ secret

    /**
     * راز رمزنگارانهٔ یکتا برای هر کاربر — ۲۰ بایت تصادفی (CSPRNG) به‌صورت
     * Base32 (۳۲ نویسه)؛ همان پیش‌فرض Google Authenticator (۱۶۰ بیت).
     */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10 || $bytes > 64) {
            throw new InvalidArgumentException('TOTP secret size out of range.');
        }
        return self::base32Encode(random_bytes($bytes));
    }

    // ------------------------------------------------------------ HOTP/TOTP

    /** HOTP هسته (RFC 4226): HMAC-SHA1 + dynamic truncation + n رقم. */
    public static function hotp(string $keyBytes, int $counter, int $digits = self::DIGITS): string
    {
        $mac  = hash_hmac('sha1', str_pad(pack('J', $counter), 8, "\0", STR_PAD_LEFT), $keyBytes, true);
        $off  = ord($mac[19]) & 0x0F;
        $bin  = ((ord($mac[$off]) & 0x7F) << 24)
              | ((ord($mac[$off + 1]) & 0xFF) << 16)
              | ((ord($mac[$off + 2]) & 0xFF) << 8)
              | (ord($mac[$off + 3]) & 0xFF);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** کدِ زمانِ داده‌شده (برای تست و شبیه‌سازی). */
    public static function codeAt(string $secretB32, int $timestamp, int $digits = self::DIGITS): string
    {
        $key = self::base32Decode($secretB32);
        return self::hotp($key, intdiv($timestamp, self::STEP), $digits);
    }

    /** گامِ زمانی فعلی. */
    public static function step(int $timestamp): int
    {
        return intdiv($timestamp, self::STEP);
    }

    /**
     * اعتبارسنجی کد با تلورانس درفت ساعت و جلوگیری از Replay.
     *
     * @param string      $secretB32 راز Base32
     * @param string      $code      کد ۶ رقمی ورودی (فاصله/خط تیره پاک می‌شود)
     * @param int|null    $now       زمان مرجع (تست) — پیش‌فرض time()
     * @param int         $lastStep  آخرین گامِ قبلاً پذیرفته‌شده (Replay guard)
     * @param int         $window    تلورانس (پیش‌فرض ±۱ گام)
     *
     * @return int|false شمارهٔ گامِ پذیرفته‌شده، یا false
     */
    public static function verify(
        string $secretB32,
        string $code,
        ?int $now = null,
        int $lastStep = 0,
        int $window = self::WINDOW,
    ): int|false {
        $code = preg_replace('/[\s\-]/', '', $code) ?? '';
        if (!ctype_digit($code) || strlen($code) !== self::DIGITS) {
            return false;
        }
        $key = self::base32Decode($secretB32);
        if ($key === '') {
            return false;
        }
        $now  = $now ?? time();
        $step = self::step($now);
        // از جدیدترین گامِ مجاز به قدیمی‌ترین می‌رویم (ترجیح: کدِ همین لحظه)
        for ($s = $step + $window; $s >= $step - $window; $s--) {
            if ($s <= $lastStep) {
                continue; // این گام قبلاً مصرف شده — Replay ممنوع
            }
            if (hash_equals(self::hotp($key, $s), $code)) {
                return $s;
            }
        }
        return false;
    }

    // ------------------------------------------------------------ otpauth

    /**
     * نشانی otpauth:// استاندارد برای اسکن توسط احرازگرها.
     * label = issuer:account — همان چیزی که Google Authenticator انتظار دارد.
     */
    public static function otpauthUri(string $secretB32, string $accountName, string $issuer = 'Peakopay'): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);
        $query = http_build_query([
            'secret' => $secretB32,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits'   => (string) self::DIGITS,
            'period'   => (string) self::STEP,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $query;
    }
}
