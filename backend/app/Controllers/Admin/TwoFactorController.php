<?php
/**
 * Peakopay — ورود دومرحله‌ای (دور ۲۹): صفحهٔ کد در ورود + پیکربندی در پنل.
 *
 * جریان ورود: رمز عبور ✓ (AuthController) → چالش → صفحهٔ کد → تأیید محلی
 * TOTP یا کد بازیابی (با رمز عبور) → تکمیل نشست. همه‌چیز محلی — بدون هیچ
 * سرویس بیرونی؛ حتی بدون اینترنت بین‌المللی کار می‌کند.
 */
declare(strict_types=1);

final class TwoFactorController
{
    // ================================================================ ورود

    /** GET/POST /admin/login/2fa — صفحهٔ تأیید کد (بعد از رمز عبور). */
    public static function verify(): void
    {
        if (!Installer::isInstalled()) {
            Http::redirect(Config::url('install'));
        }
        Session::start();
        if (Session::isLoggedIn()) {
            Http::redirect(Config::url('admin'));
        }
        $token = (string) ($_SESSION['2fa_token'] ?? '');
        if ($token === '' || TwoFactor::challenge($token) === null) {
            unset($_SESSION['2fa_token']);
            Session::flash('error', 'مهلت تأیید ورود دومرحله‌ای گذشته است. لطفاً دوباره وارد شوید.');
            Http::redirect(Config::url('admin/login'));
        }

        if (Http::isPost()) {
            Session::checkCsrf();
            $method = (string) Http::post('method', 'totp');
            $uid    = (int) (TwoFactor::challenge($token)['user_id'] ?? 0);
            $ok     = false;

            if (TwoFactor::rateLimited($uid)) {
                Audit::log('2fa.rate_limited', 'user=' . $uid);
                unset($_SESSION['2fa_token']);
                Session::flash('error', 'تلاش‌های ناموفق زیاد است؛ چند دقیقه دیگر دوباره وارد شوید.');
                Http::redirect(Config::url('admin/login'));
            }

            if ($method === 'recovery') {
                // کد بازیابی + رمز عبور (هر دو لازم — تأیید هویت کامل)
                $user = User::findForAuth($uid);
                $pass = (string) Http::post('password', '');
                $code = (string) Http::post('recovery_code', '');
                if ($user !== null && User::verifyPassword($user, $pass)) {
                    $ok = TwoFactor::consumeRecoveryCode($uid, $code);
                } else {
                    usleep(300000);
                }
            } else {
                $ok = TwoFactor::verifyCode($uid, (string) Http::post('code', ''));
            }

            if ($ok) {
                TwoFactor::clearRate($uid);
                unset($_SESSION['2fa_token']);
                Session::login($uid); // بازتولید شناسهٔ نشست (session fixation)
                User::touchLogin($uid);
                Audit::log($method === 'recovery' ? 'login.success_recovery' : 'login.success_2fa', 'user=' . $uid);
                Http::redirect(Config::url('admin'));
            }

            TwoFactor::failChallenge($token);
            Audit::log('2fa.login_failed', 'user=' . $uid . ' method=' . $method);
            $msg = $method === 'recovery'
                ? 'کد بازیابی یا رمز عبور نادرست است.'
                : 'کد وارد شده درست نیست یا منقضی شده است. کد تازه‌ای از برنامهٔ احرازگر بگیرید.';
            Session::flash('error', $msg);
            // چالش ممکن است با تلاش‌های زیاد مرده باشد
            if (TwoFactor::challenge($token) === null) {
                unset($_SESSION['2fa_token']);
                Session::flash('error', 'تلاش‌های ناموفق زیاد بود. لطفاً دوباره وارد شوید.');
                Http::redirect(Config::url('admin/login'));
            }
            Http::redirect(Config::url('admin/login/2fa'));
        }

        View::renderPlain('admin/twofactor_verify', [
            'title'  => 'ورود دومرحله‌ای',
            'method' => ((string) Http::get('m', 'totp')) === 'recovery' ? 'recovery' : 'totp',
        ]);
    }

    // ================================================================ پنل

    /** GET /admin/2fa — وضعیت/پیکربندی 2FA کاربرِ واردشده. */
    public static function index(): void
    {
        $user = AdminGuard::require();
        $uid  = (int) $user['id'];

        $enrolled  = TwoFactor::isEnabled($uid);
        $pending   = $enrolled ? null : TwoFactor::pendingSecret($uid);
        // اعلان «2FA شما توسط مدیر بازنشانی شد» — مستقل از وضعیت ثبت‌نام،
        // تا کاربری که ریست شده و هنوز دوباره فعال نکرده هم مطلع شود.
        $resetNote = TwoFactor::unseenReset($uid);
        if ($resetNote !== null) {
            TwoFactor::markResetsSeen($uid);
        }

        View::page('admin/twofactor_setup', [
            'title'             => 'امنیت دو مرحله‌ای',
            'activeNav'         => 'settings',
            'user'              => $user,
            'store'             => Store::current(),
            'enrolled'          => $enrolled,
            'pendingSecret'     => $pending,
            'pendingUri'        => $pending !== null ? Totp::otpauthUri($pending, (string) $user['email']) : '',
            'pendingQr'         => $pending !== null ? QrCode::svg(Totp::otpauthUri($pending, (string) $user['email']), 3.2) : '',
            'recoveryRemaining' => $enrolled ? TwoFactor::recoveryRemaining($uid) : 0,
            'newCodes'          => Session::pending('twofa_new_codes'),
            'resetNote'         => $resetNote,
            'enforced'          => TwoFactor::enforcedFor($user),
            'enabledAt'         => $enrolled ? Helpers::ago(TwoFactor::row($uid)['enabled_at'] ?? null) : '',
        ]);
    }

    /** POST /admin/2fa/enroll — شروع پیکربندی؛ اگر فعال است: تعویض احرازگر (رمز + کد). */
    public static function enroll(): void
    {
        $user = AdminGuard::requirePost();
        $uid  = (int) $user['id'];

        if (TwoFactor::isEnabled($uid)) {
            // تعویض احرازگر — تأیید امن: رمز عبور + کد فعلی
            $user = User::findForAuth($uid);
            $pass = (string) Http::post('password', '');
            $code = (string) Http::post('code', '');
            if ($user === null || !User::verifyPassword($user, $pass) || !TwoFactor::verifyCode($uid, $code)) {
                Audit::log('2fa.replace_denied', 'user=' . $uid);
                Session::flash('error', 'رمز عبور یا کد فعلی نادرست است — تعویض احرازگر انجام نشد.');
                Http::redirect(Config::url('admin/2fa'));
            }
            Audit::log('2fa.replace_started', 'user=' . $uid);
        }
        TwoFactor::enroll($uid);
        Session::flash('success', 'راز تازه ساخته شد. اکنون با برنامهٔ احرازگر اسکن کنید و اولین کد را تأیید کنید.');
        Http::redirect(Config::url('admin/2fa'));
    }

    /** POST /admin/2fa/activate — تأیید اولین کد + فعال‌سازی + ساخت کدهای بازیابی. */
    public static function activate(): void
    {
        $user = AdminGuard::requirePost();
        $uid  = (int) $user['id'];
        $code = (string) Http::post('code', '');

        if (TwoFactor::rateLimited($uid)) {
            Session::flash('error', 'تلاش‌های ناموفق زیاد است؛ چند دقیقه دیگر امتحان کنید.');
            Http::redirect(Config::url('admin/2fa'));
        }
        $codes = TwoFactor::activate($uid, $code);
        if ($codes === false) {
            // شمارندهٔ نرخ پاک نمی‌شود — تلاش ناموفق هم ضد brute-force شمرده می‌شود
            Session::flash('error', 'کد وارد شده درست نیست. کد تازه‌ای از برنامه بگیرید و دوباره امتحان کنید.');
            Http::redirect(Config::url('admin/2fa'));
        }
        TwoFactor::clearRate($uid);
        Session::pending('twofa_new_codes', implode("\n", $codes));
        Session::flash('success', 'ورود دومرحله‌ای فعال شد ✅ — کدهای بازیابی را همین حالا ذخیره کنید؛ فقط یک بار نشان داده می‌شوند.');
        Http::redirect(Config::url('admin/2fa'));
    }

    /** POST /admin/2fa/disable — خاموش کردن (رمز + کد فعلی). */
    public static function disable(): void
    {
        $user = AdminGuard::requirePost();
        $uid  = (int) $user['id'];
        $user = User::findForAuth($uid);
        $pass = (string) Http::post('password', '');
        $code = (string) Http::post('code', '');

        if ($user === null || !User::verifyPassword($user, $pass) || !TwoFactor::verifyCode($uid, $code)) {
            Audit::log('2fa.disable_denied', 'user=' . $uid);
            Session::flash('error', 'رمز عبور یا کد فعلی نادرست است — 2FA خاموش نشد.');
            Http::redirect(Config::url('admin/2fa'));
        }
        TwoFactor::disable($uid);
        Session::clearPending('twofa_new_codes');
        Session::flash('success', 'ورود دومرحله‌ای خاموش شد. توصیه می‌شود برای امنیت حساب دوباره فعالش کنید.');
        Http::redirect(Config::url('admin/2fa'));
    }

    /** POST /admin/2fa/recovery — بازتولید کدهای بازیابی (رمز لازم؛ قبلی‌ها باطل). */
    public static function recovery(): void
    {
        $user = AdminGuard::requirePost();
        $uid  = (int) $user['id'];
        if (!TwoFactor::isEnabled($uid)) {
            Http::redirect(Config::url('admin/2fa'));
        }
        $user = User::findForAuth($uid);
        if ($user === null || !User::verifyPassword($user, (string) Http::post('password', ''))) {
            Session::flash('error', 'رمز عبور نادرست است — کدهای بازیابی بازتولید نشدند.');
            Http::redirect(Config::url('admin/2fa'));
        }
        $codes = TwoFactor::generateRecoveryCodes($uid);
        Session::pending('twofa_new_codes', implode("\n", $codes));
        Session::flash('success', 'کدهای بازیابی تازه ساخته شد — کدهای قبلی همه باطل شدند.');
        Http::redirect(Config::url('admin/2fa'));
    }

    /** POST /admin/2fa/hide — «کدها را ذخیره کردم» — پاک‌کردن نمایش یک‌بارهٔ کدها. */
    public static function hide(): void
    {
        AdminGuard::requirePost();
        Session::clearPending('twofa_new_codes');
        Session::flash('success', 'نمایش کدهای بازیابی بسته شد. اگر ذخیره نکرده‌اید، از دکمهٔ «بازتولید کدهای بازیابی» کدهای تازه بسازید.');
        Http::redirect(Config::url('admin/2fa'));
    }

    /** POST /admin/2fa/ack-reset — تأیید اعلانِ «2FA توسط مدیر بازنشانی شد». */
    public static function ackReset(): void
    {
        AdminGuard::requirePost();
        $user = AdminGuard::require();
        TwoFactor::markResetsSeen((int) $user['id']);
        Session::flash('success', 'اعلان بازنشانی ورود دومرحله‌ای خوانده شد.');
        Http::redirect(Config::url('admin'));
    }

    /** GET /admin/2fa/codes.txt — دانلود کدهای بازیابیِ تازه‌تولیدشده (فقط از نشست). */
    public static function download(): void
    {
        $user = AdminGuard::require();
        $codes = Session::pending('twofa_new_codes');
        if ($codes === null || trim($codes) === '') {
            Session::flash('error', 'کدی برای دانلود نیست — ابتدا کدهای بازیابی را بازتولید کنید.');
            Http::redirect(Config::url('admin/2fa'));
        }
        $body = "Peakopay — کدهای بازیابی اضطراری ورود دومرحله‌ای\n"
            . "کاربر: " . $user['email'] . "\n"
            . "تاریخ تولید: " . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "هر کد فقط یک بار قابل استفاده است. این فایل را امن نگه دارید یا پس از ذخیره حذف کنید.\n"
            . str_repeat('-', 48) . "\n\n" . $codes . "\n";
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="peakopay-recovery-codes.txt"');
        header('Cache-Control: no-store');
        echo $body;
    }
}
