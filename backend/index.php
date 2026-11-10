<?php
/**
 * Peakopay — front controller.
 *
 * Authoritative deployment layout (see .htaccess in this directory):
 *   Document Root = THIS directory  (e.g. /home/USER/public_html/pay)
 *
 * The web server (Apache/LiteSpeed) serves real files (assets/, cron.php)
 * directly and routes every other request here. All application code,
 * configuration and storage live OUTSIDE the routed paths and are denied
 * by .htaccess.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

Http::sendSecurityHeaders();

// خودترمیمی دیتابیس (دور ۱۸): ارتقا با «جایگزینی فایل‌ها» حالا مهاجرت‌های
// در انتظار را خودکار اعمال می‌کند — هزینهٔ عادی هر درخواست: یک فایل کوچک marker.
// (قبلاً migrate فقط هنگام نصب اولیه اجرا می‌شد و صفحات وابسته به schema جدید
// با خطای عمومی ۵۰۰ می‌بستند — مثل صفحه کارت‌ها.)
if (Installer::isInstalled()) {
    Installer::autoMigrate();
}

// HTMLهای داینامیک هرگز کش نشوند — بعد از ارتقا، مرورگر نباید صفحه قدیمی
// (با اسکریپت inline قدیمیِ بدون nonce) را از کش نشان دهد؛ همین باعث
// «دکمه‌ها بعد از آپدیت هم کار نمی‌کنند» می‌شد. فایل‌های استاتیک آزادند.
if (!preg_match('#^/assets/#', Http::path())) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

$router = new Router();

// Always available -----------------------------------------------------------
$router->any('/install', 'InstallController::index');
$router->get('/health', 'HealthController::index');
$router->get('/pay/{token}', 'PayController::show');
$router->get('/pay/{token}/status', 'PayController::status');
$router->post('/pay/{token}/claim', 'PayController::claim');

// Public API (v1) ------------------------------------------------------------
$router->post('/api/v1/payments/create', 'ApiController::createPayment');      // store auth (Woo)
$router->get('/api/v1/payments/{token}', 'ApiController::getPayment');         // store auth (Woo)
$router->post('/api/v1/auth/pair', 'ApiController::pair');                     // public, rate-limited
$router->post('/api/v1/auth/login', 'ApiController::login');                   // account login (app) — rate-limited
$router->get('/api/v1/account/stores', 'ApiController::accountStores');        // device auth — stores of account
$router->get('/api/v1/account/overview', 'ApiController::accountOverview');    // device auth — dashboard (store|account)
$router->get('/api/v1/account/transactions', 'ApiController::accountTransactions'); // device auth — filtered list
$router->get('/api/v1/account/transactions/detail', 'ApiController::accountTransactionDetail'); // device auth
$router->post('/api/v1/devices/register', 'ApiController::registerDevice');    // device auth
$router->post('/api/v1/devices/heartbeat', 'ApiController::heartbeat');        // device auth
$router->get('/api/v1/devices/config', 'ApiController::deviceConfig');     // device auth (card/sender/parser sync)
$router->get('/api/v1/devices/cards', 'ApiController::deviceCards');       // device auth (card manager)
$router->get('/api/v1/devices/payevents', 'ApiController::paymentEvents'); // device auth (app notifications)
$router->post('/api/v1/devices/cards/save', 'ApiController::deviceCardSave');   // device auth
$router->post('/api/v1/devices/cards/action', 'ApiController::deviceCardAction'); // device auth
$router->post('/api/v1/devices/analyze', 'ApiController::deviceAnalyze');   // device auth (same engine as panel)
$router->post('/api/v1/devices/rules/save', 'ApiController::deviceRuleSave');    // device auth
$router->post('/api/v1/devices/rules/action', 'ApiController::deviceRuleAction'); // device auth
$router->post('/api/v1/devices/test', 'ApiController::deviceTest');         // device auth
$router->get('/api/v1/devices/pending', 'ApiController::devicePending');   // device auth (manual confirm list)
$router->post('/api/v1/devices/confirm', 'ApiController::deviceConfirm');  // device auth (manual confirm)
$router->post('/api/v1/devices/attach', 'ApiController::deviceAttach');    // device auth (manual attach)
$router->post('/api/v1/devices/dismiss', 'ApiController::deviceDismiss');  // device auth (دور ۲۵ — حذف از بررسی‌ها)
$router->post('/api/v1/devices/close', 'ApiController::deviceClose');      // device auth (دور ۲۵ — بستن پرداخت باز + لغو در ووکامرس)
$router->post('/api/v1/transactions', 'ApiController::uploadTransactions');    // device auth (active)
$router->get('/api/v1/store/status', 'ApiController::storeStatus');            // device or store auth
$router->post('/api/v1/webhooks/woocommerce', 'ApiController::wooWebhook');    // store auth
$router->post('/api/v1/test-connection', 'ApiController::testConnection');     // store auth

// Admin panel ----------------------------------------------------------------
$router->any('/admin/login', 'AuthController::login');
$router->any('/admin/login/2fa', 'TwoFactorController::verify');
$router->get('/admin/logout', 'AuthController::logout');
$router->post('/admin/logout', 'AuthController::logout');
$router->get('/admin', 'DashboardController::index');
$router->get('/', static function (): never {
    if (!Installer::isInstalled()) {
        Http::redirect(Config::url('install'));
    }
    Http::redirect(Session::isLoggedIn() ? Config::url('admin') : Config::url('admin/login'));
});

$router->get('/admin/payments', 'PaymentsController::index');
$router->get('/admin/payments/{id}', 'PaymentsController::detail');
$router->post('/admin/payments/action', 'PaymentsController::action');

$router->get('/admin/transactions', 'TransactionsController::index');
$router->get('/admin/transactions/{id}/raw', 'TransactionsController::raw');

$router->get('/admin/devices', 'DevicesController::index');
$router->post('/admin/devices/new-code', 'DevicesController::newCode');
$router->post('/admin/devices/action', 'DevicesController::action');

// چندفروشگاهی (دور ۲۳) — فروشگاه‌های حساب + تیم
$router->get('/admin/stores', 'StoresController::index');
$router->post('/admin/stores/save', 'StoresController::save');
$router->post('/admin/stores/update', 'StoresController::update');
$router->post('/admin/stores/switch', 'StoresController::switchTo');
$router->get('/admin/team', 'TeamController::index');
$router->post('/admin/team/add', 'TeamController::add');
$router->post('/admin/team/update', 'TeamController::update');

// دور ۲۹ — ورود دومرحله‌ای (TOTP + کدهای بازیابی)
$router->get('/admin/2fa', 'TwoFactorController::index');
$router->post('/admin/2fa/enroll', 'TwoFactorController::enroll');
$router->post('/admin/2fa/activate', 'TwoFactorController::activate');
$router->post('/admin/2fa/disable', 'TwoFactorController::disable');
$router->post('/admin/2fa/recovery', 'TwoFactorController::recovery');
$router->post('/admin/2fa/hide', 'TwoFactorController::hide');
$router->post('/admin/2fa/ack-reset', 'TwoFactorController::ackReset');
$router->get('/admin/2fa/codes.txt', 'TwoFactorController::download');

$router->get('/admin/cards', 'CardsController::index');
$router->post('/admin/cards/save', 'CardsController::save');
$router->post('/admin/cards/action', 'CardsController::action');
$router->post('/admin/cards/analyze', 'CardsController::analyze');
$router->post('/admin/cards/build-rule', 'CardsController::buildRule');
$router->post('/admin/cards/test', 'CardsController::test');

$router->get('/admin/settings', 'SettingsController::index');
$router->post('/admin/settings/save', 'SettingsController::save');
$router->post('/admin/settings/action', 'SettingsController::action');
$router->post('/admin/password', 'SystemController::password');

$router->get('/admin/diagnostics', 'SystemController::diagnostics');
$router->post('/admin/diagnostics/run', 'SystemController::runDiagnostics');
$router->post('/admin/diagnostics/repair-db', 'SystemController::repairDb');
$router->get('/admin/logs', 'SystemController::logs');
$router->get('/admin/testtools', 'SystemController::testTools');
$router->post('/admin/testtools/action', 'SystemController::testAction');
$router->get('/admin/about', 'SystemController::about');

$router->dispatch();
