<?php
/**
 * Peakopay — System diagnostics for the admin panel (non-technical users).
 * Each check: status = ok | warning | error + a human-friendly explanation.
 */
declare(strict_types=1);

final class Diagnostics
{
    /** @return array<int, array{key:string,title:string,status:string,message:string}> */
    public static function run(bool $deep = false): array
    {
        $out = [];
        $add = static function (string $key, string $title, string $status, string $message) use (&$out): void {
            $out[] = compact('key', 'title', 'status', 'message');
        };

        // --- PHP ---
        $phpOk = PHP_VERSION_ID >= 80100;
        $add('php', 'نسخه PHP', $phpOk ? 'ok' : 'error',
            $phpOk ? 'نسخه فعلی ' . PHP_VERSION . ' — کاملاً سازگار.' : 'نسخه ' . PHP_VERSION . ' قدیمی است؛ حداقل PHP 8.1 لازم است.');

        // --- extensions ---
        foreach (['pdo_mysql' => 'PDO MySQL', 'openssl' => 'OpenSSL', 'curl' => 'cURL', 'mbstring' => 'mbstring'] as $ext => $label) {
            $loaded = extension_loaded($ext);
            $add('ext_' . $ext, 'افزونه ' . $label, $loaded ? 'ok' : 'error',
                $loaded ? 'فعال است.' : 'غیرفعال است — از cPanel → Select PHP Version فعالش کنید.');
        }
        $sodium = extension_loaded('sodium');
        $add('ext_sodium', 'افزونه Sodium', $sodium ? 'ok' : 'warning',
            $sodium ? 'فعال است؛ رمزنگاری با Sodium انجام می‌شود.'
                    : 'غیرفعال است (اختیاری). پیکو پی در حال حاضر از AES-256-GCM استفاده می‌کند و همه‌چیز امن کار می‌کند؛ فقط اگر بعداً این افزونه اضافه شود، داده‌های جدید با Sodium رمز می‌شوند.');
        $add('ext_gd_like', 'رمزنگاری سرور', Crypto::hasSodium() || function_exists('openssl_encrypt') ? 'ok' : 'error',
            Crypto::hasSodium() ? 'Sodium secretbox (XSalsa20-Poly1305)' : (function_exists('openssl_encrypt') ? 'OpenSSL AES-256-GCM' : 'هیچ الگوریتم رمزنگاری در دسترس نیست!'));

        // --- HTTPS ---
        $https = Http::isHttps();
        $add('https', 'اتصال امن HTTPS', $https ? 'ok' : 'warning',
            $https ? 'پنل و صفحه پرداخت با HTTPS سرو می‌شود.' : 'HTTPS فعال نیست. برای امنیت اطلاعات کاربران و اعتماد مشتریان، گواهی SSL (در cPanel رایگان) فعال کنید. سیستم فعلاً کار می‌کند.');

        // --- storage ---
        $logsWritable = is_dir(HP_BASE . '/storage/logs') && is_writable(HP_BASE . '/storage/logs');
        $add('storage', 'دسترسی نوشتن لاگ‌ها', $logsWritable ? 'ok' : 'error',
            $logsWritable ? 'مجلد storage/logs قابل نوشتن است.' : 'مجلد storage/logs قابل نوشتن نیست — سطح دسترسی را ۷۵۵ یا بالاتر کنید.');

        // --- database ---
        try {
            $ver = (string) DB::val('SELECT VERSION()');
            $add('db', 'اتصال دیتابیس', 'ok', 'متصل — ' . $ver);
        } catch (Throwable $e) {
            $add('db', 'اتصال دیتابیس', 'error', 'خطا در اتصال: ' . mb_substr($e->getMessage(), 0, 160));
            return $out;
        }

        // ══ محافظ دور ۱۸: همه بررسی‌های وابسته به DB ══
        // اگر schema ناقص باشد (مثلاً ارتقای ناقص)، این صفحه خودش نباید ۵۰۰ شود —
        // چون دکمه «تعمیر پایگاه داده» همین‌جاست و باید همیشه در دسترس باشد.
        try {
        // --- encryption roundtrip ---
        try {
            $probe = 'peakopay-probe-' . Crypto::randomHex(6);
            $back  = Crypto::decrypt(Crypto::encrypt($probe));
            $add('crypto', 'رمزنگاری اطلاعات', $back === $probe ? 'ok' : 'error',
                $back === $probe ? 'آزمون رمز/رمزگشایی موفق بود.' : 'نتیجه رمزگشایی با ورودی یکی نیست!');
        } catch (Throwable $e) {
            $add('crypto', 'رمزنگاری اطلاعات', 'error', 'خطا: ' . mb_substr($e->getMessage(), 0, 160));
        }

        // --- migrations ---
        $pending = count(glob(HP_BASE . '/migrations/*.sql') ?: []) - (int) DB::val('SELECT COUNT(*) FROM migrations');
        // بررسی واقعی schema (دور ۱۸): جدول/ستون حیاتی که وجود ندارد = خطا، نه هشدار
        $schemaMissing = [];
        try {
            $schemaMissing = Installer::schemaStatus()['missing'];
        } catch (Throwable) {
        }
        if ($schemaMissing !== []) {
            $add('migrations', 'پایگاه داده و مهاجرت‌ها', 'error',
                'این موارد وجود ندارند: ' . implode('، ', $schemaMissing)
                . ' — دکمه «🛠 تعمیر پایگاه داده» را بزنید؛ اگر رفع نشد بسته سرور را کامل باز کنید.');
        } else {
            $add('migrations', 'پایگاه داده و مهاجرت‌ها', $pending <= 0 ? 'ok' : 'warning',
                $pending <= 0 ? 'همه جدول‌ها به‌روز هستند.' : "{$pending} فایل مهاجرت اعمال نشده است.");
        }

        // --- store ---
        $store = Store::get();
        $add('store', 'تنظیمات فروشگاه', $store !== null ? 'ok' : 'error',
            $store !== null ? 'فروشگاه «' . $store['name'] . '» پیکربندی شده است.' : 'فروشگاه پیکربندی نشده؛ نصب را کامل کنید.');

        // --- cards ---
        $cardCount = $store !== null ? (int) DB::val('SELECT COUNT(*) FROM cards WHERE store_id = ? AND is_active = 1', [(int) $store['id']]) : 0;
        $add('cards', 'کارت مقصد', $cardCount > 0 ? 'ok' : 'error',
            $cardCount > 0 ? "{$cardCount} کارت فعال تنظیم شده است." : 'هیچ کارت مقصد فعالی وجود ندارد — از اپ اندروید ← «کارت‌ها و قالب‌های پیامک» یک کارت اضافه کنید.');

        // --- devices (اپ اندروید) ---
        if ($store !== null) {
            $active  = (int) DB::val("SELECT COUNT(*) FROM devices WHERE store_id = ? AND status = 'active'", [(int) $store['id']]);
            $pending = (int) DB::val("SELECT COUNT(*) FROM devices WHERE store_id = ? AND status = 'pending'", [(int) $store['id']]);
            $status  = $active > 0 ? 'ok' : 'warning';
            $msg     = $active > 0 ? "{$active} دستگاه متصل است." : 'هیچ دستگاه اندرویدی متصل نیست.';
            if ($pending > 0) {
                $msg .= " {$pending} دستگاه در انتظار تأیید شماست.";
            }
            $lastSeen = DB::val("SELECT MAX(last_seen_at) FROM devices WHERE store_id = ?", [(int) $store['id']]);
            if ($lastSeen !== null) {
                $msg .= ' آخرین اتصال: ' . Helpers::ago((string) $lastSeen) . '.';
            }
            // جزئیات هر دستگاه — مدل/نسخه اپ/آخرین ضربان
            $devs = DB::all("SELECT name, app_version, status, last_seen_at FROM devices WHERE store_id = ? ORDER BY last_seen_at DESC LIMIT 4", [(int) $store['id']]);
            foreach ($devs as $d) {
                $msg .= "\n• «" . trim((string) $d['name']) . '» (' . ($d['app_version'] !== '' ? 'اپ ' . $d['app_version'] : 'نسخه نامشخص') . ', ' . ($d['status'] === 'active' ? 'فعال' : 'در انتظار تأیید') . ') — ضربان: '
                    . ($d['last_seen_at'] !== null ? Helpers::ago((string) $d['last_seen_at']) : 'هرگز') . '.';
            }
            $add('devices', 'اتصال اپ اندروید (ضربان)', $status, $msg);
        }

        // --- SMS pipeline (فرستنده‌ها + پارسر هر کارت) ---
        if ($store !== null) {
            $sid  = (int) $store['id'];
            $cards = DB::all('SELECT id, label, bank_name FROM cards WHERE store_id = ? AND is_active = 1 ORDER BY id', [$sid]);
            $allOk = true;
            $msg   = [];
            foreach ($cards as $c) {
                $sc = (int) DB::val('SELECT COUNT(*) FROM card_senders WHERE card_id = ?', [(int) $c['id']]);
                $rc = (int) DB::val('SELECT COUNT(*) FROM card_parser_rules WHERE card_id = ? AND enabled = 1', [(int) $c['id']]);
                $label = trim((string) ($c['label'] !== '' ? $c['label'] : $c['bank_name']));
                if ($sc === 0 || $rc === 0) {
                    $allOk = false;
                }
                $msg[] = "• «{$label}»: {$sc} فرستنده مجاز، {$rc} قالب پارسر" . (($sc === 0 || $rc === 0) ? ' — ناقص!' : '');
            }
            if ($cards === []) {
                $add('sms_pipeline', 'خط لوله پیامک (فرستنده + پارسر)', 'warning', 'هیچ کارت فعالی تنظیم نشده است.');
            } else {
                $cfgVer = CardSms::version();
                $add('sms_pipeline', 'خط لوله پیامک (فرستنده + پارسر)', $allOk ? 'ok' : 'warning',
                    ($allOk ? 'همه کارت‌های فعال کامل پیکربندی شده‌اند.' : 'کارت‌های ناقص: تا وقتی فرستنده/قالب ثبت نشود، پیامک‌های آن کارت پردازش نمی‌شود.')
                    . "\n" . implode("\n", $msg) . "\nنسخه پیکربندی پیامک: {$cfgVer}.");
            }
        }

        // --- آخرین تست اتصال افزونه ووکامرس از سمت فروشگاه ---
        // (تصمیم بر اساس آخرین رویدادِ ثبت‌شده — مقایسه id نه ثانیه، تا تهم‌ثانیه‌ها قاطی نشوند)
        {
            $last = DB::one("SELECT action, created_at FROM audit_logs WHERE (action = 'api.test_connection') OR (action = 'api.auth_failed' AND detail LIKE '%test-connection%') ORDER BY id DESC LIMIT 1");
            if ($last === null) {
                $add('woo_test', 'تست اتصال افزونه (ووکامرس)', 'warning',
                    'هنوز از سمت فروشگاه ووکامرس تستی ثبت نشده.\nدر ووکامرس ← تنظیمات ← پرداخت‌ها ← پیکو پی دکمه «تست اتصال به پیکو پی» را بزنید؛ نتیجه همین‌جا ثبت می‌شود.');
            } elseif ($last['action'] === 'api.test_connection') {
                $add('woo_test', 'تست اتصال افزونه (ووکامرس)', 'ok',
                    'آخرین تست اتصال افزونه: ' . Helpers::ago((string) $last['created_at']) . ' — موفق. (دکمه «تست اتصال» در تنظیمات درگاه ووکامرس)');
            } else {
                $add('woo_test', 'تست اتصال افزونه (ووکامرس)', 'error',
                    'آخرین تلاش افزونه (' . Helpers::ago((string) $last['created_at']) . ') ناموفق بوده — اعتبارنامه‌ها را در تنظیمات درگاه ووکامرس بررسی کنید و دوباره تست بزنید.');
            }
        }

        // --- last transaction ---
        if ($store !== null) {
            $last = Transaction::lastForStore((int) $store['id']);
            $add('last_txn', 'آخرین تراکنش دریافتی', $last === null ? 'warning' : (strtotime((string) $last['created_at']) > time() - 3600 ? 'ok' : 'warning'),
                $last === null ? 'هنوز تراکنشی از دستگاه‌ها دریافت نشده است.' : 'دریافت شده در ' . Helpers::ago((string) $last['created_at']) . ' — مبلغ ' . Helpers::money((int) $last['amount_rial']) . '.');
        }

        // --- WooCommerce ---
        $wooUrl   = Setting::get('woo_site_url');
        $wooSecret = Setting::get('woo_webhook_secret');
        $apiKey   = ApiKey::maskedActive((int) ($store['id'] ?? 0));
        if ($wooUrl === '' || $wooSecret === '' || $apiKey === null) {
            $add('woo', 'اتصال ووکامرس', 'warning', 'تنظیمات ووکامرس کامل نیست (آدرس فروشگاه، کلید API یا راز Webhook). از تنظیمات → ووکامرس تکمیل کنید.');
        } else {
            $apiUsed = ApiKey::lastUsed((int) $store['id']);
            $usedMsg = $apiUsed === null ? 'هنوز استفاده نشده' : Helpers::ago((string) $apiUsed);
            $add('woo_config', 'پیکربندی ووکامرس', 'ok', 'آدرس: ' . $wooUrl . ' — کلید فعال: ' . $apiKey . ' — آخرین استفاده: ' . $usedMsg);
            if ($deep) {
                $ping = WooNotifier::ping();
                $add('woo_ping', 'آزمون اتصال ووکامرس', $ping['ok'] ? 'ok' : 'error',
                    $ping['ok'] ? 'افزونه پیکو پی در فروشگاه پاسخ داد و امضا را تأیید کرد.' : 'پاسخ معتبر از افزونه دریافت نشد. آدرس و «راز Webhook» دو طرف باید یکسان باشد و افزونه فعال باشد.');
            }
            $lastFail = DB::val("SELECT COUNT(*) FROM webhook_events WHERE store_id = ? AND status = 'failed'", [(int) $store['id']]);
            $add('woo_webhooks', 'تحویل Webhook', (int) $lastFail > 0 ? 'warning' : 'ok',
                (int) $lastFail > 0 ? ((int) $lastFail) . ' رویداد ناموفق است — از صفحه پرداخت‌ها دوباره ارسال کنید.' : 'همه رویدادها با موفقیت تحویل شده‌اند (یا رویدادی نبوده).');
        }
        } catch (Throwable $e) {
            // بررسی‌ها به‌خاطر schema ناقص/خطای DB متوقف شد — صفحه زنده می‌ماند و راهنمایی می‌دهد
            $add('checks_failed', 'بررسی‌های سیستم با خطا متوقف شد', 'error',
                'علت: ' . mb_substr($e->getMessage(), 0, 200)
                . "\nبه‌احتمال زیاد پایگاه داده ناقص است — همین حالا دکمه «🛠 تعمیر پایگاه داده» را بزنید و سپس «اجرای بررسی کامل» را تکرار کنید.");
        }

        return $out;
    }

    public static function summary(bool $deep = false): array
    {
        $checks = self::run($deep);
        $counts = ['ok' => 0, 'warning' => 0, 'error' => 0];
        foreach ($checks as $c) {
            $counts[$c['status']]++;
        }
        return ['checks' => $checks, 'counts' => $counts];
    }
}
