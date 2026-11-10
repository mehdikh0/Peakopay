<?php
/**
 * Peakopay — test-suite entry point (no Composer, no PHPUnit).
 *
 * Usage:
 *   php tests/run.php          # unit tests + full HTTP end-to-end flow
 *   php tests/run.php unit     # unit tests only
 *   php tests/run.php e2e      # end-to-end only
 *
 * Requires a MySQL/MariaDB server; configure with env vars:
 *   HP_TEST_DB (default peakopay_test), HP_TEST_DB_USER (hp), HP_TEST_DB_PASS (hp_test_pass)
 */
declare(strict_types=1);

$only = $argv[1] ?? 'all';

if ($only === 'all' || $only === 'unit') {
    require __DIR__ . '/unit_db_init.php';
    foreach (glob(__DIR__ . '/unit/*.php') ?: [] as $file) {
        require $file;
    }
}

$exit = 0;
if ($only === 'unit') {
    $exit = test_summary();
} else {
    require __DIR__ . '/e2e.php';
    $exit = e2e_summary();
}

// stop mock server if we started it
$pidFile = sys_get_temp_dir() . '/hp_mock_woo.pid';
if (is_file($pidFile)) {
    $pid = (int) file_get_contents($pidFile);
    if ($pid > 0) {
        exec('kill ' . $pid . ' 2>/dev/null');
    }
    @unlink($pidFile);
}
exit($exit);
