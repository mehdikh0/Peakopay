<?php
/**
 * Peakopay 2 — Admin: stores of the account (add / connect / disconnect / edit).
 *
 * چندفروشگاهی: هر فروشگاه یک نصب مستقل ووکامرس است با کلید API خودش.
 * مرز امنیتی: فقط فروشگاه‌های همین حساب؛ تغییر شناسه از بیرون بی‌اثر است.
 */
declare(strict_types=1);

final class StoresController
{
    public static function index(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $accountId = (int) ($user['account_id'] ?? 0);
        $stores = $accountId > 0 ? Account::stores($accountId) : [];
        $rows = [];
        foreach ($stores as $s) {
            $sid = (int) $s['id'];
            $rows[] = $s + [
                'members_count' => (int) DB::val('SELECT COUNT(*) FROM store_members WHERE store_id = ?', [$sid]),
                'cards_count'   => (int) DB::val('SELECT COUNT(*) FROM cards WHERE store_id = ? AND is_active = 1', [$sid]),
                'api_key_mask'  => ApiKey::maskedActive($sid),
                'paid_total'    => (int) DB::val("SELECT COUNT(*) FROM payment_sessions WHERE store_id = ? AND status = 'paid' AND is_test = 0", [$sid]),
            ];
        }
        View::page('admin/stores', [
            'title'      => 'فروشگاه‌ها',
            'activeNav'  => 'stores',
            'user'       => $user,
            'store'      => $store,
            'stores'     => $rows,
            'isOwner'    => AdminGuard::can($user, 'store_manage'),
            'revealKey'  => Session::pending('reveal_store_key'),
            'revealName' => Session::pending('reveal_store_key_name'),
        ]);
    }

    /** ساخت فروشگاه جدید در همین حساب. */
    public static function save(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'store_manage', 'ساخت فروشگاه جدید');
        $accountId = (int) ($user['account_id'] ?? 0);
        if ($accountId <= 0) {
            Session::flash('error', 'حساب یافت نشد.');
            Http::redirect(Config::url('admin/stores'));
        }
        $name   = trim((string) Http::post('name', ''));
        $domain = Account::domainFromUrl((string) Http::post('domain', ''));
        $url    = rtrim(trim((string) Http::post('url', '')), '/');
        if ($name === '') {
            Session::flash('error', 'نام فروشگاه را وارد کنید.');
            Http::redirect(Config::url('admin/stores'));
        }
        if ($url === '' && $domain !== '') {
            $url = 'https://' . $domain;
        }
        $storeId = Store::create($name, $url, '', '', 'IRT', 'Asia/Tehran', $accountId);
        // مالکِ حساب، عضوِ همهٔ فروشگاه‌هایش است
        StoreMember::ensure($storeId, (int) $user['id'], 'owner');
        $key = ApiKey::issue($storeId, 'WooCommerce');
        $webhook = 'whsec_' . Crypto::randomHex(16);
        Setting::set('woo_webhook_secret', $webhook, $storeId);
        if ($url !== '') {
            Setting::set('woo_site_url', $url, $storeId);
        }
        Audit::log('store.created', 'store=' . $storeId . ' name=' . mb_substr($name, 0, 60));
        Session::pending('reveal_store_key', $key);
        Session::pending('reveal_store_key_name', $name);
        Session::setActiveStore($storeId);
        Session::flash('success', 'فروشگاه «' . $name . '» ساخته شد. کلید اتصال ووکامرس را همین حالا ذخیره کنید.');
        Http::redirect(Config::url('admin/stores'));
    }

    /** ویرایش نام/دامنه/وضعیت اتصال + ساخت کلید جدید. */
    public static function update(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'store_manage', 'ویرایش فروشگاه');
        $storeId = (int) Http::post('store_id', 0);
        if (!self::ownStore($user, $storeId)) {
            Session::flash('error', 'این فروشگاه متعلق به حساب شما نیست.');
            Http::redirect(Config::url('admin/stores'));
        }
        $do = (string) Http::post('do', '');
        if ($do === 'rekey') {
            $key = ApiKey::issue($storeId, 'WooCommerce');
            Session::pending('reveal_store_key', $key);
            Session::pending('reveal_store_key_name', (string) DB::val('SELECT name FROM stores WHERE id = ?', [$storeId]));
            Audit::log('store.rekey', 'store=' . $storeId);
            Session::flash('success', 'کلید API جدید ساخته شد — کلید قبلی از کار افتاد. افزونه ووکامرس را با کلید جدید به‌روز کنید.');
        } elseif ($do === 'disconnect') {
            Store::update($storeId, ['connection_status' => 'disconnected']);
            Audit::log('store.disconnected', 'store=' . $storeId);
            Session::flash('success', 'فروشگاه قطع شد (کلیدها باقی‌اند؛ با «اتصال مجدد» برمی‌گردد).');
        } elseif ($do === 'reconnect') {
            Store::update($storeId, ['connection_status' => 'connected']);
            Audit::log('store.reconnected', 'store=' . $storeId);
            Session::flash('success', 'فروشگاه دوباره متصل شد.');
        } elseif ($do === 'edit') {
            $name = trim((string) Http::post('name', ''));
            Store::update($storeId, [
                'name'   => $name !== '' ? $name : null,
                'domain' => Account::domainFromUrl((string) Http::post('domain', '')),
                'url'    => rtrim(trim((string) Http::post('url', '')), '/'),
            ]);
            Audit::log('store.edited', 'store=' . $storeId);
            Session::flash('success', 'اطلاعات فروشگاه ذخیره شد.');
        }
        Http::redirect(Config::url('admin/stores'));
    }

    /** تعویض فروشگاه فعال پنل (سوییچر بالای صفحه). */
    public static function switchTo(): void
    {
        $user = AdminGuard::requirePost();
        $storeId = (int) Http::post('store_id', 0);
        if (!self::ownStore($user, $storeId)) {
            Session::flash('error', 'به این فروشگاه دسترسی ندارید.');
            Http::redirect(Config::url('admin'));
        }
        Session::setActiveStore($storeId);
        Http::redirect(Config::url('admin'));
    }

    /** آیا کاربر به این فروشگاه دسترسی دارد؟ (عضویت یا مالکیت حساب) */
    private static function ownStore(array $user, int $storeId): bool
    {
        if ($storeId <= 0) {
            return false;
        }
        $accountId = (int) ($user['account_id'] ?? 0);
        if ($accountId > 0 && Account::ownsStore($accountId, $storeId)) {
            return true;
        }
        return StoreMember::isMember($storeId, (int) $user['id']);
    }
}
