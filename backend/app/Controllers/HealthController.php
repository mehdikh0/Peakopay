<?php
/**
 * Peakopay — /health endpoint. Safe, non-sensitive status information only.
 */
declare(strict_types=1);

final class HealthController
{
    public static function index(): void
    {
        $checks = [
            'installed' => Installer::isInstalled(),
            'php'       => PHP_VERSION,
            'https'     => Http::isHttps(),
            'time'      => gmdate('c'),
            'database'  => 'down',
            'extensions'=> [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'openssl'   => extension_loaded('openssl'),
                'sodium'    => extension_loaded('sodium'),
                'curl'      => extension_loaded('curl'),
                'mbstring'  => extension_loaded('mbstring'),
                'json'      => function_exists('json_encode'),
            ],
            'writable'  => [
                'storage/logs' => is_dir(HP_BASE . '/storage/logs') && is_writable(HP_BASE . '/storage/logs'),
                'config'       => is_dir(HP_BASE . '/config') && is_writable(HP_BASE . '/config'),
            ],
        ];
        try {
            DB::val('SELECT 1');
            $checks['database'] = 'up';
            $checks['migrations_pending'] = max(0, count(glob(HP_BASE . '/migrations/*.sql') ?: []) - (int) DB::val('SELECT COUNT(*) FROM migrations'));
            try {
                $wooUrl = Setting::get('woo_site_url');
                $checks['woocommerce'] = $wooUrl === '' ? 'not_configured' : 'configured';
            } catch (Throwable) {
                $checks['woocommerce'] = 'unknown';
            }
        } catch (Throwable) {
            $checks['migrations_pending'] = null;
            $checks['woocommerce'] = 'unknown';
        }
        $checks['db_name_debug'] = (string) Config::get('db.name', '(none)');
        $ok = $checks['database'] === 'up' && $checks['installed'];
        Http::json([
            'ok'       => $ok,
            'app'      => ['name' => 'Peakopay', 'version' => HP_VERSION],
            'status'   => $ok ? 'healthy' : ($checks['database'] === 'up' ? 'installing' : 'degraded'),
            'checks'   => $checks,
        ], $ok ? 200 : 503);
    }
}
