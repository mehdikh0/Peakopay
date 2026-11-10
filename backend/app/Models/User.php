<?php
/**
 * Peakopay — Panel users (account-scoped since 2.0).
 */
declare(strict_types=1);

final class User
{
    public static function findByEmail(string $email): ?array
    {
        return DB::one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    /**
     * ساخت کاربر در حساب + عضویت فروشگاه اصلی.
     * نقش از Account::ROLES اعتبارسنجی می‌شود.
     */
    public static function create(int $accountId, int $primaryStoreId, string $name, string $email, string $password, string $role = 'admin'): int
    {
        $role = Account::safeRole($role);
        $id = DB::insert('users', [
            'store_id'      => $primaryStoreId,
            'account_id'    => $accountId,
            'name'          => $name,
            'email'         => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'is_active'     => 1,
            'created_at'    => DB::now(),
        ]);
        if ($primaryStoreId > 0) {
            StoreMember::ensure($primaryStoreId, $id, $role);
        }
        return $id;
    }

    public static function verifyPassword(array $user, string $password): bool
    {
        return password_verify($password, (string) $user['password_hash']);
    }

    public static function updatePassword(int $id, string $password): void
    {
        DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$id]);
        Audit::log('user.password_changed', 'user=' . $id);
    }

    public static function touchLogin(int $id): void
    {
        DB::update('users', ['last_login_at' => DB::now()], 'id = ?', [$id]);
    }

    /**
     * دور ۲۹ — ردیف کامل (شامل password_hash) فقط برای مسیرهایی که باید
     * رمز عبور کاربرِ فعلی را تأیید کنند (2FA). findOrNull عمداً هش را
     * برنمی‌گرداند تا به‌طور پیش‌فرض در UI/لاگ نشت نکند.
     */
    public static function findForAuth(int $id): ?array
    {
        return DB::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findOrNull(int $id): ?array
    {
        return DB::one(
            'SELECT id, store_id, account_id, name, email, role, is_active, created_at, last_login_at FROM users WHERE id = ?',
            [$id],
        );
    }

    /** فعال/غیرفعال کردن دسترسی (عضویت‌ها حفظ می‌شود؛ ورود مسدود می‌شود). */
    public static function setActive(int $id, bool $active): void
    {
        DB::update('users', ['is_active' => $active ? 1 : 0], 'id = ?', [$id]);
        Audit::log($active ? 'user.activated' : 'user.deactivated', 'user=' . $id);
    }

    /** حذف کامل عضو (عضویت‌ها با FK حذف می‌شوند). آخرین مالک حساب حذف نمی‌شود. */
    public static function deleteMember(int $accountId, int $id): bool
    {
        $target = DB::one('SELECT id, role FROM users WHERE id = ? AND account_id = ?', [$id, $accountId]);
        if ($target === null) {
            return false;
        }
        if ((string) $target['role'] === 'owner'
            && (int) (DB::val("SELECT COUNT(*) FROM users WHERE account_id = ? AND role = 'owner'", [$accountId]) ?? 0) <= 1) {
            return false; // آخرین مالک — قابل حذف نیست
        }
        StoreMember::removeUser($id);
        DB::q('DELETE FROM users WHERE id = ?', [$id]);
        Audit::log('user.removed', 'user=' . $id);
        return true;
    }
}
