<?php
/**
 * Peakopay — Admin: diagnostics, logs, test tools, about, password change.
 */
declare(strict_types=1);

final class SystemController
{
    // ------------------------------------------------------------ diagnostics

    public static function diagnostics(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $run   = Session::flash('diag');
        $data  = $run !== null ? json_decode($run, true) : null;
        if ($data === null) {
            $data = Diagnostics::summary(false);
        }
        View::page('admin/diagnostics', [
            'title'      => 'وضعیت اتصال‌ها و عیب‌یابی سیستم',
            'user'       => $user,
            'store'      => $store,
            'data'       => $data,
            'activeNav'  => 'diagnostics',
        ]);
    }

    public static function runDiagnostics(): void
    {
        AdminGuard::requirePost();
        $summary = Diagnostics::summary(deep: true);
        Session::flash('diag', json_encode($summary, JSON_UNESCAPED_UNICODE));
        Session::flash('success', 'بررسی کامل انجام شد.');
        Http::redirect(Config::url('admin/diagnostics'));
    }

    /**
     * تعمیر پایگاه داده (دور ۱۸): اجرای صریح مهاجرت‌ها + گزارش دقیق نتیجه.
     * اگر صفحه‌ای (مثل کارت‌ها) خطای ۵۰۰ می‌دهد، این دکمه یا مشکل را همان‌جا رفع
     * می‌کند یا علت دقیق را به مدیر نشان می‌دهد (به‌جای صفحه خطای عمومی).
     */
    public static function repairDb(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'db_repair', 'تعمیر پایگاه داده');

        // ارزیابی مجدد اجباری (نه اتکا به marker)
        @unlink(HP_BASE . '/storage/cache/mig_state.txt');

        $applied = [];
        $error = null;
        try {
            $applied = Installer::migrate(force: true); // تعمیر واقعی: اجرای مجدد همه فایل‌ها (روادار)
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $missing = [];
        try {
            $status = Installer::schemaStatus();
            $missing = $status['missing'];
        } catch (Throwable $e) {
            $error = $error ?? $e->getMessage();
        }

        if ($error !== null) {
            Session::flash('error', 'تعمیر پایگاه داده با خطا متوقف شد — علت دقیق: ' . mb_substr($error, 0, 400)
                . ' — همین متن را به پشتیبانی گزارش کنید (کد پیگیری: ' . HP_REQUEST_ID . ').');
            Audit::log('db_repair', 'fail: ' . mb_substr($error, 0, 200));
        } else {
            $parts = [];
            if ($applied !== []) {
                $parts[] = 'مهاجرت‌های اعمال‌شده: ' . implode('، ', $applied);
            }
            if ($missing === []) {
                $parts[] = 'همه جدول‌ها و ستون‌های موردنیاز موجودند ✓';
                Session::flash('success', 'پایگاه داده سالم است — ' . implode(' — ', $parts));
            } else {
                Session::flash('error', 'پس از تعمیر، این موارد هنوز وجود ندارند: ' . implode('، ', $missing)
                    . ' — احتمالاً فایل مهاجرت مربوطه روی هاست نیست؛ بسته سرور را کامل باز کنید و دوباره بزنید (کد پیگیری: ' . HP_REQUEST_ID . ').');
            }
            Audit::log('db_repair', $applied !== [] ? ('applied: ' . implode(',', $applied)) : 'no-op');
        }
        Http::redirect(Config::url('admin/diagnostics'));
    }

    // ------------------------------------------------------------------ logs

    public static function logs(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $q     = trim((string) Http::get('q', ''));
        $rows  = Audit::recent(150, $q);
        $appLog = self::tailAppLog(120);
        View::page('admin/logs', [
            'title'     => 'گزارش‌ها',
            'user'      => $user,
            'store'     => $store,
            'rows'      => $rows,
            'appLog'    => $appLog,
            'q'         => $q,
            'activeNav' => 'logs',
        ]);
    }

    private static function tailAppLog(int $lines): array
    {
        $file = HP_BASE . '/storage/logs/app-' . gmdate('Ymd') . '.log';
        if (!is_file($file)) {
            return [];
        }
        $all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_slice($all, -$lines);
    }

    // ------------------------------------------------------------- test tools

    public static function testTools(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $testPayments = $sid > 0 ? DB::all(
            "SELECT p.*, c.bank_name FROM payment_sessions p LEFT JOIN cards c ON c.id = p.card_id
             WHERE p.store_id = ? AND p.is_test = 1 ORDER BY p.id DESC LIMIT 10", [$sid]
        ) : [];
        View::page('admin/testtools', [
            'title'        => 'ابزارهای تست',
            'user'         => $user,
            'store'        => $store,
            'testMode'     => Setting::bool('test_mode', false),
            'testPayments' => $testPayments,
            'sampleSms'    => "بانک ملت\nواریز به کارت\nمبلغ: 1,500,000 ريال\nکارت مقصد: 6219-86••-••••-1234\nتاریخ: " . gmdate('Y/m/d') . " ساعت: " . gmdate('H:i') . "\nکد رهگیری: 123456789",
            'activeNav'    => 'testtools',
        ]);
    }

    public static function testAction(): void
    {
        $user  = AdminGuard::requirePost();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $do    = (string) Http::post('do', '');

        if (!Setting::bool('test_mode', false) && $do !== 'enable_test_mode') {
            Session::flash('error', 'ابتدا «حالت تست» را از تنظیمات → پیشرفته فعال کنید.');
            Http::redirect(Config::url('admin/testtools'));
        }

        switch ($do) {
            case 'enable_test_mode':
                Setting::set('test_mode', '1');
                Session::flash('success', 'حالت تست فعال شد. تراکنش‌های تست با برچسب «تست» ایجاد می‌شوند و هرگز با پرداخت واقعی اشتباه نمی‌شوند.');
                break;
            case 'test_payment':
                try {
                    $card = Card::activeDefault($sid);
                    if ($card === null) {
                        throw new DomainException('ابتدا یک کارت مقصد اضافه کنید.');
                    }
                    $amountToman = max(1, (int) Http::post('amount_toman', 1000));
                    $orderNo     = 'TEST-' . strtoupper(Crypto::randomHex(3));
                    $payment = Payment::create($sid, $card, $orderNo, '', $amountToman * 10, 'IRT', $amountToman, [
                        'description' => 'پرداخت تستی پیکو پی',
                        'is_test'     => true,
                    ]);
                    Session::flash('test_payment', json_encode([
                        'order' => $orderNo,
                        'url'   => Config::url('pay/' . $payment['token']),
                        'token' => $payment['token'],
                        'amount_rial' => (int) $payment['amount_rial'],
                    ], JSON_UNESCAPED_SLASHES));
                    Session::flash('success', 'پرداخت تستی ساخته شد. صفحه پرداخت را باز کنید و سپس پیامک بانکی را شبیه‌سازی کنید.');
                } catch (Throwable $e) {
                    Session::flash('error', 'ساخت پرداخت تستی ناموفق بود: ' . $e->getMessage());
                }
                break;
            case 'simulate_sms':
                try {
                    $text   = (string) Http::post('sms_text', '');
                    $sender = (string) Http::post('sender', '');
                    $parsed = SmsParser::parse($text, $sender);
                    $device = self::testDevice($sid);
                    $result = Matcher::process([
                        'bank'        => $parsed['bank'],
                        'sender'      => $sender,
                        'amount_rial' => $parsed['amount_rial'],
                        'occurred_at' => $parsed['occurred_at'],
                        'dest_last4'  => $parsed['dest_last4'],
                        'reference'   => $parsed['reference'],
                        'raw_text'    => $text,
                        'raw_hash'    => null,
                    ], $device, true);
                    Session::flash('test_result', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $msg = [
                        'matched'         => 'تراکنش با موفقیت به پرداخت تستی متصل شد و سفارش «تستی» ووکامرس کامل شد (در صورت اتصال).',
                        'matched_pending' => 'تراکنش تطبیق یافت اما در انتظار تأیید دستی شماست (تنظیمات → تطبیق).',
                        'unmatched'       => 'تراکنش دریافت شد اما هیچ پرداخت بازی برای آن پیدا نشد — در فهرست «بدون تطبیق» ببینید.',
                        'duplicate'       => 'این پیامک قبلاً ارسال شده بود؛ تراکنش تکراری تشخیص داده شد.',
                    ][$result['result']] ?? $result['result'];
                    Session::flash('success', $msg);
                } catch (Throwable $e) {
                    Session::flash('error', 'شبیه‌سازی ناموفق: ' . $e->getMessage());
                }
                break;
            default:
                Session::flash('error', 'عملیات نامعتبر.');
        }
        Http::redirect(Config::url('admin/testtools'));
    }

    /** A dedicated pseudo-device so web simulations never require a real phone. */
    private static function testDevice(int $storeId): array
    {
        $device = DB::one("SELECT * FROM devices WHERE store_id = ? AND name = 'شبیه‌ساز تست' LIMIT 1", [$storeId]);
        if ($device === null) {
            $id = DB::insert('devices', [
                'store_id'        => $storeId,
                'name'            => 'شبیه‌ساز تست',
                'model'           => 'web-admin',
                'android_version' => '-',
                'app_version'     => HP_VERSION,
                'status'          => 'active',
                'secret_enc'      => Crypto::encrypt(Crypto::newDeviceSecret()),
                'paired_at'       => DB::now(),
                'approved_at'     => DB::now(),
            ]);
            $device = Device::find($id);
        }
        return $device ?? Device::find(1);
    }

    // ----------------------------------------------------------------- about

    public static function about(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        View::page('admin/about', [
            'title'     => 'درباره پیکو پی',
            'user'      => $user,
            'store'     => $store,
            'activeNav' => 'about',
        ]);
    }

    // --------------------------------------------------------------- password

    public static function password(): void
    {
        $user  = AdminGuard::requirePost();
        $current = (string) Http::post('current_password', '');
        $new     = (string) Http::post('new_password', '');
        $repeat  = (string) Http::post('new_password2', '');
        $full    = User::findByEmail((string) $user['email']);
        if ($full === null || !User::verifyPassword($full, $current)) {
            Session::flash('error', 'رمز عبور فعلی اشتباه است.');
        } elseif (strlen($new) < 8) {
            Session::flash('error', 'رمز جدید باید حداقل ۸ کاراکتر باشد.');
        } elseif ($new !== $repeat) {
            Session::flash('error', 'تکرار رمز جدید یکسان نیست.');
        } else {
            User::updatePassword((int) $user['id'], $new);
            Session::flash('success', 'رمز عبور تغییر کرد.');
        }
        Http::redirect(Config::url('admin/settings') . '?section=security');
    }
}
