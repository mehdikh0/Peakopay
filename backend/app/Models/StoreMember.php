<?php
/**
 * Peakopay 2 — Store membership (کدام کاربر به کدام فروشگاه دسترسی دارد).
 *
 * منبع حقیقتِ دامنهٔ دسترسی پنل: هر کاربر فقط فروشگاه‌هایی را می‌بیند که
 * عضو آن‌ها باشد؛ نقشِ محلی هم از همین جدول خوانده می‌شود (پرکاربردترین نقش
 * بین حساب و فروشگاه ملاکِ صفحهٔ تیم است، اما بررسی مجوزهای حساس همیشه از
 * نقشِ حساب در users.role انجام می‌شود).
 */
declare(strict_types=1);

final class StoreMember
{
    public static function ensure(int $storeId, int $userId, string $role = 'admin'): void
    {
        $role = Account::safeRole($role);
        $exists = DB::one('SELECT id, role FROM store_members WHERE store_id = ? AND user_id = ?', [$storeId, $userId]);
        if ($exists === null) {
            DB::insert('store_members', [
                'store_id'   => $storeId,
                'user_id'    => $userId,
                'role'       => $role,
                'created_at' => DB::now(),
            ]);
        } elseif ($exists['role'] !== $role) {
            DB::update('store_members', ['role' => $role], 'id = ?', [(int) $exists['id']]);
        }
    }

    public static function remove(int $storeId, int $userId): void
    {
        DB::q('DELETE FROM store_members WHERE store_id = ? AND user_id = ?', [$storeId, $userId]);
    }

    public static function removeUser(int $userId): void
    {
        DB::q('DELETE FROM store_members WHERE user_id = ?', [$userId]);
    }

    /** فروشگاه‌هایی که کاربر عضو آن‌هاست (id => نقش محلی). */
    public static function storesOfUser(int $userId): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT s.id, sm.role FROM store_members sm JOIN stores s ON s.id = sm.store_id WHERE sm.user_id = ? ORDER BY s.id',
            [$userId],
        ) as $r) {
            $out[(int) $r['id']] = (string) $r['role'];
        }
        return $out;
    }

    public static function membersOfStore(int $storeId): array
    {
        return DB::all(
            'SELECT u.id, u.name, u.email, u.role, u.is_active, sm.role AS store_role, u.last_login_at
             FROM store_members sm JOIN users u ON u.id = sm.user_id
             WHERE sm.store_id = ? ORDER BY u.id',
            [$storeId],
        );
    }

    public static function isMember(int $storeId, int $userId): bool
    {
        return (int) (DB::val('SELECT COUNT(*) FROM store_members WHERE store_id = ? AND user_id = ?', [$storeId, $userId]) ?? 0) === 1;
    }
}
