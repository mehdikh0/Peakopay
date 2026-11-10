<?php
/**
 * Peakopay — DB-backed fixed-window rate limiter.
 *
 * Used for: admin login protection, pairing attempts, payment claims and
 * per-device API limits. Fails open (with a log line) if the DB is briefly
 * unavailable so a limiter hiccup can never take the payment API down.
 */
declare(strict_types=1);

final class RateLimiter
{
    /** Increment the counter for $bucket and report whether it is still allowed. */
    public static function allow(string $bucket, int $max, int $windowSeconds): bool
    {
        try {
            $now = time();
            $row = DB::one('SELECT * FROM rate_limits WHERE bucket = ?', [$bucket]);
            if ($row === null) {
                DB::q(
                    'INSERT INTO rate_limits (bucket, hits, window_start, blocked_until) VALUES (?, 1, ?, NULL)',
                    [$bucket, DB::now()]
                );
                return true;
            }
            $start = strtotime((string) $row['window_start']); // PHP tz is UTC
            if ((int) $row['hits'] >= $max && ($now - $start) < $windowSeconds) {
                return false;
            }
            if (($now - $start) >= $windowSeconds) {
                DB::q('UPDATE rate_limits SET hits = 1, window_start = ?, blocked_until = NULL WHERE bucket = ?', [DB::now(), $bucket]);
                return true;
            }
            DB::q('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
            return true;
        } catch (Throwable $e) {
            Log::warn('rate limiter unavailable: ' . $e->getMessage());
            return true;
        }
    }

    public static function clear(string $bucket): void
    {
        try {
            DB::q('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
        } catch (Throwable $e) {
            Log::warn('rate limiter clear failed: ' . $e->getMessage());
        }
    }

    /** Opportunistic cleanup of stale buckets (called rarely, never required). */
    public static function gc(int $olderThanSeconds = 86400): void
    {
        try {
            DB::q('DELETE FROM rate_limits WHERE window_start < ?', [gmdate('Y-m-d H:i:s', time() - $olderThanSeconds)]);
        } catch (Throwable $e) {
            Log::warn('rate limiter gc failed: ' . $e->getMessage());
        }
    }
}
