<?php
/**
 * Peakopay — Application bootstrap.
 *
 * Plain PHP, no Composer, no framework. This file loads every class in
 * app/{Core,Models,Services,Controllers}, loads configuration (.env optional
 * + config/config.php written by the installer) and installs friendly error
 * handling (raw errors are never shown to visitors).
 *
 * Bootstrap hardening rules (cPanel/LiteSpeed):
 *  1. Nothing in this file may fatal before the error handler is installed —
 *     a missing optional extension (e.g. mbstring) must reach the installer's
 *     requirements page, never a blank HTTP 500.
 *  2. PHP warnings/notices are LOGGED, never converted to exceptions — on
 *     shared hosting they are frequently environmental noise (LiteSpeed,
 *     permissions, session paths). Only genuine errors throw.
 */
declare(strict_types=1);

if (!defined('HP_BASE')) {
    define('HP_BASE', dirname(__DIR__));
}
define('HP_VERSION', '2.2.0');
define('HP_START_TIME', microtime(true));
define('HP_REQUEST_ID', 'HP-' . gmdate('Ymd') . '-' . bin2hex(random_bytes(4)));

// All storage is UTC; the UI converts to the store timezone for display.
date_default_timezone_set('UTC');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// Best-effort: make sure the log directory exists before we point error_log
// at it (empty directories can be lost during manual FTP uploads).
if (!is_dir(HP_BASE . '/storage/logs')) {
    @mkdir(HP_BASE . '/storage/logs', 0755, true);
}
if (!is_dir(HP_BASE . '/storage/cache')) {
    @mkdir(HP_BASE . '/storage/cache', 0755, true);
}

// ---- Load application classes (alphabetical order is fine: classes only ----
// ---- reference each other at runtime, never at load time).               ----
foreach (['Core', 'Models', 'Services', 'Controllers', 'Controllers/Admin'] as $group) {
    foreach (glob(HP_BASE . '/app/' . $group . '/*.php') ?: [] as $hpFile) {
        require_once $hpFile;
    }
}
unset($hpFile, $group);

// ---- Configuration: defaults ← .env (optional) ← config/config.php --------
// The installer writes config/config.php; .env is an optional ops override
// (see .env.example). Installed config.php always wins over .env for
// database/app_key values; ops switches (SESSION_SECURE,
// WEBHOOK_ALLOWED_HOSTS) always win from .env when present.
Config::load(HP_BASE . '/config/config.php');

// ---- Error handling ---------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', Config::get('debug') ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(HP_BASE . '/storage/logs') && is_writable(HP_BASE . '/storage/logs')) {
    ini_set('error_log', HP_BASE . '/storage/logs/php-errors.log');
}

// Warnings/notices/deprecation → logged and suppressed (never a blank 500).
// E_USER_ERROR / E_RECOVERABLE_ERROR → exceptions (genuine bugs).
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false; // @-suppressed
    }
    if ($no === E_USER_ERROR || $no === E_RECOVERABLE_ERROR) {
        throw new ErrorException($str, 0, $no, $file, $line);
    }
    error_log(sprintf('[php:%d] %s in %s:%d (ref %s)', $no, $str, $file, $line, HP_REQUEST_ID));
    return true; // do not bubble to PHP's default handler
});

set_exception_handler(static function (Throwable $e): void {
    Http::handleFatal($e);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Http::handleFatal(new ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
    }
});
