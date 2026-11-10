<?php
/**
 * Peakopay — Admin guard: every admin page/controller calls AdminGuard::require().
 */
declare(strict_types=1);

final class AdminGuard
{
    /** Admin pages must never be served from a browser/proxy/LiteSpeed page cache. */
    private static function noStoreHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
    }

    /** @return array user row */
    public static function require(): array
    {
        self::noStoreHeaders();
        if (!Installer::isInstalled()) {
            Http::redirect(Config::url('install'));
        }
        Session::start();
        if (!Session::isLoggedIn()) {
            Http::redirect(Config::url('admin/login'));
        }
        Session::ensureFresh(max(Setting::int('session_timeout_minutes', 240), 10));
        $user = User::findOrNull(Session::userId());
        if ($user === null) {
            Session::destroy();
            Http::redirect(Config::url('admin/login'));
        }
        self::checkNotRevoked((int) $user['id']);
        self::enforcePolicy($user);
        return $user;
    }

    /**
     * دور ۲۹ — نشست‌های ابطل‌شده (پس از ریست ادمینی/تغییر امنیتی 2FA) بلافاصله
     * می‌میرند، حتی اگر کوکی هنوز معتبر باشد.
     */
    private static function checkNotRevoked(int $userId): void
    {
        $revokedAt = TwoFactor::revokedAfter($userId);
        if ($revokedAt === null) {
            return;
        }
        $loginAt = (int) ($_SESSION['login_at'] ?? 0);
        if ($loginAt >= $revokedAt) {
            return; // نشست بعد از ابطال شروع شده — سالم
        }
        Session::destroy();
        Session::flash('error', 'نشست شما به دلایل امنیتی (بازنشانی ورود دومرحله‌ای) باطل شد. لطفاً دوباره وارد شوید.');
        Http::redirect(Config::url('admin/login'));
    }

    /**
     * دور ۲۹ — سیاست «2FA الزامی برای مدیران» (پیش‌فرض خاموش): مدیرِ بدون 2FA
     * فقط به صفحهٔ پیکربندی هدایت می‌شود؛ ورودش انجام شده و قفل نمی‌شود.
     */
    private static function enforcePolicy(array $user): void
    {
        if (!TwoFactor::enforcedFor($user) || TwoFactor::isEnabled((int) $user['id'])) {
            return;
        }
        $path = Http::path();
        $allowed = ['/admin/2fa', '/admin/logout', '/admin/2fa/codes.txt'];
        foreach ($allowed as $p) {
            if ($path === $p || str_starts_with($path, $p . '?') || str_starts_with($path, $p . '/')) {
                return;
            }
        }
        Session::flash('error', 'طبق سیاست حساب، ورود دومرحله‌ای برای مدیران الزامی است. ابتدا آن را پیکربندی کنید.');
        Http::redirect(Config::url('admin/2fa'));
    }

    /** State-changing admin requests: session + CSRF token. */
    public static function requirePost(): array
    {
        self::noStoreHeaders();
        $user = self::require();
        Session::checkCsrf();
        return $user;
    }

    /** For fetch() JSON actions: same checks, JSON errors. */
    public static function requireJson(): array
    {
        if (!Installer::isInstalled()) {
            Http::apiError('not_installed', 'نصب کامل نشده است.', 503);
        }
        Session::start();
        if (!Session::isLoggedIn()) {
            Http::apiError('unauthorized', 'ابتدا وارد شوید.', 401);
        }
        Session::ensureFresh(max(Setting::int('session_timeout_minutes', 240), 10));
        Session::checkCsrf();
        $user = User::findOrNull(Session::userId());
        if ($user === null) {
            Http::apiError('unauthorized', 'نشست نامعتبر است.', 401);
        }
        return $user;
    }

    // --------------------------------------------------------------- RBAC (دور ۲۳)

    /**
     * نقش‌ها و مجوزها — مالک همه‌چیز؛ مدیر عملیات فروشگاه؛ حسابدار فقط-
     * خواندنی؛ اپراتور عملیات محدود. دامنهٔ فروشگاه جدا از این ماتریس در
     * Store::current()/StoreMember اعمال می‌شود (کاربر فقط فروشگاه‌های عضوش را می‌بیند).
     */
    private const PERMS = [
        'owner'      => ['*'],
        'admin'      => [
            'dashboard_view', 'payments_view', 'transactions_view', 'logs_view', 'diagnostics_view',
            'settings_edit', 'cards_edit', 'devices_manage', 'testtools_use', 'db_repair', 'payments_confirm',
        ],
        'accountant' => [
            'dashboard_view', 'payments_view', 'transactions_view', 'logs_view',
        ],
        'operator'   => [
            'dashboard_view', 'payments_view', 'transactions_view', 'payments_confirm',
        ],
    ];

    public static function can(array $user, string $perm): bool
    {
        $role = Account::safeRole((string) ($user['role'] ?? ''));
        $perms = self::PERMS[$role] ?? [];
        return in_array('*', $perms, true) || in_array($perm, $perms, true);
    }

    /** صفحه/اکشن حساس: اگر مجوز نیست → پیام + بازگشت (برای POSTها). */
    public static function denyUnless(array $user, string $perm, string $what = 'این عملیات'): void
    {
        if (self::can($user, $perm)) {
            return;
        }
        Audit::log('rbac.denied', 'perm=' . $perm . ' user=' . ($user['id'] ?? '-') . ' path=' . Http::path());
        if (Http::wantsJson()) {
            Http::apiError('forbidden', 'برای ' . $what . ' دسترسی ندارید.', 403);
        }
        Session::flash('error', 'برای ' . $what . ' دسترسی ندارید.');
        Http::redirect($_SERVER['HTTP_REFERER'] ?? Config::url('admin'));
    }
}
