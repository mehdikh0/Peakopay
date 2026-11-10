<?php
/**
 * Peakopay — Shared helpers: escaping, Persian digits/numbers, Jalali dates,
 * money formatting and small utilities used across templates.
 */
declare(strict_types=1);

final class Helpers
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 0-9 → ۰-۹ (accepts int|string) */
    public static function faDigits(int|string $s): string
    {
        return strtr((string) $s, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    /** ۰-۹ and ٠-٩ → 0-9 */
    public static function enDigits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => ',',
        ]);
    }

    /** 1,500,000 ریال (Persian digits) */
    public static function money(int|float|string|null $amount, string $unit = 'ریال'): string
    {
        $n = (int) self::enDigits((string) $amount);
        return self::faDigits(number_format($n)) . ($unit === '' ? '' : ' ' . $unit);
    }

    public static function toman(int $rial): int
    {
        return intdiv($rial, 10);
    }

    /** Id of the (single) store — null before installation completes. */
    /** زمینهٔ فروشگاهِ تحمیلی برای کارهای خارج از نشست (دور ۲۳ — چندفروشگاهی). */
    private static ?int $forcedStore = null;

    /**
     * اجرای $fn با «فروشگاه جاری» مشخص — برای مسیرهایی که فروشگاه از داده
     * مشخص است (تطبیق تراکنش، ساخت پرداخت، وب‌هوک): همهٔ Setting:: و کدهای
     * وابسته به currentStoreId داخل همان فروشگاه resolving می‌شوند و دیگر
     * تنظیماتِ فروشگاه اول یا فروشگاه جاریِ نشست به‌اشتباه خوانده نمی‌شود.
     */
    public static function withStore(int $storeId, callable $fn): mixed
    {
        $prev = self::$forcedStore;
        self::$forcedStore = $storeId;
        try {
            return $fn();
        } finally {
            self::$forcedStore = $prev;
        }
    }

    public static function currentStoreId(): ?int
    {
        if (self::$forcedStore !== null) {
            return self::$forcedStore;
        }
        static $id = false;
        if ($id !== false) {
            return $id;
        }
        try {
            // آگاه از نشست/عضویت (دور ۲۳) — جزئیات در Store::current()
            $s   = Store::current();
            $id  = ($s !== null && isset($s['id'])) ? (int) $s['id'] : null;
        } catch (Throwable) {
            $id = null;
        }
        return $id;
    }

    public static function storeTimezone(): string
    {
        try {
            $tz = Setting::get('timezone', 'Asia/Tehran');
            return in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'Asia/Tehran';
        } catch (Throwable) {
            return 'Asia/Tehran';
        }
    }

    /** Convert a UTC MySQL datetime to the store timezone. */
    public static function toLocal(?string $utcMysql): ?DateTimeImmutable
    {
        if ($utcMysql === null || $utcMysql === '' || str_starts_with($utcMysql, '0000')) {
            return null;
        }
        try {
            return (new DateTimeImmutable($utcMysql . ' UTC', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(self::storeTimezone()));
        } catch (Throwable) {
            return null;
        }
    }

    /** Gregorian → Jalali (standard algorithm). @return array{int,int,int} */
    public static function g2j(int $gy, int $gm, int $gd): array
    {
        $gdm  = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2  = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
              + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy   = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy  += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy  += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            return [$jy, 1 + intdiv($days, 31), 1 + ($days % 31)];
        }
        return [$jy, 7 + intdiv($days - 186, 30), 1 + (($days - 186) % 30)];
    }

    /** Jalali month names. */
    public static function jMonth(int $m): string
    {
        $names = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        return $names[$m] ?? '';
    }

    /** Format a UTC MySQL datetime in the store timezone as Jalali. Default: ۱۴۰۳/۰۵/۱۲ — ۱۴:۳۰ */
    public static function jdate(?string $utcMysql, bool $withTime = true): string
    {
        $local = self::toLocal($utcMysql);
        if ($local === null) {
            return '—';
        }
        [$jy, $jm, $jd] = self::g2j((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
        $out = self::faDigits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
        if ($withTime) {
            $out .= ' — ' . self::faDigits($local->format('H:i'));
        }
        return $out;
    }

    /** Human-friendly relative time in Persian. */
    public static function ago(?string $utcMysql): string
    {
        $local = self::toLocal($utcMysql);
        if ($local === null) {
            return '—';
        }
        // نکته: getTimestamp() برای DateTime که به منطقه محلی تبدیل شده همان epoch
        // مطلق رویداد است — کسر دوباره offset باعث می‌شد همه «X پیش»ها ۳:۳۰ ساعت
        // جلوتر نمایش داده شوند (باگ قدیمی، اینجا رفع شد).
        $diff = time() - $local->getTimestamp();
        if ($diff < 0) {
            $diff = abs($diff);
            $suffix = 'دیگر';
        } else {
            $suffix = 'پیش';
        }
        if ($diff < 60)      return self::faDigits($diff) . ' ثانیه ' . $suffix;
        if ($diff < 3600)    return self::faDigits(intdiv($diff, 60)) . ' دقیقه ' . $suffix;
        if ($diff < 86400)   return self::faDigits(intdiv($diff, 3600)) . ' ساعت ' . $suffix;
        if ($diff < 2592000) return self::faDigits(intdiv($diff, 86400)) . ' روز ' . $suffix;
        return self::jdate($utcMysql, false);
    }

    /** Status → [Persian label, css badge class]. */
    public static function statusLabel(string $status): array
    {
        $map = [
            // payments
            'pending'   => ['در انتظار پرداخت', 'badge-warn'],
            'verifying' => ['در حال بررسی', 'badge-info'],
            'paid'      => ['پرداخت شد', 'badge-ok'],
            'expired'   => ['منقضی شد', 'badge-mute'],
            'failed'    => ['ناموفق', 'badge-bad'],
            // transactions
            'received'  => ['دریافت شد', 'badge-info'],
            'matched'   => ['تطبیق یافت', 'badge-info'],
            'confirmed' => ['تأیید شد', 'badge-ok'],
            'duplicate' => ['تکراری', 'badge-mute'],
            'unmatched' => ['بدون تطبیق', 'badge-warn'],
            'dismissed' => ['نادیده گرفته شد', 'badge-mute'],
            'rejected'  => ['رد شد', 'badge-bad'],
            // devices
            'active'    => ['متصل', 'badge-ok'],
            'revoked'   => ['لغو دسترسی', 'badge-bad'],
        ];
        return $map[$status] ?? [$status, 'badge-mute'];
    }

    public static function asset(string $path): string
    {
        return Config::url('assets/' . ltrim($path, '/')) . '?v=' . HP_VERSION;
    }

    /** Truncate + ellipsis for tables. */
    public static function trunc(?string $s, int $len = 40): string
    {
        $s = (string) $s;
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }
}
