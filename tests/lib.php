<?php
/**
 * Peakopay — tiny dependency-free test harness (works on any PHP 8 host).
 */
declare(strict_types=1);

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0, 'failures' => []];

function t(string $name, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['__t']['pass']++;
        fwrite(STDOUT, "  ✓ {$name}\n");
    } else {
        $GLOBALS['__t']['fail']++;
        $GLOBALS['__t']['failures'][] = $name . ($detail !== '' ? " — {$detail}" : '');
        fwrite(STDOUT, "  ✗ {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n");
    }
}

function eq(string $name, mixed $expected, mixed $actual): void
{
    t($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function throws(string $name, string $class, callable $fn): void
{
    try {
        $fn();
        t($name, false, 'no exception thrown');
    } catch (Throwable $e) {
        t($name, $e instanceof $class, 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
    }
}

function section(string $title): void
{
    fwrite(STDOUT, "\n== {$title} ==\n");
}

function test_summary(): int
{
    $t = $GLOBALS['__t'];
    fwrite(STDOUT, "\n-----------------------------------------\n");
    fwrite(STDOUT, 'PASS: ' . $t['pass'] . '   FAIL: ' . $t['fail'] . "\n");
    if ($t['failures'] !== []) {
        fwrite(STDOUT, "\nFailed tests:\n");
        foreach ($t['failures'] as $f) {
            fwrite(STDOUT, "  - {$f}\n");
        }
    }
    return $t['fail'] === 0 ? 0 : 1;
}
