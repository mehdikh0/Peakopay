<?php
/**
 * Peakopay — Key/value settings for the current store.
 *
 * Sensitive values are transparently encrypted at rest (see SENSITIVE).
 */
declare(strict_types=1);

final class Setting
{
    /** Values stored encrypted. */
    public const SENSITIVE = ['woo_webhook_secret', 'telegram_bot_token'];

    public const DEFAULTS = [
        // payment behaviour
        'payment_expiry_minutes'  => '60',
        'payment_tolerance_rial'  => '2000',
        'unique_amount'           => '1',
        'min_amount_rial'         => '20000',
        'max_amount_rial'         => '500000000',
        // matching
        'match_mode'              => 'tolerance', // tolerance | exact
        'match_by_card'           => '1',
        'match_by_reference'      => '0',
        'match_window_minutes'    => '1440',
        'auto_confirm'            => '1',
        // woocommerce
        'woo_enabled'             => '1',
        'woo_site_url'            => '',
        'woo_webhook_secret'      => '',      // encrypted
        'paid_order_status'       => 'processing', // processing | completed
        'expired_order_status'    => 'cancelled',  // cancelled | failed
        // notifications
        'notify_email_enabled'    => '0',
        'notify_email'            => '',
        'notify_telegram_enabled' => '0',
        'telegram_bot_token'      => '',      // encrypted
        'telegram_chat_id'        => '',
        'notify_event_paid'       => '1',
        'notify_event_unmatched'  => '1',
        'notify_event_device'     => '0',
        // security
        'session_timeout_minutes' => '240',
        'login_max_attempts'      => '5',
        'api_rate_per_minute'     => '60',
        // misc
        'test_mode'               => '0',
        'sms_rules'               => '',      // optional custom JSON rules (encrypted not needed)
    ];

    /**
     * خواندن تنظیمه — از نسخهٔ ۲.۰ با پارامتر اختیاری فروشگاه:
     * در زمینه‌های خارج از نشست (وب‌هوکِ کرون، تطبیق تراکنش فروشگاه دیگر و…)
     * باید فروشگاهِ صاحب داده پاس شود وگرنه فروشگاه جاریِ نشست (یا اولین
     * فروشگاه) برمی‌خواهد — دقیقاً همان باگی که وب‌هوک چندفروشگاهی را خراب می‌کرد.
     */
    public static function get(string $key, ?string $default = null, ?int $storeId = null): string
    {
        $storeId = $storeId ?? Helpers::currentStoreId();
        if ($storeId === null) {
            return $default ?? self::DEFAULTS[$key] ?? '';
        }
        $row = DB::one('SELECT svalue FROM settings WHERE store_id = ? AND skey = ?', [$storeId, $key]);
        if ($row === null) {
            return $default ?? self::DEFAULTS[$key] ?? '';
        }
        if (in_array($key, self::SENSITIVE, true)) {
            return (string) (Crypto::decrypt((string) $row['svalue']) ?? '');
        }
        return (string) $row['svalue'];
    }

    public static function set(string $key, string $value, ?int $storeId = null): void
    {
        $storeId = $storeId ?? Helpers::currentStoreId();
        if ($storeId === null) {
            throw new RuntimeException('Store not configured yet.');
        }
        if (in_array($key, self::SENSITIVE, true)) {
            $value = Crypto::encrypt($value);
        }
        DB::q(
            'INSERT INTO settings (store_id, skey, svalue) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
            [$storeId, $key, $value]
        );
    }

    /** All settings merged with defaults (decrypted, admin display). */
    public static function all(): array
    {
        $out = self::DEFAULTS;
        $storeId = Helpers::currentStoreId();
        if ($storeId !== null) {
            foreach (DB::all('SELECT skey, svalue FROM settings WHERE store_id = ?', [$storeId]) as $row) {
                $out[$row['skey']] = in_array($row['skey'], self::SENSITIVE, true)
                    ? (string) (Crypto::decrypt((string) $row['svalue']) ?? '')
                    : (string) $row['svalue'];
            }
        }
        return $out;
    }

    /**
     * دور ۲۳ — تنظیمات سطحِ «نصب» (install_meta): مقادیری که به یک فروشگاه
     * خاص وابسته نیستند (توکن کرون و…). جدا از ردیف‌های per-store.
     */
    public static function installGet(string $key, string $default = ''): string
    {
        try {
            $v = DB::val('SELECT svalue FROM install_meta WHERE skey = ?', [$key]);
            return $v === null || $v === '' ? $default : (string) $v;
        } catch (Throwable) {
            return $default; // جدول هنوز نیست (پیش از ۰۰۳)
        }
    }

    public static function installSet(string $key, string $value): void
    {
        DB::q(
            'INSERT INTO install_meta (skey, svalue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
            [$key, $value]
        );
    }

    public static function int(string $key, int $default = 0, ?int $storeId = null): int
    {
        return (int) self::get($key, (string) $default, $storeId);
    }

    public static function bool(string $key, bool $default = false, ?int $storeId = null): bool
    {
        $v = self::get($key, $default ? '1' : '0', $storeId);
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }
}
