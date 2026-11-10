<?php
/**
 * Peakopay — maintenance cron (cPanel-friendly).
 *
 * ⚠️ این اسکریپت برای «کارهای نگهداری» است؛ تطبیق و تأیید پرداخت‌ها
 *    هرگز به cron وابسته نیست و همان لحظه (in-request) انجام می‌شود.
 *
 * دو روش اجرا (هر دو بدون SSH):
 *
 * ۱) cPanel → Cron Jobs (پیشنهادی — هر ۱۵ دقیقه):
 *        php -q /home/USERNAME/public_html/pay/cron.php
 *
 * ۲) HTTP (اگر هاست اجازه cron نمی‌دهد):
 *        https://pay.example.com/cron.php?token=CRON_TOKEN
 *    توکن را از پنل مدیریت ← تنظیمات ← سیستم بردارید.
 *
 * کارهای انجام‌شده:
 *   - انقضای پرداخت‌های سرآمده (+ ارسال وب‌هوک payment.expired به ووکامرس)
 *   - پاک‌سازی: کلیدهای idempotency قدیمی، کدهای همراه‌سازی منقضی،
 *     رویدادهای وب‌هوک قدیمی، محدودیت‌های نرخ (rate limit) قدیمی
 *   - پاک‌سازی لاگ‌های قدیمی (بیش از ۳۰ روز)
 */
declare(strict_types=1);

define('HP_CRON', true);

require __DIR__ . '/app/bootstrap.php';

$respond = static function (array $payload, int $status = 200): void {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
};

// ── Access control ──────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    // HTTP: require the per-install cron token (hash_equals, timing-safe)
    $token    = trim((string) ($_GET['token'] ?? ''));
    $expected = '';
    try {
        // دور ۲۳ — توکن کرون در دامنهٔ «نصب» است (install_meta)؛ کرون یکجا برای
        // همهٔ فروشگاه‌ها اجرا می‌شود. برای نصب‌های قدیمی‌تر که توکن را در سطح
        // فروشگاه ذخیره کرده بودند، همان مقدار قبلی پذیرفته می‌شود.
        $expected = Setting::installGet('cron_token');
        if ($expected === '') {
            $expected = (string) (DB::val(
                "SELECT svalue FROM settings WHERE skey = 'cron_token' AND store_id > 0 ORDER BY store_id LIMIT 1"
            ) ?? '');
        }
    } catch (Throwable) {
        // settings table missing → not installed
    }
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        $respond(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => 'cron token invalid or missing']], 403);
        exit;
    }
    // gentle flood control for HTTP mode
    if (!RateLimiter::allow('cron:http', 20, 3600)) {
        $respond(['ok' => false, 'error' => ['code' => 'rate_limited', 'message' => 'too many cron calls']], 429);
        exit;
    }
}

// ── Must have a working database configuration ─────────────────────────────
if ((string) Config::get('db.name', '') === '') {
    $respond(['ok' => false, 'error' => ['code' => 'not_configured', 'message' => 'Database not configured — run the installer first.']]);
    exit;
}

// خودترمیمی دیتابیس (دور ۱۸) — مثل index.php؛ برای هاست‌هایی که cron دارند
// حتی اگر وب‌سرور هنوز درخواستی نخورده باشد، schema همگام می‌شود.
Installer::autoMigrate();

$start   = microtime(true);
$results = [];

try {
    // 1) Expire stale pending payments (webhooks included — same as lazy path)
    $results['expired_payments'] = Payment::expireStale();

    // 2) Housekeeping
    RateLimiter::gc();
    $results['idempotency_purged'] = DB::q(
        'DELETE FROM idempotency_keys WHERE created_at < ?',
        [gmdate('Y-m-d H:i:s', time() - 86400)]
    )->rowCount();
    $results['pairing_codes_purged'] = DB::q(
        'DELETE FROM pairing_codes WHERE expires_at < ?',
        [gmdate('Y-m-d H:i:s', time() - 86400)]
    )->rowCount();
    $results['webhook_events_purged'] = DB::q(
        'DELETE FROM webhook_events WHERE created_at < ?',
        [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]
    )->rowCount();
    $results['notifications_purged'] = DB::q(
        'DELETE FROM notifications WHERE created_at < ?',
        [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]
    )->rowCount();

    // 3) Purge log files older than 30 days
    $results['logs_purged'] = 0;
    foreach (glob(HP_BASE . '/storage/logs/*.log') ?: [] as $logFile) {
        if (is_file($logFile) && filemtime($logFile) < time() - 30 * 86400) {
            @unlink($logFile);
            $results['logs_purged']++;
        }
    }
} catch (Throwable $e) {
    Log::error('cron failure: ' . $e->getMessage());
    $respond([
        'ok'    => false,
        'error' => ['code' => 'cron_failed', 'message' => 'Cron maintenance failed — see storage/logs for details.', 'ref' => HP_REQUEST_ID],
    ], 500);
    exit;
}

$respond([
    'ok'   => true,
    'data' => $results + ['took_ms' => (int) round((microtime(true) - $start) * 1000)],
]);
