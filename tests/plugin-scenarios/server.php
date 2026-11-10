<?php
/**
 * Scenario dispatcher for `php -S` (tests only):  /?s=<scenario>
 * Each scenario is a self-contained PHP file that boots the WP stubs,
 * includes the plugin and prints machine-readable markers.
 */
declare(strict_types=1);

$s = (string) ($_GET['s'] ?? '');
if (!preg_match('/^\d{2}[a-z]?_[a-z0-9_]+$/', $s) || !is_file(__DIR__ . '/' . $s . '.php')) {
    http_response_code(404);
    echo "unknown scenario\n";
    exit;
}
require __DIR__ . '/' . $s . '.php';
