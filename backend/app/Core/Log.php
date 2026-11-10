<?php
/**
 * Peakopay — File logger (storage/logs).
 *
 * Everything sensitive is redacted before it hits disk: card-like digit runs,
 * API keys (hpk_…), device secrets (hpd_…), long hex strings and password
 * fields in contexts.
 */
declare(strict_types=1);

final class Log
{
    private static function file(string $channel): string
    {
        return HP_BASE . '/storage/logs/' . $channel . '-' . gmdate('Ymd') . '.log';
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::write('app', 'INFO', $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::write('app', 'WARN', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('app', 'ERROR', $msg, $ctx);
    }

    public static function fatal(Throwable $e): void
    {
        self::write('errors', 'FATAL', get_class($e) . ': ' . $e->getMessage(), [
            'file'  => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    private static function write(string $channel, string $level, string $msg, array $ctx): void
    {
        $line = sprintf(
            "[%s] [%s] [%s] %s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            HP_REQUEST_ID,
            self::redact($msg),
            $ctx === [] ? '' : ' ' . self::redactCtx($ctx)
        );
        if (@file_put_contents(self::file($channel), $line, FILE_APPEND | LOCK_EX) === false) {
            // storage/logs not writable (e.g. wrong permissions after upload):
            // fall back to the PHP system error log so nothing is lost.
            error_log('[Peakopay ' . $channel . '] ' . trim($line));
        }
    }

    /** Mask card-like digit sequences and known token shapes. */
    public static function redact(string $msg): string
    {
        $msg = preg_replace('#\b(hpk|hpd)_[A-Za-z0-9]{8,}\b#', '$1_***', $msg) ?? $msg;
        $msg = preg_replace('#\b\d{16,19}\b#', '[card]', $msg) ?? $msg;
        $msg = preg_replace('#\b[0-9a-f]{32,64}\b#', '[hex]', $msg) ?? $msg;
        $msg = preg_replace('#(password|passwd|secret|token|api_key)"?\s*[:=]\s*"[^"]*"#i', '$1="***"', $msg) ?? $msg;
        return $msg;
    }

    private static function redactCtx(array $ctx): string
    {
        foreach ($ctx as $k => $v) {
            if (is_string($v)) {
                $ctx[$k] = self::redact(mb_substr($v, 0, 500));
            }
        }
        $json = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }
}
