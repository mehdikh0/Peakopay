<?php
/**
 * Peakopay 2 — Admin: team management (account members + per-store access).
 *
 * فقط مالک حساب. هر عضو: نقش سطح حساب + عضویت در فروشگاه‌های مشخص.
 * کاربرِ فروشگاه A به دادهٔ فروشگاه B هرگز دسترسی ندارد (Store::current).
 */
declare(strict_types=1);

final class TeamController
{
    public static function index(): void
    {
        $user  = AdminGuard::require();
        AdminGuard::denyUnless($user, 'team_manage', 'مدیریت تیم');
        $store = Store::current();
        $accountId = (int) ($user['account_id'] ?? 0);
        $members = $accountId > 0 ? Account::members($accountId) : [];
        $stores  = $accountId > 0 ? Account::stores($accountId) : [];
        $rows = [];
        foreach ($members as $m) {
            $rows[] = $m + [
                'stores' => StoreMember::storesOfUser((int) $m['id']),
                'twofa'  => TwoFactor::isEnabled((int) $m['id']),
            ];
        }
        View::page('admin/team', [
            'title'      => 'تیم و دسترسی‌ها',
            'activeNav'  => 'team',
            'user'       => $user,
            'store'      => $store,
            'members'    => $rows,
            'stores'     => $stores,
            'roles'      => Account::ROLE_LABELS,
        ]);
    }

    /** افزودن عضو: نقش حساب + فروشگاه‌های قابل دسترسی. */
    public static function add(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'team_manage', 'افزودن عضو');
        $accountId = (int) ($user['account_id'] ?? 0);
        if ($accountId <= 0) {
            Session::flash('error', 'حساب یافت نشد.');
            Http::redirect(Config::url('admin/team'));
        }
        $name     = trim((string) Http::post('name', ''));
        $email    = mb_strtolower(trim((string) Http::post('email', '')));
        $password = (string) Http::post('password', '');
        $role     = Account::safeRole((string) Http::post('role', 'operator'));
        $storeIds = array_map('intval', (array) (Http::post('stores', [])));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            Session::flash('error', 'نام، ایمیل معتبر و رمز حداقل ۸ نویسه لازم است.');
            Http::redirect(Config::url('admin/team'));
        }
        if (User::findByEmail($email) !== null) {
            Session::flash('error', 'این ایمیل قبلاً ثبت شده است.');
            Http::redirect(Config::url('admin/team'));
        }
        // فقط فروشگاه‌های همین حساب — تزریق شناسهٔ بیرونی بی‌اثر است
        $valid = array_intersect($storeIds, Account::storeIds($accountId));
        if ($valid === [] && $role !== 'owner') {
            Session::flash('error', 'حداقل یک فروشگاه برای دسترسی انتخاب کنید (مالک به همه فروشگاه‌ها دسترسی دارد).');
            Http::redirect(Config::url('admin/team'));
        }
        if ($role === 'owner') {
            $valid = Account::storeIds($accountId); // مالک همیشه همهٔ فروشگاه‌ها
        }
        $primary = $valid === [] ? (int) (Account::storeIds($accountId)[0] ?? 0) : (int) min($valid);
        $userId  = User::create($accountId, $primary, $name, $email, $password, $role);
        foreach ($valid as $sid) {
            StoreMember::ensure((int) $sid, $userId, $role);
        }
        Audit::log('team.member_added', 'user=' . $userId . ' role=' . $role . ' stores=' . implode(',', $valid));
        Session::flash('success', 'عضو «' . $name . '» اضافه شد. ایمیل و رمز را برایش بفرستید.');
        Http::redirect(Config::url('admin/team'));
    }

    /** حذف عضو یا تغییر دسترسی‌های فروشگاهی/نقش. */
    public static function update(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'team_manage', 'ویرایش عضو');
        $accountId = (int) ($user['account_id'] ?? 0);
        $targetId  = (int) Http::post('user_id', 0);
        $do        = (string) Http::post('do', '');
        $target    = DB::one('SELECT id, name, role FROM users WHERE id = ? AND account_id = ?', [$targetId, $accountId]);
        if ($target === null) {
            Session::flash('error', 'عضو یافت نشد.');
            Http::redirect(Config::url('admin/team'));
        }
        if ($do === 'remove') {
            if ((int) $target['id'] === (int) $user['id']) {
                Session::flash('error', 'خودتان را نمی‌توانید حذف کنید.');
                Http::redirect(Config::url('admin/team'));
            }
            if (User::deleteMember($accountId, $targetId)) {
                Session::flash('success', 'عضو حذف شد.');
            } else {
                Session::flash('error', 'آخرین مالک حساب قابل حذف نیست.');
            }
        } elseif ($do === 'toggle_active') {
            $active = (int) (DB::val('SELECT is_active FROM users WHERE id = ?', [$targetId]) ?? 1) === 1;
            if ((string) $target['role'] === 'owner' && $active) {
                Session::flash('error', 'مالک را نمی‌توان غیرفعال کرد؛ ابتدا مالک دیگری تعیین کنید.');
                Http::redirect(Config::url('admin/team'));
            }
            User::setActive($targetId, !$active);
            Session::flash('success', $active ? 'دسترسی عضو غیرفعال شد.' : 'دسترسی عضو فعال شد.');
        } elseif ($do === 'reset_2fa') {
            // دور ۲۹ — ریست ادمینی 2FA: فقط در صورت گم‌شدن احرازگر/کدهای بازیابی.
            // تأیید هویت ادمین با رمز عبور (recent authentication) + دلیل الزامی.
            if ((int) $target['id'] === (int) $user['id']) {
                Session::flash('error', 'برای خاموش کردن 2FAٔ خودتان از صفحهٔ «امنیت دو مرحله‌ای» استفاده کنید.');
                Http::redirect(Config::url('admin/team'));
            }
            $reason = trim((string) Http::post('reason', ''));
            $adminForAuth = User::findForAuth((int) $user['id']);
            if ($adminForAuth === null || !User::verifyPassword($adminForAuth, (string) Http::post('admin_password', ''))) {
                usleep(300000);
                Session::flash('error', 'رمز عبور شما نادرست است — بازنشانی انجام نشد.');
                Http::redirect(Config::url('admin/team'));
            }
            if (mb_strlen($reason) < TwoFactor::RESET_MIN_REASON) {
                Session::flash('error', 'دلیل بازنشانی حداقل ' . Helpers::faDigits((string) TwoFactor::RESET_MIN_REASON) . ' نویسه لازم دارد (برای سوابق امنیتی).');
                Http::redirect(Config::url('admin/team'));
            }
            if (!TwoFactor::isEnabled($targetId)) {
                Session::flash('error', 'این عضو ورود دومرحله‌ای ندارد.');
                Http::redirect(Config::url('admin/team'));
            }
            TwoFactor::adminReset($user, $targetId, $reason);
            Session::flash('success', 'ورود دومرحله‌ای «' . $target['name'] . '» بازنشانی شد. نشست‌های فعال او باطل شدند و با بنر درون‌پنل مطلع می‌شود؛ ورود بعدی‌اش باید احرازگر جدید پیکربندی کند.');
        } elseif ($do === 'stores') {
            $role     = Account::safeRole((string) Http::post('role', (string) $target['role']));
            $storeIds = array_map('intval', (array) (Http::post('stores', [])));
            $valid    = array_intersect($storeIds, Account::storeIds($accountId));
            if ($role === 'owner') {
                $valid = Account::storeIds($accountId);
            }
            if ($valid === [] && $role !== 'owner') {
                Session::flash('error', 'حداقل یک فروشگاه انتخاب کنید.');
                Http::redirect(Config::url('admin/team'));
            }
            foreach (Account::storeIds($accountId) as $sid) {
                if (in_array($sid, $valid, true)) {
                    StoreMember::ensure((int) $sid, $targetId, $role);
                } else {
                    StoreMember::remove((int) $sid, $targetId);
                }
            }
            DB::update('users', ['role' => $role], 'id = ? AND account_id = ?', [$targetId, $accountId]);
            Audit::log('team.member_updated', 'user=' . $targetId . ' role=' . $role . ' stores=' . implode(',', $valid));
            Session::flash('success', 'دسترسی‌های عضو به‌روز شد.');
        }
        Http::redirect(Config::url('admin/team'));
    }
}
