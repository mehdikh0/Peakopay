<?php
/**
 * Peakopay 2 — Accounts (business entity above stores).
 *
 * یک «حساب» = یک کسب‌وکار (تاجر مستقل یا مالک چند فروشگاه ووکامرس).
 * فروشگاه‌ها از طریق stores.account_id به حساب وصل می‌شوند؛ کاربران از طریق
 * users.account_id و عضویت‌های store_members (نقش محلی هر فروشگاه).
 */
declare(strict_types=1);

final class Account
{
    /** نقش‌های معتبر سطح حساب (store_members همین مقادیر را برای نقش محلی استفاده می‌کند). */
    public const ROLES = ['owner', 'admin', 'accountant', 'operator'];

    public const ROLE_LABELS = [
        'owner'      => 'مالک حساب',
        'admin'      => 'مدیر',
        'accountant' => 'حسابدار',
        'operator'   => 'اپراتور',
    ];

    public static function create(string $name): int
    {
        return DB::insert('accounts', [
            'name'       => mb_substr(trim($name) !== '' ? $name : 'حساب Peakopay', 0, 120),
            'created_at' => DB::now(),
        ]);
    }

    public static function get(int $id): ?array
    {
        return DB::one('SELECT * FROM accounts WHERE id = ?', [$id]);
    }

    public static function rename(int $id, string $name): void
    {
        DB::update('accounts', ['name' => mb_substr(trim($name), 0, 120)], 'id = ?', [$id]);
    }

    /** فروشگاه‌های حساب (بر اساس عضویت یا مالکیت مستقیم). */
    public static function storeIds(int $accountId): array
    {
        return array_map(
            'intval',
            array_column(DB::all('SELECT id FROM stores WHERE account_id = ? ORDER BY id', [$accountId]), 'id'),
        );
    }

    public static function stores(int $accountId): array
    {
        return DB::all('SELECT * FROM stores WHERE account_id = ? ORDER BY id', [$accountId]);
    }

    /** آیا store در مالکیت حساب است؟ (مرز اجباری tenant-isolation) */
    public static function ownsStore(int $accountId, int $storeId): bool
    {
        return (int) (DB::val('SELECT COUNT(*) FROM stores WHERE id = ? AND account_id = ?', [$storeId, $accountId]) ?? 0) === 1;
    }

    public static function members(int $accountId): array
    {
        return DB::all(
            'SELECT u.id, u.name, u.email, u.role, u.is_active, u.last_login_at, u.created_at
             FROM users u WHERE u.account_id = ? ORDER BY u.id',
            [$accountId],
        );
    }

    /**
     * مهاجرت داده‌های نصب‌های تک‌فروشگاهی موجود (idempotent — بعد از هر migrate).
     * - هر فروشگاه بدون حساب → حساب جدید با نام خودش (دامنه از url استخراج می‌شود)
     * - هر کاربر بدون حساب → عضو حساب فروشگاهش؛ اولین کاربر هر حساب «مالک» می‌شود
     * - هر دستگاه بدون حساب → حسابِ فروشگاهش
     */
    public static function backfill(): void
    {
        // ۱) فروشگاه‌ها
        foreach (DB::all('SELECT id, name, url FROM stores WHERE account_id IS NULL') as $s) {
            $accountId = self::create((string) $s['name']);
            DB::update('stores', [
                'account_id'        => $accountId,
                'domain'            => self::domainFromUrl((string) $s['url']),
                'connection_status' => 'connected',
            ], 'id = ?', [(int) $s['id']]);
        }

        // ۲) کاربران — نقش اولین کاربرِ هر حساب «مالک»، بقیه همان نقش قبلی
        foreach (DB::all('SELECT id, store_id, role FROM users WHERE account_id IS NULL AND store_id IS NOT NULL') as $u) {
            $storeId    = (int) $u['store_id'];
            $accountRow = DB::one('SELECT account_id FROM stores WHERE id = ?', [$storeId]);
            if ($accountRow === null || $accountRow['account_id'] === null) {
                continue; // فروشگاه بی‌حساب (نباید رخ دهد — مرحله ۱ پوشش می‌دهد)
            }
            $accountId = (int) $accountRow['account_id'];
            $first     = (int) (DB::val('SELECT COUNT(*) FROM users WHERE account_id = ?', [$accountId]) ?? 0) === 0;
            $role      = $first ? 'owner' : self::safeRole((string) $u['role']);
            DB::update('users', ['account_id' => $accountId, 'role' => $role], 'id = ?', [(int) $u['id']]);
            StoreMember::ensure($storeId, (int) $u['id'], $role);
        }

        // ۳) دستگاه‌ها
        foreach (DB::all('SELECT id, store_id FROM devices WHERE account_id IS NULL AND store_id IS NOT NULL') as $d) {
            $accountRow = DB::one('SELECT account_id FROM stores WHERE id = ?', [(int) $d['store_id']]);
            if ($accountRow !== null && $accountRow['account_id'] !== null) {
                DB::update('devices', ['account_id' => (int) $accountRow['account_id']], 'id = ?', [(int) $d['id']]);
            }
        }
    }

    /** دامنهٔ امن از URL (فقط برای نمایش). */
    public static function domainFromUrl(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = trim(explode('/', trim($url))[0] ?? '');
        }
        $host = strtolower(preg_replace('#[^a-z0-9.\-]#i', '', $host) ?? '');
        return mb_substr($host, 0, 190);
    }

    /** نقشِ مجاز — مقادیر ناشناخته به «اپراتور» سقوط می‌کنند (کمترین دسترسی). */
    public static function safeRole(string $role): string
    {
        return in_array($role, self::ROLES, true) ? $role : 'operator';
    }
}
