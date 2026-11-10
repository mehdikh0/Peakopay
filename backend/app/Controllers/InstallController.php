<?php
/**
 * Peakopay — One-click web installer (https://example.com/install).
 *
 * Step 1: environment check          (auto)
 * Step 2: database (test + migrate)  (auto — no manual SQL ever)
 * Step 3: admin account
 * Step 4: store/merchant info
 * Step 5: destination card (encrypted at rest, masked after save)
 * Step 6: WooCommerce + Android credentials (shown ONCE)
 * Step 7: finish (lock file written)
 */
declare(strict_types=1);

final class InstallController
{
    private const STEP_MIN = 1;
    private const STEP_MAX = 7;

    public static function index(): void
    {
        Session::start();
        if (Installer::isInstalled() && !Installer::reinstallAllowed()) {
            if (!empty($_SESSION['install_finished'])) {
                // one-time success page right after finishing
                unset($_SESSION['install_finished']);
                self::showStep(7);
                return;
            }
            View::page('install/locked', [], 'layout_install');
            return;
        }
        $step = self::currentStep();
        if (Http::isPost()) {
            self::handlePost($step);
        }
        self::showStep($step);
    }

    private static function currentStep(): int
    {
        $step = (int) (Http::get('step', $_SESSION['install_step'] ?? 1));
        $step = max(self::STEP_MIN, min(self::STEP_MAX, $step));
        // gate: can't skip past what is done
        $maxDone = 1;
        if (Config::exists() && Config::get('db.name') !== '') {
            $maxDone = 2;
        }
        if (!empty($_SESSION['install_admin'])) {
            $maxDone = 3;
        }
        if (!empty($_SESSION['install_store'])) {
            $maxDone = 4;
        }
        if (!empty($_SESSION['install_card'])) {
            $maxDone = 5;
        }
        if (!empty($_SESSION['install_seeded'])) {
            $maxDone = 7;
        }
        return min($step, $maxDone + 1);
    }

    private static function handlePost(int $step): void
    {
        Session::checkCsrf();
        switch ($step) {
            case 1:
                self::postRequirements();
                break;
            case 2:
                self::postDatabase();
                break;
            case 3:
                self::postAdmin();
                break;
            case 4:
                self::postStore();
                break;
            case 5:
                self::postCard();
                break;
            case 6:
                self::postFinish();
                break;
            default:
                Http::redirect(Config::url('install') . '?step=7');
        }
    }

    // ---------------------------------------------------------------- steps

    private static function postRequirements(): void
    {
        if (!Installer::requirementsPassed()) {
            Session::flash('error', 'چند مورد الزامی قرمز است؛ ابتدا آن‌ها را در cPanel فعال کنید.');
            Http::redirect(Config::url('install'));
        }
        Http::redirect(Config::url('install') . '?step=2');
    }

    private static function postDatabase(): void
    {
        $v = Validator::make($_POST)
            ->rule('db_host', 'required|maxlen:190', 'هاست دیتابیس')
            ->rule('db_port', 'int|min:1|max:65535', 'پورت')
            ->rule('db_name', 'required|maxlen:190', 'نام دیتابیس')
            ->rule('db_user', 'required|maxlen:190', 'نام کاربری دیتابیس')
            ->rule('db_pass', 'maxlen:190', 'رمز عبور دیتابیس');
        if ($v->fails()) {
            Session::flash('error', $v->firstError());
            Http::redirect(Config::url('install') . '?step=2');
        }
        $in = $v->values();
        $port = (int) ($in['db_port'] !== null && $in['db_port'] !== '' ? $in['db_port'] : 3306);
        $test = Installer::testDb((string) $in['db_host'], $port, (string) $in['db_name'], (string) $in['db_user'], (string) ($in['db_pass'] ?? ''));
        if (!$test['ok']) {
            Session::flash('error', $test['message']);
            Http::redirect(Config::url('install') . '?step=2');
        }
        try {
            Installer::saveConfigAndMigrate([
                'host' => (string) $in['db_host'],
                'port' => $port,
                'name' => (string) $in['db_name'],
                'user' => (string) $in['db_user'],
                'pass' => (string) ($in['db_pass'] ?? ''),
            ], Http::guessBaseUrl());
        } catch (Throwable $e) {
            Session::flash('error', 'در ساخت جدول‌ها خطا رخ داد: ' . mb_substr($e->getMessage(), 0, 200));
            Http::redirect(Config::url('install') . '?step=2');
        }
        $_SESSION['install_step'] = 3;
        Session::flash('success', 'اتصال برقرار شد و همه جدول‌ها ساخته شدند. ✅');
        Http::redirect(Config::url('install') . '?step=3');
    }

    private static function postAdmin(): void
    {
        $v = Validator::make($_POST)
            ->rule('admin_name', 'required|minlen:2|maxlen:120', 'نام مدیر')
            ->rule('admin_email', 'required|email', 'ایمیل مدیر')
            ->rule('admin_pass', 'required|minlen:8|maxlen:190', 'رمز عبور');
        if ($v->fails()) {
            Session::flash('error', $v->firstError());
            Http::redirect(Config::url('install') . '?step=3');
        }
        if (($_POST['admin_pass'] ?? '') !== ($_POST['admin_pass2'] ?? '')) {
            Session::flash('error', 'تکرار رمز عبور با رمز عبور یکسان نیست.');
            Http::redirect(Config::url('install') . '?step=3');
        }
        $in = $v->values();
        $_SESSION['install_admin'] = [
            'name'  => (string) $in['admin_name'],
            'email' => (string) $in['admin_email'],
            'password' => (string) $in['admin_pass'],
        ];
        $_SESSION['install_step'] = 4;
        Http::redirect(Config::url('install') . '?step=4');
    }

    private static function postStore(): void
    {
        $v = Validator::make($_POST)
            ->rule('store_name', 'required|minlen:2|maxlen:190', 'نام فروشگاه')
            ->rule('store_url', 'nullable|maxlen:250|url', 'آدرس سایت')
            ->rule('support_phone', 'nullable|maxlen:40', 'تلفن پشتیبانی')
            ->rule('support_telegram', 'nullable|maxlen:120', 'تلگرام پشتیبانی')
            ->rule('currency', 'required|in:IRT;IRR', 'واحد نمایش مبالغ')
            ->rule('timezone', 'required|maxlen:64', 'منطقه زمانی');
        if ($v->fails()) {
            Session::flash('error', $v->firstError());
            Http::redirect(Config::url('install') . '?step=4');
        }
        $in = $v->values();
        $tzList = DateTimeZone::listIdentifiers();
        if (!in_array((string) $in['timezone'], $tzList, true)) {
            Session::flash('error', 'منطقه زمانی معتبر نیست.');
            Http::redirect(Config::url('install') . '?step=4');
        }
        $_SESSION['install_store'] = [
            'name'     => (string) $in['store_name'],
            'url'      => (string) ($in['store_url'] ?? ''),
            'phone'    => (string) ($in['support_phone'] ?? ''),
            'telegram' => (string) ($in['support_telegram'] ?? ''),
            'currency' => (string) $in['currency'],
            'timezone' => (string) $in['timezone'],
        ];
        $_SESSION['install_step'] = 5;
        Http::redirect(Config::url('install') . '?step=5');
    }

    private static function postCard(): void
    {
        $v = Validator::make($_POST)
            ->rule('bank_name', 'required|maxlen:80', 'نام بانک')
            ->rule('card_holder', 'required|minlen:3|maxlen:120', 'نام صاحب کارت')
            ->rule('card_number', 'required|card', 'شماره کارت')
            ->rule('iban', 'nullable|maxlen:30|iban', 'شماره شبا')
            ->rule('account_number', 'nullable|maxlen:30', 'شماره حساب');
        if ($v->fails()) {
            Session::flash('error', $v->firstError());
            Http::redirect(Config::url('install') . '?step=5');
        }
        $in = $v->values();
        $_SESSION['install_card'] = [
            'bank_name' => (string) $in['bank_name'],
            'holder'    => (string) $in['card_holder'],
            'number'    => Crypto::normalizeCardNumber((string) $in['card_number']),
            'iban'      => (string) ($in['iban'] ?? ''),
            'account'   => (string) ($in['account_number'] ?? ''),
        ];
        // everything collected — create the store now
        try {
            $creds = Installer::seed(
                $_SESSION['install_store'],
                $_SESSION['install_admin'],
                $_SESSION['install_card']
            );
            $_SESSION['install_seeded']  = true;
            $_SESSION['install_step']    = 6;
            $_SESSION['install_creds']   = $creds;
            Session::flash('success', 'فروشگاه ساخته شد. اطلاعات اتصال را همین‌جا ذخیره کنید — فقط یک بار نمایش داده می‌شود.');
            Http::redirect(Config::url('install') . '?step=6');
        } catch (Throwable $e) {
            Log::error('seed failed: ' . $e->getMessage());
            Session::flash('error', 'ساخت فروشگاه ناموفق بود: ' . mb_substr($e->getMessage(), 0, 200));
            Http::redirect(Config::url('install') . '?step=5');
        }
    }

    private static function postFinish(): void
    {
        Installer::lock();
        Audit::log('installer.finished', 'installation locked');
        $_SESSION['install_step'] = 7;
        $_SESSION['install_finished'] = true;
        unset(
            $_SESSION['install_admin'],
            $_SESSION['install_store'],
            $_SESSION['install_card'],
            $_SESSION['install_creds'],
            $_SESSION['install_seeded'],
            $_SESSION['install_step']
        );
        Http::redirect(Config::url('install') . '?step=7');
    }

    // ---------------------------------------------------------------- views

    private static function showStep(int $step): void
    {
        $data = [
            'step'      => $step,
            'checks'    => [],
            'timezones' => ['Asia/Tehran' => 'تهران (ایران)'],
        ];
        if ($step === 1) {
            $data['checks'] = Installer::requirements();
        }
        if ($step === 2) {
            // prefill sensible cPanel defaults
            $data['db_defaults'] = [
                'host' => 'localhost',
                'name' => '',
                'user' => '',
            ];
        }
        if ($step === 6) {
            $data['creds'] = $_SESSION['install_creds'] ?? null;
            if ($data['creds'] === null) {
                Http::redirect(Config::url('admin/login'));
            }
            $data['backend_url'] = Config::baseUrl();
        }
        View::page('install/step' . $step, $data, 'layout_install');
    }
}
