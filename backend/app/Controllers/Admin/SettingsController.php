<?php
/**
 * Peakopay — Admin settings (all sections of one page, saved per-section).
 * Secrets are never echoed back; regenerate buttons create new values.
 */
declare(strict_types=1);

final class SettingsController
{
    public const SECTIONS = ['general', 'payment', 'paypage', 'matching', 'woocommerce', 'android', 'security', 'notifications', 'advanced'];

    public static function index(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $section = (string) Http::get('section', 'general');
        if (!in_array($section, self::SECTIONS, true)) {
            $section = 'general';
        }
        $extra = [];
        if ($section === 'paypage') {
            $extra['defaultCss'] = (string) @file_get_contents(HP_BASE . '/assets/css/pay.css');
            $extra['loadDefault'] = Http::get('load', '') === 'default';
        }
        View::page('admin/settings', array_merge([
            'title'      => 'تنظیمات',
            'user'       => $user,
            'store'      => $store,
            's'          => Setting::all(),
            // دور ۲۳ — توکن کرون در دامنهٔ نصب است، نه فروشگاه
            'cronToken'  => Setting::installGet('cron_token'),
            'section'    => $section,
            'sections'   => self::SECTIONS,
            'apiKeyMask' => $sid > 0 ? ApiKey::maskedActive($sid) : null,
            'apiKeyUsed' => $sid > 0 ? ApiKey::lastUsed($sid) : null,
            'reinstall'  => Installer::reinstallAllowed(),
            'activeNav'  => 'settings',
        ], $extra));
    }

    public static function save(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'settings_edit', 'ذخیره تنظیمات');
        $store   = Store::current();
        $sid     = (int) ($store['id'] ?? 0);
        $section = (string) Http::post('section', '');
        if (!in_array($section, self::SECTIONS, true) || $sid === 0) {
            Session::flash('error', 'بخش تنظیمات نامعتبر است.');
            Http::redirect(Config::url('admin/settings'));
        }
        $err = self::{"save{$section}"}($sid);
        if ($err !== null) {
            Session::flash('error', $err);
        } else {
            Audit::log('settings.saved', 'section=' . $section);
            Session::flash('success', 'تنظیمات ذخیره شد.');
        }
        Http::redirect(Config::url('admin/settings') . '?section=' . $section);
    }

    // ------------------------------------------------------------- sections

    private static function saveGeneral(int $sid): ?string
    {
        $v = Validator::make($_POST)
            ->rule('store_name', 'required|minlen:2|maxlen:190', 'نام فروشگاه')
            ->rule('store_url', 'nullable|maxlen:250|url', 'آدرس سایت')
            ->rule('support_phone', 'nullable|maxlen:40', 'تلفن پشتیبانی')
            ->rule('support_telegram', 'nullable|maxlen:120', 'تلگرام پشتیبانی')
            ->rule('currency', 'required|in:IRT;IRR', 'واحد نمایش')
            ->rule('timezone', 'required|maxlen:64', 'منطقه زمانی');
        if ($v->fails()) {
            return $v->firstError();
        }
        if (!in_array((string) $v->value('timezone'), DateTimeZone::listIdentifiers(), true)) {
            return 'منطقه زمانی معتبر نیست.';
        }
        $in = $v->values();
        Store::update($sid, [
            'name'             => (string) $in['store_name'],
            'url'              => (string) ($in['store_url'] ?? ''),
            'support_phone'    => (string) ($in['support_phone'] ?? ''),
            'support_telegram' => (string) ($in['support_telegram'] ?? ''),
            'currency'         => (string) $in['currency'],
            'timezone'         => (string) $in['timezone'],
        ]);
        return null;
    }

    private static function savePayment(int $sid): ?string
    {
        $v = Validator::make($_POST)
            ->rule('payment_expiry_minutes', 'required|int|min:5|max:10080', 'مدت اعتبار پرداخت')
            ->rule('payment_tolerance_rial', 'required|int|min:0|max:10000000', 'تلورانس مبلغ')
            ->rule('min_amount_rial', 'required|int|min:0', 'حداقل مبلغ')
            ->rule('max_amount_rial', 'required|int|min:1000', 'حداکثر مبلغ')
            ->rule('unique_amount', 'bool', 'مبلغ یکتا');
        if ($v->fails()) {
            return $v->firstError();
        }
        $min = (int) $v->value('min_amount_rial');
        $max = (int) $v->value('max_amount_rial');
        if ($max <= $min) {
            return 'حداکثر مبلغ باید از حداقل مبلغ بیشتر باشد.';
        }
        Setting::set('payment_expiry_minutes', (string) $v->value('payment_expiry_minutes'));
        Setting::set('payment_tolerance_rial', (string) $v->value('payment_tolerance_rial'));
        Setting::set('min_amount_rial', (string) $min);
        Setting::set('max_amount_rial', (string) $max);
        Setting::set('unique_amount', self::on($_POST['unique_amount'] ?? ''));
        return null;
    }

    /** ظاهر صفحه پرداخت — CSS سفارشی (فقط ظاهر؛ هرگز کد اجرایی نیست) */
    private static function savePaypage(int $sid): ?string
    {
        $css = (string) ($_POST['custom_css'] ?? '');
        if (isset($_POST['do_reset'])) {
            $css = '';
        }
        // سلب ایمنی: خروج از تگ style/اسکریپت ممنوع — این فیلد فقط CSS است
        $css = str_ireplace(['</style', '<script', '</script', '<iframe'], '', $css);
        if (mb_strlen($css) > 30000) {
            return 'کد CSS بیش از حد طولانی است (حداکثر ۳۰٬۰۰۰ نویسه).';
        }
        Setting::set('pay_custom_css', $css);
        return null;
    }

    private static function saveMatching(int $sid): ?string
    {
        $v = Validator::make($_POST)
            ->rule('match_mode', 'required|in:tolerance;exact', 'روش تطبیق مبلغ')
            ->rule('match_window_minutes', 'required|int|min:30|max:20160', 'بازه زمانی تطبیق')
            ->rule('paid_order_status', 'required|in:processing;completed', 'وضعیت سفارش پس از پرداخت')
            ->rule('expired_order_status', 'required|in:cancelled;failed;pending', 'وضعیت سفارش پس از انقضا');
        if ($v->fails()) {
            return $v->firstError();
        }
        Setting::set('match_mode', (string) $v->value('match_mode'));
        Setting::set('match_window_minutes', (string) $v->value('match_window_minutes'));
        Setting::set('match_by_card', self::on($_POST['match_by_card'] ?? ''));
        Setting::set('match_by_reference', self::on($_POST['match_by_reference'] ?? ''));
        Setting::set('auto_confirm', self::on($_POST['auto_confirm'] ?? ''));
        Setting::set('paid_order_status', (string) $v->value('paid_order_status'));
        Setting::set('expired_order_status', (string) $v->value('expired_order_status'));
        return null;
    }

    private static function saveWoocommerce(int $sid): ?string
    {
        $v = Validator::make($_POST)
            ->rule('woo_site_url', 'nullable|maxlen:250|url', 'آدرس فروشگاه ووکامرس');
        if ($v->fails()) {
            return $v->firstError();
        }
        Setting::set('woo_site_url', rtrim((string) ($v->value('woo_site_url') ?? ''), '/'));
        Setting::set('woo_enabled', self::on($_POST['woo_enabled'] ?? ''));
        return null;
    }

    private static function saveAndroid(int $sid): ?string
    {
        return null; // device management lives on the Devices page
    }

    private static function saveSecurity(int $sid): ?string
    {
        $v = Validator::make($_POST)
            ->rule('session_timeout_minutes', 'required|int|min:10|max:1440', 'مهلت نشست')
            ->rule('login_max_attempts', 'required|int|min:3|max:20', 'حداکثر تلاش ورود');
        if ($v->fails()) {
            return $v->firstError();
        }
        Setting::set('session_timeout_minutes', (string) $v->value('session_timeout_minutes'));
        Setting::set('login_max_attempts', (string) $v->value('login_max_attempts'));
        // دور ۲۹ — سیاست الزام 2FA برای مدیران (فقط مالک می‌تواند تغییر دهد)
        $actor = User::findOrNull(Session::userId() ?? 0);
        if ($actor !== null && (string) ($actor['role'] ?? '') === 'owner') {
            Setting::set('twofactor_required_admins', Http::post('twofactor_required_admins') === '1' ? '1' : '0');
        }
        return null;
    }

    private static function saveNotifications(int $sid): ?string
    {
        $v = Validator::make($_POST)
            ->rule('notify_email', 'nullable|email', 'ایمیل دریافت اطلاع‌رسانی')
            ->rule('telegram_chat_id', 'nullable|maxlen:60', 'شناسه چت تلگرام');
        if ($v->fails()) {
            return $v->firstError();
        }
        $token = trim((string) Http::post('telegram_bot_token', ''));
        if ($token !== '' && !preg_match('#^\d{6,}:[A-Za-z0-9_-]{20,}$#', $token)) {
            return 'قالب توکن ربات تلگرام معتبر نیست.';
        }
        if ($token !== '') {
            Setting::set('telegram_bot_token', $token);
        }
        Setting::set('notify_email_enabled', self::on($_POST['notify_email_enabled'] ?? ''));
        Setting::set('notify_email', (string) ($v->value('notify_email') ?? ''));
        Setting::set('notify_telegram_enabled', self::on($_POST['notify_telegram_enabled'] ?? ''));
        Setting::set('telegram_chat_id', (string) ($v->value('telegram_chat_id') ?? ''));
        Setting::set('notify_event_paid', self::on($_POST['notify_event_paid'] ?? ''));
        Setting::set('notify_event_unmatched', self::on($_POST['notify_event_unmatched'] ?? ''));
        Setting::set('notify_event_device', self::on($_POST['notify_event_device'] ?? ''));
        return null;
    }

    private static function saveAdvanced(int $sid): ?string
    {
        Setting::set('test_mode', self::on($_POST['test_mode'] ?? ''));
        $rules = trim((string) Http::post('sms_rules', ''));
        if ($rules !== '') {
            $decoded = json_decode($rules, true);
            if (!is_array($decoded) || !isset($decoded['rules']) || !is_array($decoded['rules'])) {
                return 'JSON قواعد پیامک معتبر نیست (باید شکل {"rules":[…]} داشته باشد).';
            }
            Setting::set('sms_rules', $rules);
        } else {
            Setting::set('sms_rules', '');
        }
        return null;
    }

    // ------------------------------------------------------- special actions

    /** POST: regenerate API key / webhook secret / toggle test mode / reopen installer */
    public static function action(): void
    {
        $user = AdminGuard::requirePost();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $do    = (string) Http::post('do', '');
        switch ($do) {
            case 'regen_api_key':
                $key = ApiKey::issue($sid, 'WooCommerce');
                Audit::log('apikey.regenerated', 'by user=' . $user['id']);
                // persists in session until explicitly dismissed — survives reload/prefetch/page-cache
                Session::pending('reveal_api_key', $key);
                Session::flash('success', 'کلید API جدید ساخته شد — کادر بالای صفحه را ببینید. کلید قبلی باطل شد.');
                break;
            case 'regen_webhook_secret':
                $secret = 'whsec_' . Crypto::randomHex(16);
                Setting::set('woo_webhook_secret', $secret);
                Audit::log('webhook_secret.regenerated', 'by user=' . $user['id']);
                Session::pending('reveal_webhook_secret', $secret);
                Session::flash('success', 'راز Webhook جدید ساخته شد — کادر بالای صفحه را ببینید.');
                break;
            case 'dismiss_reveal':
                Session::clearPending('reveal_api_key', 'reveal_webhook_secret');
                Session::flash('success', 'نمایش مقدارهای جدید پنهان شد.');
                break;
            case 'open_reinstall':
                Installer::openReinstallWindow();
                Session::flash('success', 'نصب‌کننده برای ۳۰ دقیقه باز شد. برای اجرای مجدد به /install بروید.');
                break;
            default:
                Session::flash('error', 'عملیات نامعتبر.');
        }
        $back = (string) ($_POST['back'] ?? '');
        if ($back === '' || !str_starts_with($back, Config::baseUrl())) {
            $back = Config::url('admin/settings') . '?section=woocommerce';
        }
        Http::redirect($back);
    }

    private static function on(string $value): string
    {
        return in_array($value, ['1', 'on', 'true'], true) ? '1' : '0';
    }
}
