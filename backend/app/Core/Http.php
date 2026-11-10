<?php
/**
 * Peakopay — HTTP request/response helpers.
 */
declare(strict_types=1);

final class Http
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /** Sub-directory base path of the app, e.g. "" or "/pay". */
    public static function base(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir    = rtrim(dirname($script), '/');
        $base   = $dir === '.' ? '' : $dir;
        return $base;
    }

    /** Current request path relative to the app base, always starts with "/". */
    public static function path(): string
    {
        $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = self::base();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim($uri, '/');
        return $uri === '//' ? '/' : $uri;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        return false;
    }

    public static function scheme(): string
    {
        return self::isHttps() ? 'https' : 'http';
    }

    public static function host(): string
    {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    /** Best-effort base URL derived from the current request. */
    public static function guessBaseUrl(): string
    {
        return self::scheme() . '://' . self::host() . self::base();
    }

    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : null;
    }

    public static function rawBody(): string
    {
        static $body = null;
        if ($body === null) {
            $body = (string) file_get_contents('php://input');
        }
        return $body;
    }

    /** Decoded JSON body. Returns [] for empty body; null when invalid. */
    public static function jsonInput(): ?array
    {
        $raw = trim(self::rawBody());
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public static function wantsJson(): bool
    {
        $path    = self::path();
        $accept  = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_starts_with($path, '/api')
            || str_starts_with($path, '/health')
            || str_contains($accept, 'application/json')
            || strtolower((string) self::header('X-Requested-With')) === 'xmlhttprequest';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    // ------------------------------------------------------------- responses

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function apiSuccess(array $data = [], int $status = 200): never
    {
        self::json(['ok' => true, 'data' => $data], $status);
    }

    public static function apiError(string $code, string $message, int $status = 400): never
    {
        self::json(['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'ref' => HP_REQUEST_ID]], $status);
    }

    public static function redirect(string $url, int $code = 302): never
    {
        header('Location: ' . $url, true, $code);
        exit;
    }

    public static function notFound(): never
    {
        if (self::wantsJson()) {
            self::apiError('not_found', 'منبع درخواستی یافت نشد.', 404);
        }
        http_response_code(404);
        View::renderPlain('partials/404', ['title' => 'صفحه یافت نشد']);
        exit;
    }

    /** Nonce یک‌بارمصرف برای اسکریپت‌های inline ویوها (هر درخواست جدید). */
    public static function cspNonce(): string
    {
        static $nonce = '';
        if ($nonce === '') {
            $nonce = bin2hex(random_bytes(16));
        }
        return $nonce;
    }

    public static function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        // script-src با nonce: فقط اسکریپت‌های inline‌ای که nonce همین درخواست را دارند اجرا می‌شوند
        // (سخت‌گیرانه‌تر از unsafe-inline). style-src چون ویوها attribute استایل دارند unsafe-inline است.
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-" . self::cspNonce() . "'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'");
    }

    /**
     * Last-resort handler: logs the real error, shows visitors a friendly
     * Persian message with a tracking reference (never raw PHP errors).
     */
    public static function handleFatal(Throwable $e): void
    {
        try {
            Log::fatal($e);
        } catch (Throwable) {
            // logging must never break the error page
        }
        // Fallback: always reach the PHP error log too (cPanel → Errors log),
        // even when storage/logs is not writable.
        if (PHP_SAPI !== 'cli') {
            error_log('[Peakopay fatal ' . HP_REQUEST_ID . '] ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
        $debug = (bool) Config::get('debug');
        if (self::wantsJson()) {
            $msg = $debug ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : 'خطای داخلی سرور';
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'    => false,
                'error' => [
                    'code'    => 'server_error',
                    'message' => $msg,
                    'ref'     => HP_REQUEST_ID,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        $detail = $debug ? '<pre dir="ltr">' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>' : '';
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>خطا — پیکو پی</title></head>'
            . '<body style="font-family:Vazirmatn,Tahoma,sans-serif;background:#f4f6fb;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0">'
            . '<div style="background:#fff;border-radius:20px;padding:40px;max-width:420px;text-align:center;box-shadow:0 10px 40px rgba(80,80,160,.12)">'
            . '<div style="font-size:44px">⚠️</div>'
            . '<h2 style="color:#1e2438;margin:12px 0">خطایی در ارتباط با سرویس پرداخت رخ داد</h2>'
            . '<p style="color:#5c6480;line-height:2">لطفاً چند لحظه دیگر دوباره تلاش کنید یا با پشتیبانی فروشگاه تماس بگیرید.</p>'
            . '<p style="direction:rtl">کد پیگیری: <b style="direction:ltr;display:inline-block">' . htmlspecialchars(HP_REQUEST_ID) . '</b></p>'
            . '<p style="color:#9aa3bd;font-size:12px;direction:rtl;line-height:1.9">مدیر فروشگاه: جزئیات این خطا در فایل لاگ <code dir="ltr">storage/logs/errors-*.log</code> (یا «گزارش‌ها ← لاگ‌ها» در پنل پیکو پی و لاگ خطای PHP در cPanel) ثبت شده است.</p>'
            . $detail
            . '</div></body></html>';
        exit;
    }
}
