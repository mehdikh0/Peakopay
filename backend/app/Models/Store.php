<?php
/**
 * Peakopay — Store (multi-store since 2.0: every store belongs to an account).
 */
declare(strict_types=1);

final class Store
{
    public static function get(?int $id = null): ?array
    {
        if ($id !== null) {
            return DB::one('SELECT * FROM stores WHERE id = ?', [$id]);
        }
        return DB::one('SELECT * FROM stores ORDER BY id LIMIT 1');
    }

    public static function create(
        string $name,
        string $url,
        string $phone,
        string $telegram,
        string $currency = 'IRT',
        string $timezone = 'Asia/Tehran',
        ?int $accountId = null,
    ): int {
        $publicId = 'st_' . Crypto::randomHex(4);
        return DB::insert('stores', [
            'account_id'        => $accountId,
            'public_id'         => $publicId,
            'name'              => $name,
            'domain'            => Account::domainFromUrl($url),
            'connection_status' => 'pending',
            'url'               => $url,
            'support_phone'     => $phone,
            'support_telegram'  => $telegram,
            'currency'          => $currency,
            'timezone'          => $timezone,
            'created_at'        => DB::now(),
        ]);
    }

    /**
     * دور ۲۶ — لوگوی سایت فروشگاه (favicon/apple-touch-icon):
     * یک‌بار از صفحهٔ اول سایت ووکامرس استخراج و در تنظیمات همان فروشگاه کش
     * می‌شود؛ اپ آن را به‌جای نشان پیش‌فرض نشان می‌دهد. '' = لوگو نداریم.
     * خطا حداکثر یک‌بار در ساعت دوباره امتحان می‌شود (none:TIMESTAMP).
     */
    public static function logoUrl(int $storeId): string
    {
        $cached = Setting::get('site_logo_url', '', $storeId);
        if ($cached !== '') {
            if (str_starts_with($cached, 'none:')) {
                if ((int) substr($cached, 5) > time() - 3600) {
                    return '';
                }
            } else {
                return $cached;
            }
        }
        $site = rtrim(Setting::get('woo_site_url', '', $storeId), '/');
        if ($site === '' || !preg_match('#^https?://#i', $site)) {
            Setting::set('site_logo_url', 'none:' . time(), $storeId);
            return '';
        }
        // همان سیاست میزبان‌های مجاز خروجی وب‌هوک (اگر تنظیم شده باشد)
        $allowed = trim((string) Config::get('webhook_allowed_hosts', ''));
        if ($allowed !== '') {
            $host = strtolower((string) (parse_url($site, PHP_URL_HOST) ?: ''));
            $list = array_filter(array_map('trim', explode(',', strtolower($allowed))));
            if ($host === '' || ($list !== [] && !in_array($host, $list, true))) {
                Setting::set('site_logo_url', 'none:' . time(), $storeId);
                return '';
            }
        }
        $url = self::fetchIconUrl($site);
        Setting::set('site_logo_url', $url !== '' ? $url : 'none:' . time(), $storeId);
        return $url;
    }

    /** favicon سایت — apple-touch-icon (باکیفیت‌تر) وگرنه icon، و در نهایت /favicon.ico. */
    private static function fetchIconUrl(string $site): string
    {
        $html = self::httpGet($site . '/', 4);
        if ($html === '') {
            $html = self::httpGet($site, 4);
        }
        if ($html === '') {
            return ''; // سایت در دسترس نیست — لوگویی نداریم (ساعت بعد دوباره امتحان می‌شود)
        }
        foreach (['apple-touch-icon', 'icon', 'shortcut icon'] as $rel) {
                // <link rel="..." href="...">
                if (preg_match('#<link\b[^>]*rel=["\']' . preg_quote($rel, '#') . '["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m)
                    || preg_match('#<link\b[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']' . preg_quote($rel, '#') . '["\']#i', $html, $m)) {
                    if (trim($m[1]) !== '') {
                        return self::absoluteUrl(trim($m[1]), $site);
                    }
                }
        }
        return $site . '/favicon.ico'; // HTML آمد ولی تگ آیکون نداشت — راه کلی
    }

    private static function absoluteUrl(string $href, string $site): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p    = parse_url($site);
        $base = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
        if (isset($p['port'])) {
            $base .= ':' . $p['port'];
        }
        if ($href === '') {
            return $base . '/';
        }
        if (str_starts_with($href, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . $href;
        }
        return $base . '/' . ltrim($href, '/');
    }

    /** GET کوتاه‌مدت — فقط http/https و حداکثر ~800KB. */
    private static function httpGet(string $url, int $timeout): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 2,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout + 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Peakopay-Logo/1.0',
                CURLOPT_MAXFILESIZE    => 800 * 1024,
            ]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($code >= 200 && $code < 300) ? $body : '';
        }
        $ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'Peakopay-Logo/1.0', 'max_redirects' => 2]]);
        $body = @file_get_contents($url, false, $ctx, 0, 800 * 1024);
        return is_string($body) ? $body : '';
    }

    public static function update(int $id, array $fields): void
    {
        $allowed = ['name', 'url', 'support_phone', 'support_telegram', 'currency', 'timezone', 'domain', 'connection_status'];
        $data    = array_intersect_key($fields, array_flip($allowed));
        if (isset($data['url'])) {
            $data['domain'] = Account::domainFromUrl((string) $data['url']);
        }
        if ($data !== []) {
            DB::update('stores', $data, 'id = ?', [$id]);
        }
    }

    /**
     * فروشگاهِ جاریِ پنل — «آگاه از نشست و عضویت» (دور ۲۳):
     * ۱) اگر نشست فروشگاه فعال دارد و کاربر عضو آن است → همان
     * ۲) وگرنه اولین فروشگاهِ عضویت کاربر
     * ۳) fallback قدیمی: اولین فروشگاه (نصب‌کننده/کرون/صفحه پرداخت بدون نشست)
     * هرگز فروشگاهِ غیرعضو برگردانده نمی‌شود — مرز tenant-isolation در پنل همین‌جاست.
     */
    public static function current(): array
    {
        static $row = null;
        if ($row !== null) {
            return $row;
        }
        $row = [];
        try {
            $uid = (PHP_SAPI === 'cli') ? null : Session::userId();
            if ($uid !== null) {
                $user = User::findOrNull($uid);
                if ($user !== null && (int) ($user['is_active'] ?? 1) === 1) {
                    $memberships = StoreMember::storesOfUser($uid);
                    if ($memberships !== []) {
                        $active = Session::activeStoreId();
                        if ($active !== null && isset($memberships[$active])) {
                            $s = self::get($active);
                            if ($s !== null) {
                                $row = $s;
                                return $row;
                            }
                        }
                        $s = self::get((int) array_key_first($memberships));
                        if ($s !== null) {
                            $row = $s;
                            return $row;
                        }
                    }
                }
            }
        } catch (Throwable) {
            // نشست/DB در دسترس نیست (نصب اولیه، کرون) — fallback پایین
        }
        $row = self::get() ?? [];
        return $row;
    }
}
