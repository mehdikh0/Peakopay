<?php
/**
 * Router for `php -S` (tests only). Emulates the production root .htaccess:
 *   - 403 for sensitive paths (app/ config/ migrations/ storage/ docs/ tests/)
 *     and dotfiles (.env, .htaccess, …)
 *   - real files (assets/, cron.php, index.php) served directly
 *   - everything else routed through the front controller index.php
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');

// Sensitive directories → 403 (mirrors RewriteRule ^(app|config|…) [F])
if (preg_match('#^/(app|config|migrations|storage|docs|tests)(/|$)#', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n";
    exit;
}
// Dotfiles → 403 (mirrors RewriteRule (^|/)\. [F])
if (preg_match('#(^|/)\.#', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n";
    exit;
}

$file = realpath($root . $path);
if ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file) && $path !== '/index.php') {
    return false; // let the built-in server serve the static/PHP file
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
