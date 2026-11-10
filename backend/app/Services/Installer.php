<?php
/**
 * Peakopay — Web installer engine: requirements check, DB connect, migrations,
 * seeding, credentials generation, lock file.
 */
declare(strict_types=1);

final class Installer
{
    public const LOCK_FILE = HP_BASE . '/storage/installed.lock';
    public const REINSTALL_FILE = HP_BASE . '/storage/allow_reinstall';

    public static function isInstalled(): bool
    {
        return Config::exists() && is_file(self::LOCK_FILE);
    }

    /** Admin can explicitly re-open the installer for a short window. */
    public static function reinstallAllowed(): bool
    {
        if (!is_file(self::REINSTALL_FILE)) {
            return false;
        }
        $t = (int) @file_get_contents(self::REINSTALL_FILE);
        if ($t === 0 || $t < time() - 1800) { // 30-minute window
            @unlink(self::REINSTALL_FILE);
            return false;
        }
        return true;
    }

    public static function openReinstallWindow(): void
    {
        @file_put_contents(self::REINSTALL_FILE, (string) time());
        Audit::log('installer.reopen', 'reinstall window opened');
    }

    public static function lock(): void
    {
        @file_put_contents(self::LOCK_FILE, 'installed ' . gmdate('c') . ' ' . Crypto::randomHex(8));
        @unlink(self::REINSTALL_FILE);
    }

    // ---------------------------------------------------------- requirements

    /** @return array<int, array{name:string, ok:bool, required:bool, hint:string}> */
    public static function requirements(): array
    {
        $checks = [];

        $phpOk = PHP_VERSION_ID >= 80100;
        $checks[] = [
            'name' => 'نسخه PHP (حداقل ۸.۱ — پیشنهادی ۸.۲+)',
            'ok'   => $phpOk,
            'required' => true,
            'hint' => 'نسخه فعلی: ' . PHP_VERSION,
        ];
        foreach (['PDO', 'pdo_mysql', 'json', 'openssl', 'curl', 'mbstring'] as $ext) {
            $checks[] = [
                'name' => 'افزونه ' . strtoupper($ext),
                'ok'   => extension_loaded($ext),
                'required' => true,
                'hint' => extension_loaded($ext) ? 'فعال' : 'این افزونه الزامی است. در cPanel از «Select PHP Version» فعالش کنید.',
            ];
        }
        $sodium = extension_loaded('sodium');
        $checks[] = [
            'name' => 'افزونه Sodium (اختیاری)',
            'ok'   => $sodium,
            'required' => false,
            'hint' => $sodium
                ? 'فعال — رمزنگاری با Sodium انجام می‌شود.'
                : 'فعال نیست؛ مشکلی نیست، پیکو پی به‌صورت خودکار از AES-256-GCM (OpenSSL) استفاده می‌کند که همین الان فعال است.',
        ];
        foreach (['storage', 'storage/logs', 'config'] as $dir) {
            $path   = HP_BASE . '/' . $dir;
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $checks[] = [
                'name' => 'دسترسی نوشتن در /' . $dir,
                'ok'   => $writable,
                'required' => true,
                'hint' => $writable ? 'قابل نوشتن' : 'مجلد باید وجود داشته باشد و قابل نوشتن باشد (سطح دسترسی ۷۵۵ یا ۷۷۵).',
            ];
        }
        $https = Http::isHttps();
        $checks[] = [
            'name' => 'اتصال امن HTTPS',
            'ok'   => $https,
            'required' => false,
            'hint' => $https ? 'فعال' : 'ترجیحاً قبل از شروع فروش، برای دامنه گواهی SSL فعال کنید (در cPanel رایگان است). نصب ادامه می‌یابد.',
        ];
        return $checks;
    }

    public static function requirementsPassed(): bool
    {
        foreach (self::requirements() as $c) {
            if ($c['required'] && !$c['ok']) {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------- database

    /** Test a DB connection without saving anything. @return array{ok:bool,message:string} */
    public static function testDb(string $host, int $port, string $name, string $user, string $pass): array
    {
        if ($host === '' || $name === '' || $user === '') {
            return ['ok' => false, 'message' => 'هاست، نام دیتابیس و نام کاربری الزامی است.'];
        }
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 8,
            ]);
            $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            return ['ok' => true, 'message' => 'اتصال موفق — نسخه سرور: ' . $ver];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'اتصال ناموفق: ' . self::friendlyDbError($e)];
        }
    }

    private static function friendlyDbError(PDOException $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Access denied')) {
            return 'نام کاربری یا رمز عبور دیتابیس اشتباه است.';
        }
        if (str_contains($msg, 'Unknown database')) {
            return 'دیتابیس با این نام پیدا نشد؛ ابتدا در cPanel دیتابیس بسازید.';
        }
        if (str_contains($msg, 'Connection refused') || str_contains($msg, 'No connection could be made')) {
            return 'سرور دیتابیس پاسخ نداد؛ معمولاً در cPanel هاست باید localhost باشد.';
        }
        return $msg;
    }

    /**
     * Persist DB config + APP key into config/config.php and apply migrations.
     */
    public static function saveConfigAndMigrate(array $db, string $baseUrl): void
    {
        Config::write([
            'app_key'  => Crypto::generateKeyHex(),
            'base_url' => rtrim($baseUrl, '/'),
            'debug'    => false,
            'db'       => [
                'host' => $db['host'],
                'port' => (int) $db['port'],
                'name' => $db['name'],
                'user' => $db['user'],
                'pass' => $db['pass'],
                'charset' => 'utf8mb4',
            ],
        ]);
        // Config::write() keeps the in-memory config in sync (a re-require of the
        // file could serve a stale OPcache entry right after rewriting it).
        self::migrate();
    }

    /**
     * Apply pending migration files (idempotent, recorded in `migrations`).
     *
     * $force = true (دکمه «تعمیر پایگاه داده»): همه فایل‌ها دوباره اجرا می‌شوند حتی
     * اگر قبلاً ثبت شده باشند — چون ممکن است جدول‌ها بعداً به‌طور دستی حذف/خراب
     * شده باشند؛ اجرای روادار روی schema سالم بی‌ضرر است.
     */
    public static function migrate(bool $force = false): array
    {
        $done = [];
        // bootstrap table (safe on every run)
        DB::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(190) NOT NULL,
                applied_at DATETIME NOT NULL,
                hash CHAR(32) NOT NULL DEFAULT \'\',
                UNIQUE KEY uq_mig (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        // نصب‌های قدیمی: ستون hash را tolerate-shoden اضافه کن (اگر هست، نادیده گرفته می‌شود)
        self::execTolerant('ALTER TABLE migrations ADD COLUMN hash CHAR(32) NOT NULL DEFAULT \'\'');

        $applied = [];
        foreach (DB::all('SELECT filename, hash FROM migrations') as $row) {
            $applied[(string) $row['filename']] = (string) $row['hash'];
        }
        $files = glob(HP_BASE . '/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            $hash = md5((string) file_get_contents($file));
            // «در انتظار» = هرگز اعمال نشده، یا محتوای فایل از زمان اعمال تغییر کرده
            // (رفع «ارتقا با جایگزینی فایل‌ها» — دور ۱۸). حالت force همه را اجرا می‌کند.
            if (!$force && isset($applied[$name]) && $applied[$name] === $hash) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            // NOTE: DDL statements implicitly commit in MySQL, so no wrapping
            // transaction here — each statement runs and commits on its own.
            foreach (self::splitSql($sql) as $stmt) {
                self::execTolerant($stmt);
            }
            if (isset($applied[$name])) {
                DB::update('migrations', ['hash' => $hash, 'applied_at' => DB::now()], 'filename = ?', [$name]);
            } else {
                DB::insertIgnore('migrations', ['filename' => $name, 'applied_at' => DB::now(), 'hash' => $hash]);
            }
            $done[] = $name;
        }
        // چندفروشگاهی (دور ۲۳): پس از اعمال مهاجرت‌ها، داده‌های موجود را به
        // حساب‌ها متصل کن (idempotent — نصب تک‌فروشگاهی = یک حساب با یک مالک).
        try {
            Account::backfill();
        } catch (Throwable $e) {
            error_log('[migrate] account backfill failed: ' . $e->getMessage() . ' (ref ' . HP_REQUEST_ID . ')');
        }
        return $done;
    }

    /**
     * اجرای یک دستور DDL به‌صورت «روادار»: خطاهای «از قبل موجود» (جدول/ستون/کلید
     * تکراری، حذفِ چیزی که نیست) نادیده گرفته می‌شوند تا اجرای مجددِ یک مهاجرت
     * روی دیتابیس سالم بی‌ضرر باشد (idempotent). بقیه خطاها پرتاب می‌شوند.
     */
    private static function execTolerant(string $stmt): void
    {
        try {
            DB::pdo()->exec($stmt);
        } catch (PDOException $e) {
            $errno = (int) ($e->errorInfo[1] ?? $e->getCode());
            $tolerated = [1050, 1054, 1060, 1061, 1062, 1091, 1022, 121, 1826, 1827];
            if (in_array($errno, $tolerated, true)) {
                return; // جدول/ستون/کلید از قبل هست — همان چیزی که می‌خواستیم
            }
            // دور ۲۳: افزودنِ مجدد FK در اجرای روادار (force/تعمیر) روی برخی
            // نسخه‌های MariaDB با 1005/121report می‌شود (نه 1826). فقط برای ALTER
            // روادار است — CREATE جدول تازه با 1005 همچنان خطای واقعی است.
            if (in_array($errno, [1005, 121], true) && preg_match('/^ALTER\s+TABLE/i', ltrim($stmt))) {
                return;
            }
            throw $e;
        }
    }

    /**
     * مهاجرت خودکار پس از نصب (دور ۱۸ — رفع ۵۰۰ شدن صفحه کارت‌ها).
     *
     * مشکل: راهنمای ارتقای ما «جایگزینی فایل‌ها» است، ولی migrate() فقط هنگام نصب
     * اولیه اجرا می‌شد → روی نصب‌های قدیمی، مهاجرت‌های جدید هرگز به دیتابیس
     * نمی‌رسیدند و صفحات وابسته (مثل کارت‌ها) با خطای عمومی ۵۰۰ می‌بستند.
     *
     * این متد در هر درخواست (وقتی نصب انجام شده) صدا زده می‌شود:
     *  - اگر «اثر انگشت» فایل‌های مهاجرت از آخرین بار تغییر نکرده باشد، فقط یک
     *    file_get_contents کوچک هزینه دارد (marker در storage/cache)؛
     *  - وگرنه با قفل MySQL، مهاجرت‌های در انتظار (یا تغییریافته) را اعمال می‌کند.
     * خطای مهاجرت هرگز خود سایت را نمی‌اندازد — لاگ می‌شود و دفعه بعد دوباره.
     */
    public static function autoMigrate(): void
    {
        $fingerprint = self::migrationsFingerprint();
        $marker = HP_BASE . '/storage/cache/mig_state.txt';
        if (is_file($marker) && trim((string) file_get_contents($marker)) === $fingerprint) {
            return; // هیچ چیزی تغییر نکرده — هزینهٔ تقریباً صفر
        }
        try {
            // قفل: اگر درخواست دیگری همین الان در حال مهاجرت است، صرف‌نظر می‌کنیم.
            // (برخی هاست‌های اشتراکی GET_LOCK را اجازه نمی‌دهند — در آن صورت بدون
            // قفل ادامه می‌دهیم؛ DDL روادار و INSERT IGNORE است و رقاق ایمنی دارد.)
            $locked = false;
            $hasLock = true;
            try {
                $locked = ((int) (DB::val('SELECT GET_LOCK(?, 0)', ['peakopay_migrate']) ?? 0) === 1);
            } catch (Throwable) {
                $hasLock = false; // هاست بدون GET_LOCK — بدون قفل ادامه بده
            }
            if ($hasLock && !$locked) {
                return; // فرایند دیگر همین الان در حال انجامش است
            }
            try {
                self::migrate();
            } finally {
                if ($hasLock) {
                    try {
                        DB::val('SELECT RELEASE_LOCK(?)', ['peakopay_migrate']);
                    } catch (Throwable) {
                    }
                }
            }
            if (!is_dir(dirname($marker))) {
                @mkdir(dirname($marker), 0755, true);
            }
            @file_put_contents($marker, $fingerprint . "\n");
        } catch (Throwable $e) {
            // خطای واقعی مهاجرت: لاگ (قابل مشاهده در «گزارش‌ها») + ادامهٔ سرویس‌دهی
            $msg = 'مهاجرت خودکار دیتابیس ناموفق: ' . $e->getMessage();
            try {
                Log::error($msg);
            } catch (Throwable) {
            }
            error_log(sprintf('[migrate] %s (ref %s)', $e->getMessage(), HP_REQUEST_ID));
        }
    }

    /**
     * بررسی سلامت schema (دور ۱۸): فهرست جدول‌ها/ستون‌های حیاتی هر مهاجرت را با
     * information_schema مقایسه می‌کند. خروجی: ['ok' => bool, 'missing' => string[]]
     * — برای دکمه «تعمیر پایگاه داده» و صفحه عیب‌یابی.
     */
    public static function schemaStatus(): array
    {
        $requiredTables = [
            'stores', 'users', 'settings', 'cards', 'devices', 'pairing_codes', 'api_keys',
            'payment_sessions', 'transactions', 'idempotency_keys', 'webhook_events',
            'audit_logs', 'notifications', 'rate_limits',
            'card_senders', 'card_parser_rules', // 002
            'accounts', 'store_members', 'install_meta', // 003
        ];
        $requiredColumns = [
            'transactions' => ['card_id', 'fingerprint', 'raw_hash'], // 002 + base
            'tx_records'   => [],
            'stores'       => ['account_id', 'connection_status', 'public_id'], // 003
            'users'        => ['account_id', 'role', 'is_active'],              // 003
            'devices'      => ['account_id', 'source'],                         // 003
        ];
        $missing = [];

        $tables = [];
        foreach (DB::all('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $r) {
            $tables[strtolower((string) $r['t'])] = true;
        }
        foreach ($requiredTables as $t) {
            if (!isset($tables[$t])) {
                $missing[] = "جدول `{$t}`";
            }
        }

        $cols = [];
        foreach (DB::all('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $r) {
            $cols[strtolower((string) $r['t']) . '.' . strtolower((string) $r['c'])] = true;
        }
        foreach ($requiredColumns as $table => $list) {
            foreach ($list as $c) {
                if (isset($tables[$table]) && !isset($cols[$table . '.' . $c])) {
                    $missing[] = "ستون `{$table}`.`{$c}`";
                }
            }
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /** اثر انگشت فایل‌های مهاجرت: نام+اندازه+mtime — بدون خواندن کل فایل‌ها. */
    private static function migrationsFingerprint(): string
    {
        $parts = [];
        foreach (glob(HP_BASE . '/migrations/*.sql') ?: [] as $f) {
            $parts[] = basename($f) . ':' . filesize($f) . ':' . filemtime($f);
        }
        sort($parts);
        return md5(implode('|', $parts));
    }

    /** Split a .sql file into statements (schema files contain no semicolons inside strings). */
    private static function splitSql(string $sql): array
    {
        $out    = [];
        $buffer = '';
        foreach (preg_split('/\R/u', $sql) ?: [] as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with($t, ';')) {
                $stmt = trim($buffer);
                $buffer = '';
                $out[] = rtrim($stmt, ';');
            }
        }
        if (trim($buffer) !== '') {
            $out[] = rtrim($buffer, ';');
        }
        return $out;
    }

    // ----------------------------------------------------------------- seed

    /**
     * Create store, admin, card, settings, API key + webhook secret.
     * @return array{store_id:int,public_id:string,api_key:string,webhook_secret:string}
     */
    public static function seed(array $store, array $admin, array $card): array
    {
        // ۲.۰ — نصب تازه از حساب شروع می‌شود: یک حساب + یک مالک + یک فروشگاه
        $accountId = Account::create((string) $store['name']);
        $storeId = Store::create(
            $store['name'], $store['url'], $store['phone'], $store['telegram'],
            $store['currency'] ?? 'IRT', $store['timezone'] ?? 'Asia/Tehran',
            $accountId,
        );
        DB::update('stores', ['connection_status' => 'pending'], 'id = ?', [$storeId]);
        User::create($accountId, $storeId, $admin['name'], $admin['email'], $admin['password'], 'owner');
        Card::add($storeId, $card);
        $apiKey   = ApiKey::issue($storeId, 'WooCommerce');
        $webhook  = 'whsec_' . Crypto::randomHex(16);
        Setting::set('woo_webhook_secret', $webhook, $storeId);
        Setting::installSet('cron_token', Crypto::randomHex(16)); // دور ۲۳ — دامنهٔ نصب (install_meta): کرون یکجا برای همهٔ فروشگاه‌ها
        if (!empty($store['url'])) {
            Setting::set('woo_site_url', rtrim((string) $store['url'], '/'), $storeId);
        }
        Audit::log('installer.seed', 'store created');
        return [
            'store_id'       => $storeId,
            'public_id'      => (string) DB::val('SELECT public_id FROM stores WHERE id = ?', [$storeId]),
            'api_key'        => $apiKey,
            'webhook_secret' => $webhook,
        ];
    }
}
