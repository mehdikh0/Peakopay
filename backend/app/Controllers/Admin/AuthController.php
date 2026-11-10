<?php
/**
 * Peakopay — Admin authentication (login/logout) with brute-force protection.
 */
declare(strict_types=1);

final class AuthController
{
    public static function login(): void
    {
        if (!Installer::isInstalled()) {
            Http::redirect(Config::url('install'));
        }
        Session::start();
        if (Session::isLoggedIn()) {
            Http::redirect(Config::url('admin'));
        }
        if (Http::isPost()) {
            Session::checkCsrf();
            $ip  = Http::ip();
            $max = max(Setting::int('login_max_attempts', 5), 3);
            if (!RateLimiter::allow('login:' . $ip, $max, 900)) {
                Session::flash('error', 'تلاش‌های ناموفق زیاد است؛ ۱۵ دقیقه دیگر دوباره امتحان کنید.');
                Http::redirect(Config::url('admin/login'));
            }
            $email    = (string) Http::post('email', '');
            $password = (string) Http::post('password', '');
            $user     = $email !== '' ? User::findByEmail($email) : null;
            if ($user === null
                || (int) ($user['is_active'] ?? 1) !== 1
                || !User::verifyPassword($user, $password)) {
                Audit::log('login.failed', 'email=' . mb_substr($email, 0, 60));
                usleep(300000); // constant-ish delay
                Session::flash('error', 'ایمیل یا رمز عبور اشتباه است.');
                Http::redirect(Config::url('admin/login'));
            }
            RateLimiter::clear('login:' . $ip);
            // دور ۲۹ — ورود دومرحله‌ای: بعد از تأیید رمز، اگر 2FA فعال باشد
            // کاربر هنوز وارد نشده؛ چالش می‌سازیم و به صفحهٔ کد می‌فرستیم.
            // (وضعیت 2FA فقط بعد از موفقیت رمز عبور آشکار می‌شود.)
            if (TwoFactor::isEnabled((int) $user['id'])) {
                session_regenerate_id(true);
                $_SESSION['2fa_token'] = TwoFactor::createChallenge((int) $user['id']);
                Audit::log('login.2fa_challenge', 'user=' . $user['id']);
                Http::redirect(Config::url('admin/login/2fa'));
            }
            Session::login((int) $user['id']);
            User::touchLogin((int) $user['id']);
            Audit::log('login.success', 'user=' . $user['id']);
            Http::redirect(Config::url('admin'));
        }
        View::page('admin/login', ['title' => 'ورود به پنل مدیریت']);
    }

    public static function logout(): void
    {
        Session::start();
        Audit::log('logout', 'user=' . (Session::userId() ?? '-'));
        Session::logout();
        Http::redirect(Config::url('admin/login'));
    }
}
