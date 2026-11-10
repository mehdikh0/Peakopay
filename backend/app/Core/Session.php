<?php
/**
 * Peakopay — Admin sessions + CSRF protection.
 */
declare(strict_types=1);

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('HPSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => Http::base() ?: '/',
            'secure'   => Http::isHttps() || (bool) Config::get('session_secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Enforce idle timeout (minutes) — call at the top of every admin page. */
    public static function ensureFresh(int $timeoutMinutes): void
    {
        self::start();
        $now  = time();
        $last = (int) ($_SESSION['_last'] ?? $now);
        if ($timeoutMinutes > 0 && $now - $last > $timeoutMinutes * 60) {
            self::destroy();
            self::flash('error', 'نشست شما منقضی شد. لطفاً دوباره وارد شوید.');
            Http::redirect(Config::url('admin/login'));
        }
        $_SESSION['_last'] = $now;
        if (!isset($_SESSION['_rot']) || $now - (int) $_SESSION['_rot'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_rot'] = $now;
        }
    }

    public static function login(int $userId): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['uid']  = $userId;
        $_SESSION['_last'] = time();
        $_SESSION['_rot']  = time();
        // دور ۲۹ — برای ابطال نشست‌ها پس از ریست ادمینی/تغییر امنیتی 2FA
        $_SESSION['login_at'] = time();
    }

    public static function logout(): void
    {
        self::start();
        self::destroy();
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
    }

    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    /**
     * فروشگاه فعالِ پنل (دور ۲۳ — چندفروشگاهی). فقط شناسه ذخیره می‌شود؛
     * اعتبارسنجی عضویت هر بار در Store::current() انجام می‌شود (مرز امنیتی).
     */
    public static function activeStoreId(): ?int
    {
        self::start();
        return isset($_SESSION['active_store']) ? (int) $_SESSION['active_store'] : null;
    }

    public static function setActiveStore(int $storeId): void
    {
        self::start();
        $_SESSION['active_store'] = $storeId;
    }

    public static function isLoggedIn(): bool
    {
        return self::userId() !== null;
    }

    /** Flash messages: Session::flash('success', 'msg') to set, ->flash('success') to read+clear. */
    public static function flash(string $key, ?string $message = null): ?string
    {
        self::start();
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;
            return null;
        }
        $msg = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $msg;
    }

    /**
     * Pending reveal: a one-time secret (new API key / webhook secret) that
     * must SURVIVE page reloads, prefetches and page caches until the admin
     * explicitly dismisses it. Unlike flash() it is never auto-cleared.
     */
    public static function pending(string $key, ?string $value = null): ?string
    {
        self::start();
        if ($value !== null) {
            $_SESSION['_pending'][$key] = $value;
            return null;
        }
        return isset($_SESSION['_pending'][$key]) ? (string) $_SESSION['_pending'][$key] : null;
    }

    public static function clearPending(string ...$keys): void
    {
        self::start();
        foreach ($keys as $k) {
            unset($_SESSION['_pending'][$k]);
        }
    }

    // ------------------------------------------------------------------ CSRF

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(20));
        }
        return (string) $_SESSION['_csrf'];
    }

    /** Verify the CSRF token on every state-changing admin request. */
    public static function checkCsrf(): void
    {
        $token = (string) ($_POST['_csrf'] ?? Http::header('X-CSRF-Token') ?? '');
        if ($token === '' || !self::equalCsrf($token)) {
            Audit::log('csrf.blocked', 'path=' . Http::path());
            if (Http::wantsJson()) {
                Http::apiError('csrf', 'درخواست نامعتبر است (CSRF). صفحه را رفرش کنید و دوباره تلاش کنید.', 403);
            }
            Session::flash('error', 'درخواست نامعتبر بود. لطفاً دوباره تلاش کنید.');
            $fallback = str_starts_with(Http::path(), '/install')
                ? Config::url('install')
                : Config::url('admin');
            Http::redirect($_SERVER['HTTP_REFERER'] ?? $fallback);
        }
    }

    private static function equalCsrf(string $given): bool
    {
        self::start();
        $known = (string) ($_SESSION['_csrf'] ?? '');
        return $known !== '' && hash_equals($known, $given);
    }
}
