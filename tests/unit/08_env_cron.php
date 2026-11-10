<?php
/**
 * .env configuration loader + maintenance cron (cron.php) tests.
 */
declare(strict_types=1);

section('.env loader (Config)');

$tmp = sys_get_temp_dir() . '/hp_envtest_' . getmypid();
@mkdir($tmp . '/config', 0755, true);

$envFile = $tmp . '/.env';
file_put_contents($envFile, <<<TXT
# comment line
APP_ENV=debug
APP_URL=https://pay.peakora.ir/
APP_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa

DB_DSN="mysql:host=db.example.com;port=3307;dbname=ixddoaao_pay;charset=utf8mb4"
DB_USER=ixddoaao_pay
DB_PASSWORD='s3cr et!"x'

SESSION_SECURE=true
WEBHOOK_ALLOWED_HOSTS=shop.peakora.ir, other.example.com
TXT

);

$parsed = Config::parseEnvFile($envFile);
eq('parser reads APP_ENV', 'debug', $parsed['APP_ENV']);
eq('parser strips double quotes', 'mysql:host=db.example.com;port=3307;dbname=ixddoaao_pay;charset=utf8mb4', $parsed['DB_DSN']);
eq('parser strips single quotes with special chars', 's3cr et!"x', $parsed['DB_PASSWORD']);
eq('parser skips comments', true, !isset($parsed['# comment line']));

// full load precedence: defaults ← .env ← config.php
Config::reset();
Config::load($tmp . '/config/config.php'); // does not exist yet → .env drives
eq('APP_URL trailing slash trimmed', 'https://pay.peakora.ir', Config::get('base_url'));
eq('DB_DSN parsed into parts', 'db.example.com', Config::get('db.host'));
eq('DB_DSN port parsed', 3307, Config::get('db.port'));
eq('DB_DSN dbname parsed', 'ixddoaao_pay', Config::get('db.name'));
eq('APP_ENV=debug enables debug', true, Config::get('debug'));
eq('SESSION_SECURE honored', true, Config::get('session_secure'));
eq('WEBHOOK_ALLOWED_HOSTS honored', 'shop.peakora.ir, other.example.com', Config::get('webhook_allowed_hosts'));
eq('Config::env() exposes raw values', 'ixddoaao_pay', Config::env('DB_USER'));

// config.php (installer) overrides .env for installation data…
file_put_contents($tmp . '/config/config.php', '<?php return ["base_url" => "https://installed.example.com", "app_key" => "' . str_repeat('b', 64) . '", "db" => ["host" => "localhost", "port" => 3306, "name" => "installed_db", "user" => "u", "pass" => "", "charset" => "utf8mb4"]];');
Config::reset();
Config::load($tmp . '/config/config.php');
eq('installed config.php wins over .env (db)', 'installed_db', Config::get('db.name'));
eq('installed config.php wins over .env (base_url)', 'https://installed.example.com', Config::get('base_url'));
// …but ops switches always win from .env
eq('SESSION_SECURE still wins from .env', true, Config::get('session_secure'));

// restore the singleton to the real test config for the rest of the suite
Config::reset();
Config::load(HP_BASE . '/config/config.php');
@unlink($tmp . '/config/config.php');
@unlink($envFile);
@rmdir($tmp . '/config');
@rmdir($tmp);

section('Maintenance cron (cron.php via CLI)');

// unit_db_init wrote backend/config/config.php pointing at peakopay_test
$exec = PHP_BINARY . ' ' . escapeshellarg(HP_BASE . '/cron.php') . ' 2>&1';
$out = (string) shell_exec($exec);
$json = json_decode($out, true);
t('cron runs and returns JSON ok', is_array($json) && ($json['ok'] ?? false) === true);
t('cron reports maintenance counters', isset($json['data']['expired_payments']) && isset($json['data']['logs_purged']) && isset($json['data']['took_ms']));
t('cron output contains no secrets', !str_contains($out, 'hpk_') && !str_contains($out, 'hpd_') && !str_contains($out, 'whsec_'));
