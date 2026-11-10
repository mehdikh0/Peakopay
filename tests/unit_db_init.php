<?php
/**
 * Peakopay — unit-test bootstrap.
 * Resets the schema, creates a fresh store/admin/card/API key, and exposes
 * them via $GLOBALS['T'] so tests can build scenarios quickly.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

define('HP_BASE', dirname(__DIR__) . '/backend');

// ---- fresh test config (random app key, test DB) ----
$testDb = getenv('HP_TEST_DB') ?: 'peakopay_test';
$config = [
    'app_key'  => bin2hex(random_bytes(32)),
    'base_url' => 'http://127.0.0.1:8099',
    'debug'    => true,
    'db'       => [
        'host' => '127.0.0.1', 'port' => 3306, 'name' => $testDb,
        'user' => getenv('HP_TEST_DB_USER') ?: 'hp', 'pass' => getenv('HP_TEST_DB_PASS') ?: 'hp_test_pass',
        'charset' => 'utf8mb4',
    ],
];
@unlink(HP_BASE . '/storage/installed.lock');
file_put_contents(HP_BASE . '/config/config.php', '<?php return ' . var_export($config, true) . ";\n");
register_shutdown_function(static function (): void {
    @unlink(HP_BASE . '/config/config.php');
    @unlink(HP_BASE . '/storage/installed.lock');
});

require HP_BASE . '/app/bootstrap.php';

// ---- wipe all tables, then migrate ----
try {
    DB::q('SET FOREIGN_KEY_CHECKS = 0');
    foreach (DB::all('SHOW TABLES') as $row) {
        DB::q('DROP TABLE IF EXISTS `' . array_values($row)[0] . '`');
    }
    DB::q('SET FOREIGN_KEY_CHECKS = 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot reset test DB: " . $e->getMessage() . "\n(see tests/README: create the test database first)\n");
    exit(2);
}
Installer::migrate();

// ---- seed a store (with account — multi-store era) ----
$accountId = Account::create('حساب تست');
$storeId = Store::create('فروشگاه تست', 'http://127.0.0.1:8098', '09120000000', '@test', 'IRT', 'Asia/Tehran', $accountId);
DB::update('stores', ['connection_status' => 'connected'], 'id = ?', [$storeId]);
User::create($accountId, $storeId, 'مدیر تست', 'admin@test.local', 'secret-pass-123', 'owner');
$cardId = Card::add($storeId, [
    'bank_name' => 'بانک ملت',
    'holder'    => 'علی تستی',
    'number'    => '6219860000000019',
    'iban'      => 'IR820540102680020817909002',
    'account'   => '1234567890',
]);
$apiKey = ApiKey::issue($storeId, 'test');
Setting::set('woo_webhook_secret', 'whsec_test_0123456789abcdef');
Setting::set('woo_site_url', 'http://127.0.0.1:8098');
Setting::set('test_mode', '1');
@unlink(sys_get_temp_dir() . '/hp_mock_woo.log');
@unlink(sys_get_temp_dir() . '/hp_mock_woo_orders.json');
file_put_contents(sys_get_temp_dir() . '/hp_mock_woo.secret', 'whsec_test_0123456789abcdef');

// issue + approve a test device
[$code] = Device::issuePairingCode($storeId);
$creds  = Device::pair($code, 'گوشی تست', 'Pixel 8', '1.0.0', '14');
Device::setStatus($creds['id'], $storeId, 'active');

$GLOBALS['T'] = [
    'store_id'     => $storeId,
    'public_id'    => (string) DB::val('SELECT public_id FROM stores WHERE id = ?', [$storeId]),
    'card_id'      => $cardId,
    'api_key'      => $apiKey,
    'webhook_secret' => 'whsec_test_0123456789abcdef',
    'device'       => Device::find($creds['id']),
    'device_secret'=> $creds['secret'],
    'user_email'   => 'admin@test.local',
    'user_pass'    => 'secret-pass-123',
];
