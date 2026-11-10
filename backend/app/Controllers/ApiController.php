<?php
/**
 * Peakopay — REST-like JSON API (v1).
 *
 * Response envelope: {"ok":true,"data":{…}} / {"ok":false,"error":{code,message,ref}}
 *
 * Authentication:
 *   - Store (WooCommerce plugin): headers `X-Peakopay-Store` + `Authorization: Bearer hpk_…`
 *   - Device (Android app): HMAC-signed requests — `X-Peakopay-Device`,
 *     `X-Peakopay-Timestamp`, `X-Peakopay-Signature` = HMAC-SHA256(secret, ts . "." . rawBody)
 *
 * The payment flow is fully synchronous: a device upload returns the match
 * result immediately. No cron is involved anywhere.
 */
declare(strict_types=1);

final class ApiController
{
    private const TS_WINDOW = 300; // ±5 min replay window

    // ------------------------------------------------------------ payments

    /** POST /api/v1/payments/create — WooCommerce plugin */
    public static function createPayment(): void
    {
        $auth = self::authStore();
        $storeId = (int) $auth['store']['id'];
        if (!RateLimiter::allow('api:create:' . $storeId, 120, 60)) {
            Http::apiError('rate_limited', 'تعداد درخواست‌ها زیاد است؛ لطفاً کمی بعد دوباره تلاش کنید.', 429);
        }

        $in = Http::jsonInput();
        if ($in === null) {
            Http::apiError('bad_json', 'بدنه درخواست JSON معتبر نیست.', 400);
        }
        $v = Validator::make($in)->rule('order_id', 'required|maxlen:64', 'شماره سفارش')
            ->rule('amount', 'required|int|min:100', 'مبلغ')
            ->rule('currency', 'required|in:IRT;IRR;irt;irr', 'واحد پول');
        $orderId   = (string) ($in['order_id'] ?? '');
        $orderKey  = (string) ($in['order_key'] ?? '');
        $currency  = strtoupper((string) ($in['currency'] ?? ''));
        $amount    = (int) ($in['amount'] ?? 0);
        $isTest    = !empty($in['test']) && Setting::bool('test_mode', false, $storeId);

        // Explicit, transparent currency conversion — never silently changed.
        $amountRial = $currency === 'IRT' ? $amount * 10 : $amount;

        $card = Card::activeDefault($storeId);
        if ($card === null) {
            Http::apiError('no_card', 'کارت مقصد در پنل پیکو پی تنظیم نشده است.', 409);
        }

        // Idempotency (header) — same key returns the same payment.
        $idemKey = trim((string) (Http::header('Idempotency-Key') ?? $in['idempotency_key'] ?? ''));
        if ($idemKey !== '') {
            $idemKey = mb_substr($idemKey, 0, 120);
            $existing = DB::one('SELECT * FROM idempotency_keys WHERE scope = ? AND ikey = ?', ['payment.create', $idemKey]);
            if ($existing !== null) {
                $pay = Payment::findReusable($storeId, $orderId, $amountRial, $isTest);
                if ($pay !== null) {
                    Http::apiSuccess(['payment' => self::paymentView($pay, $card), 'replayed' => true]);
                }
            }
        }

        try {
            $payment = Payment::create($storeId, $card, $orderId, $orderKey, $amountRial, $currency, $amount, [
                'customer_name'  => (string) ($in['customer']['name'] ?? ''),
                'customer_phone' => (string) ($in['customer']['phone'] ?? ''),
                'description'    => (string) ($in['description'] ?? ''),
                'redirect_url'   => (string) ($in['redirect_url'] ?? ''),
                'expected_reference' => (string) ($in['expected_reference'] ?? ''),
                'is_test'        => $isTest,
            ]);
        } catch (DomainException $e) {
            // validation problems from the model (min/max amount…)
            Http::apiError('invalid_amount', $e->getMessage(), 422);
        }

        if ($idemKey !== '') {
            try {
                DB::insert('idempotency_keys', ['scope' => 'payment.create', 'ikey' => $idemKey, 'response_hash' => Crypto::sha256((string) $payment['id']), 'created_at' => DB::now()]);
                if (random_int(1, 100) === 1) { // opportunistic cleanup, not required
                    DB::q('DELETE FROM idempotency_keys WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
                    RateLimiter::gc();
                }
            } catch (Throwable $e) {
                Log::warn('idempotency record failed: ' . $e->getMessage());
            }
        }

        Log::info('payment created', ['payment' => (int) $payment['id'], 'order' => $orderId, 'amount' => $amountRial]);
        Http::apiSuccess(['payment' => self::paymentView($payment, $card)]);
    }

    /** GET /api/v1/payments/{token} — plugin status check (thank-you fallback) */
    public static function getPayment(array $args): void
    {
        $auth   = self::authStore();
        $storeId = (int) $auth['store']['id'];
        $payment = Payment::findByToken((string) $args['token']);
        if ($payment === null || (int) $payment['store_id'] !== $storeId) {
            Http::apiError('not_found', 'پرداخت یافت نشد.', 404);
        }
        $payment = Payment::expireIfNeeded($payment);
        Http::apiSuccess(['payment' => self::paymentView($payment, null, true)]);
    }

    // ---------------------------------------------------------- WooCommerce

    /** POST /api/v1/webhooks/woocommerce — inbound events from the plugin (informational) */
    public static function wooWebhook(): void
    {
        $auth  = self::authStore();
        $in    = Http::jsonInput();
        if ($in === null) {
            Http::apiError('bad_json', 'بدنه درخواست JSON معتبر نیست.', 400);
        }
        $event = (string) ($in['event'] ?? 'unknown');
        Audit::log('woo.event', 'event=' . $event . ' order=' . (string) ($in['order_id'] ?? ''));
        Http::apiSuccess(['received' => true, 'event' => $event]);
    }

    /** POST /api/v1/test-connection — plugin "Test Connection" button */
    public static function testConnection(): void
    {
        $auth  = self::authStore();
        $store = $auth['store'];
        $sid   = (int) $store['id'];
        // وضعیت کامل برای «تست اتصال» افزونه — همه اجزا در یک پاسخ
        $cards   = (int) DB::val('SELECT COUNT(*) FROM cards WHERE store_id = ? AND is_active = 1', [$sid]);
        $senders = (int) DB::val('SELECT COUNT(*) FROM card_senders s JOIN cards c ON c.id = s.card_id WHERE c.store_id = ?', [$sid]);
        $devices = (int) DB::val("SELECT COUNT(*) FROM devices WHERE store_id = ? AND status = 'active'", [$sid]);
        Audit::log('api.test_connection', 'ok store=' . (string) $store['public_id'] . ' cards=' . $cards . ' devices=' . $devices, ['store_id' => $sid]);
        Http::apiSuccess([
            'connected'  => true,
            'store'      => $store['name'],
            'version'    => HP_VERSION,
            'server_time'=> gmdate('c'),
            'crypto'     => Crypto::hasSodium() ? 'sodium' : 'openssl-aes-256-gcm',
            'currency'   => (string) ($store['currency'] ?? 'IRT'),
            'test_mode'  => Setting::get('test_mode', '', $sid) === '1',
            'cards'      => $cards,
            'senders'    => $senders,
            'devices'    => $devices,
        ]);
    }

    // ------------------------------------------------- account auth (دور ۲۳)

    /**
     * POST /api/v1/auth/login — ورود اپ اندروید با حساب کاربری (ایمیل + رمز).
     * دستگاهی حساب‌محور می‌سازد (active — خودِ ورود، تأیید است) با دامنهٔ
     * همهٔ فروشگاه‌های حساب. برute-force با RateLimiter مسدود می‌شود.
     */
    public static function login(): void
    {
        if (!RateLimiter::allow('applogin:' . Http::ip(), 10, 900)) {
            Http::apiError('rate_limited', 'تلاش‌های ورود زیاد است؛ ۱۵ دقیقه دیگر دوباره امتحان کنید.', 429);
        }
        $in = Http::jsonInput();
        if (!is_array($in)) {
            Http::apiError('bad_json', 'بدنه درخواست JSON معتبر نیست.', 400);
        }
        $email    = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');
        if ($email === '' || $password === '') {
            Http::apiError('missing_credentials', 'ایمیل و رمز عبور الزامی است.', 422);
        }
        $user = User::findByEmail($email);
        if ($user === null || (int) ($user['is_active'] ?? 1) !== 1 || !User::verifyPassword($user, $password)) {
            Audit::log('applogin.failed', 'email=' . mb_substr($email, 0, 60));
            usleep(300000);
            Http::apiError('invalid_credentials', 'ایمیل یا رمز عبور اشتباه است.', 401);
        }
        $accountId = (int) ($user['account_id'] ?? 0);
        if ($accountId <= 0) {
            Http::apiError('no_account', 'این کاربر به حسابی متصل نیست؛ با پنل مدیریت تماس بگیرید.', 403);
        }
        RateLimiter::clear('applogin:' . Http::ip());
        $secret = Crypto::newDeviceSecret();
        $now    = DB::now();
        $storeId = (int) ($user['store_id'] ?? 0);
        if ($storeId <= 0) {
            $memberships = StoreMember::storesOfUser((int) $user['id']);
            $storeId = (int) (array_key_first($memberships) ?: 0);
        }

        // دور ۲۵ — افزودن فروشگاه/چنداکانتی: اگر دستگاهِ فعال، اعتبارنامهٔ خودش را
        // فرستاده باشد، همان دستگاه به این حساب وصل می‌شود (بدون ردیف جدید) و
        // secret قبلی‌اش را نگه می‌دارد — مثل ورود اکانت دوم در واتساپ.
        $upgrade = null;
        $reqDeviceId = (int) ($in['device_id'] ?? 0);
        $reqSecret   = (string) ($in['device_secret'] ?? '');
        if ($reqDeviceId > 0 && $reqSecret !== '') {
            $existing = Device::find($reqDeviceId);
            if ($existing !== null && $existing['status'] !== 'revoked' && Device::secret($reqDeviceId) === $reqSecret) {
                $upgrade = $existing;
            } else {
                Http::apiError('invalid_device', 'اعتبارنامهٔ دستگاه فعلی معتبر نیست؛ دوباره همراه‌سازی کنید.', 401);
            }
        }

        if ($upgrade !== null) {
            DB::update('devices', [
                'store_id'        => $storeId, // فروشگاه اصلی — دامنهٔ واقعی: account_id
                'account_id'      => $accountId,
                'source'          => 'login',
                'app_version'     => mb_substr((string) ($in['app_version'] ?? ''), 0, 20),
                'android_version' => mb_substr((string) ($in['android_version'] ?? ''), 0, 20),
                'approved_at'     => $upgrade['approved_at'] ?? $now,
            ], 'id = ?', [(int) $upgrade['id']]);
            User::touchLogin((int) $user['id']);
            Audit::log('applogin.upgrade', 'user=' . $user['id'] . ' device=' . (int) $upgrade['id'] . ' account=' . $accountId);
            Http::apiSuccess([
                'device_id'     => (int) $upgrade['id'],
                'device_secret' => $reqSecret,
                'status'        => 'active',
                'user'          => ['name' => (string) $user['name'], 'role' => (string) $user['role']],
                'account'       => ['name' => (string) (DB::val('SELECT name FROM accounts WHERE id = ?', [$accountId]) ?? '')],
                'stores'        => self::storeViews($accountId),
                'config_version' => CardSms::versionForStores(Account::storeIds($accountId)),
                'api_version'   => 'v1',
            ]);
        }

        $deviceId = DB::insert('devices', [
            'store_id'        => $storeId, // فروشگاه اصلی — دامنهٔ واقعی: account_id
            'account_id'      => $accountId,
            'name'            => mb_substr((string) ($in['device_name'] ?? 'دستگاه بدون نام'), 0, 120),
            'model'           => mb_substr((string) ($in['model'] ?? ''), 0, 120),
            'android_version' => mb_substr((string) ($in['android_version'] ?? ''), 0, 20),
            'app_version'     => mb_substr((string) ($in['app_version'] ?? ''), 0, 20),
            'status'          => 'active',
            'source'          => 'login',
            'secret_enc'      => Crypto::encrypt($secret),
            'paired_at'       => $now,
            'approved_at'     => $now,
        ]);
        User::touchLogin((int) $user['id']);
        Audit::log('applogin.success', 'user=' . $user['id'] . ' device=' . $deviceId);
        Http::apiSuccess([
            'device_id'     => $deviceId,
            'device_secret' => $secret,
            'status'        => 'active',
            'user'          => ['name' => (string) $user['name'], 'role' => (string) $user['role']],
            'account'       => ['name' => (string) (DB::val('SELECT name FROM accounts WHERE id = ?', [$accountId]) ?? '')],
            'stores'        => self::storeViews($accountId),
            'config_version' => CardSms::versionForStores(Account::storeIds($accountId)),
            'api_version'   => 'v1',
        ]);
    }

    /** GET /api/v1/account/stores — فروشگاه‌های حساب دستگاه. */
    public static function accountStores(): void
    {
        $device = self::authDevice(allowPending: true);
        $accountId = (int) ($device['account_id'] ?? 0);
        if ($accountId <= 0) {
            // legacy: دستگاه تک‌فروشگاهی — همان یک فروشگاه را می‌بیند
            $s = Store::get((int) $device['store_id']);
            Http::apiSuccess(['stores' => $s === null ? [] : [[
                'id' => (int) $s['id'], 'name' => (string) $s['name'], 'domain' => (string) $s['domain'],
                'connection_status' => (string) $s['connection_status'], 'currency' => (string) $s['currency'],
                'logo_url' => Store::logoUrl((int) $s['id']),
            ]]]);
        }
        Http::apiSuccess(['stores' => self::storeViews($accountId), 'server_time' => gmdate('c')]);
    }

    /** @return array<int,array{id:int,name:string,domain:string,connection_status:string,currency:string,logo_url:string}> */
    private static function storeViews(int $accountId): array
    {
        $out = [];
        foreach (Account::stores($accountId) as $s) {
            $out[] = [
                'id'                => (int) $s['id'],
                'name'              => (string) $s['name'],
                'domain'            => (string) $s['domain'],
                'connection_status' => (string) $s['connection_status'],
                'currency'          => (string) $s['currency'],
                // دور ۲۶ — لوگوی سایت فروشگاه (favicon) برای آواتار اپ
                'logo_url'          => Store::logoUrl((int) $s['id']),
            ];
        }
        return $out;
    }

    /** GET /api/v1/account/overview — داشبورد: یک فروشگاه (?store_id=) یا کل حساب. */
    public static function accountOverview(): void
    {
        $device = self::authDevice(allowPending: true);
        $allowed = self::deviceStoreIds($device);
        $req = (int) (Http::get('store_id', 0) ?: 0);
        $scopeStores = $allowed;
        if ($req > 0) {
            if (!in_array($req, $allowed, true)) {
                Http::apiError('forbidden', 'این فروشگاه به این حساب تعلق ندارد.', 403);
            }
            $scopeStores = [$req];
        }
        $multi = count($allowed) > 1;

        $today = ['income_rial' => 0, 'paid_count' => 0, 'transactions' => 0, 'unmatched' => 0, 'failed' => 0, 'expired' => 0, 'pending' => 0];
        $totals = ['income_rial' => 0, 'paid_count' => 0];
        $perStore = [];
        $lastTx = null;
        foreach ($scopeStores as $sid) {
            $stats = Payment::statsToday((int) $sid);
            $today['income_rial'] += (int) $stats['paid_income'];
            $today['paid_count']  += (int) $stats['paid_count'];
            $today['pending']     += (int) $stats['pending'];
            $today['failed']      += (int) ($stats['failed'] ?? 0);
            $today['expired']     += (int) ($stats['expired'] ?? 0);
            $today['transactions'] += Transaction::todayCount((int) $sid);
            $today['unmatched']   += (int) DB::val("SELECT COUNT(*) FROM transactions WHERE store_id = ? AND status = 'unmatched'", [$sid]);
            $totals['income_rial'] += Payment::totalIncome((int) $sid);
            $totals['paid_count']  += (int) DB::val("SELECT COUNT(*) FROM payment_sessions WHERE store_id = ? AND status = 'paid' AND is_test = 0", [$sid]);
            $lt = Transaction::lastForStore((int) $sid);
            if ($lt !== null && ($lastTx === null || $lt['id'] > $lastTx['id'])) {
                $lastTx = $lt + ['store_id' => (int) $sid, 'store_name' => (string) DB::val('SELECT name FROM stores WHERE id = ?', [$sid])];
            }
            if ($multi) {
                $perStore[] = [
                    'store_id'    => (int) $sid,
                    'name'        => (string) DB::val('SELECT name FROM stores WHERE id = ?', [$sid]),
                    'domain'      => (string) DB::val('SELECT domain FROM stores WHERE id = ?', [$sid]),
                    'connection_status' => (string) DB::val('SELECT connection_status FROM stores WHERE id = ?', [$sid]),
                    'today_income_rial' => (int) $stats['paid_income'],
                    'today_paid'   => (int) $stats['paid_count'],
                    'pending'      => (int) $stats['pending'],
                    'last_sync_at' => (string) (DB::val('SELECT MAX(last_sync_at) FROM devices WHERE account_id = ? AND last_sync_at IS NOT NULL', [(int) ($device['account_id'] ?? 0)]) ?: ''),
                ];
            }
        }
        Http::apiSuccess([
            'scope'       => $req > 0 ? 'store' : ($multi ? 'account' : 'store'),
            'store_id'    => $req > 0 ? $req : null,
            'stores'      => self::storeViews((int) ($device['account_id'] ?? 0)),
            'per_store'   => $perStore,
            'today'       => $today,
            'totals'      => $totals,
            'last_transaction' => $lastTx === null ? null : [
                'amount_rial' => (int) $lastTx['amount_rial'],
                'bank'        => (string) $lastTx['bank'],
                'created_at'  => (string) $lastTx['created_at'],
                'status'      => (string) $lastTx['status'],
                'store_id'    => (int) $lastTx['store_id'],
                'store_name'  => (string) $lastTx['store_name'],
            ],
            'device' => ['id' => (int) $device['id'], 'name' => $device['name'], 'status' => $device['status'], 'last_seen_at' => $device['last_seen_at']],
            'server_time' => gmdate('c'),
        ]);
    }

    /** GET /api/v1/account/transactions — فهرست پرداخت‌ها/تراکنش‌ها با فیلتر (device auth). */
    public static function accountTransactions(): void
    {
        $device = self::authDevice(allowPending: true);
        $allowed = self::deviceStoreIds($device);
        $req = (int) (Http::get('store_id', 0) ?: 0);
        $scope = $allowed;
        if ($req > 0) {
            if (!in_array($req, $allowed, true)) {
                Http::apiError('forbidden', 'این فروشگاه به این حساب تعلق ندارد.', 403);
            }
            $scope = [$req];
        }
        $ph    = implode(',', array_fill(0, count($scope), '?'));
        $names = [];
        foreach (DB::all("SELECT id, name, domain FROM stores WHERE id IN ($ph)", $scope) as $s) {
            $names[(int) $s['id']] = $s;
        }

        $status = (string) (Http::get('status', 'all') ?: 'all');
        $q      = trim((string) (Http::get('q', '') ?: ''));
        $page   = max(1, (int) (Http::get('page', 1) ?: 1));
        $limit  = min(50, max(10, (int) (Http::get('limit', 25) ?: 25)));
        $off    = ($page - 1) * $limit;

        $rows = [];
        $more = false;
        if ($status === 'unmatched') {
            // تراکنش‌های بدون تطبیق (پیشنهاد اتصال دستی)
            $sql = "SELECT t.id, t.store_id, t.amount_rial, t.bank, t.sender, t.occurred_at, t.reference, t.dest_last4, t.status, t.created_at
                    FROM transactions t
                    WHERE t.store_id IN ($ph) AND t.status = 'unmatched'";
            $params = $scope;
            if ($q !== '') {
                $sql .= ' AND (t.reference LIKE ? OR t.bank LIKE ?)';
                $params[] = "%{$q}%"; $params[] = "%{$q}%";
            }
            $sql .= ' ORDER BY t.id DESC';
            $all = DB::all($sql . " LIMIT " . ($limit + 1) . " OFFSET $off", $params);
            $more = count($all) > $limit;
            foreach (array_slice($all, 0, $limit) as $t) {
                $rows[] = [
                    'kind' => 'txn', 'id' => (int) $t['id'],
                    'store_id' => (int) $t['store_id'],
                    'store_name' => (string) ($names[(int) $t['store_id']]['name'] ?? ''),
                    'amount_rial' => (int) $t['amount_rial'],
                    'status' => 'unmatched',
                    'order_id' => '',
                    'bank' => (string) $t['bank'],
                    'sender' => (string) $t['sender'],
                    'occurred_at' => (string) $t['occurred_at'],
                    'created_at' => (string) $t['created_at'],
                    'reference' => (string) $t['reference'],
                    'dest_last4' => (string) $t['dest_last4'],
                    'has_txn' => true,
                ];
            }
        } else {
            // پرداخت‌محور (کار تاجر): هر ردیف یک پرداخت + تراکنش وصل‌شده اگر باشد
            $map = ['all' => '', 'paid' => "p.status = 'paid'", 'pending' => "p.status IN ('pending','verifying')",
                    'expired' => "p.status = 'expired'", 'failed' => "p.status = 'failed'", 'review' => "p.status = 'verifying'"];
            $cond = $map[$status] ?? '';
            $sql = "SELECT p.id, p.store_id, p.order_id, p.amount_rial, p.status, p.is_test, p.created_at, p.paid_at, p.expires_at, p.woo_notify_status,
                           t.id AS txn_id, t.bank, t.sender, t.occurred_at, t.reference, t.dest_last4, t.status AS txn_status
                    FROM payment_sessions p
                    LEFT JOIN transactions t ON t.id = p.transaction_id
                    WHERE p.store_id IN ($ph)";
            $params = $scope;
            if ($cond !== '') {
                $sql .= ' AND ' . $cond;
            }
            if ($q !== '') {
                $sql .= ' AND (p.order_id LIKE ? OR p.customer_name LIKE ?)';
                $params[] = "%{$q}%"; $params[] = "%{$q}%";
            }
            $sql .= ' ORDER BY p.id DESC';
            $all = DB::all($sql . " LIMIT " . ($limit + 1) . " OFFSET $off", $params);
            $more = count($all) > $limit;
            foreach (array_slice($all, 0, $limit) as $p) {
                $rows[] = [
                    'kind' => 'payment', 'id' => (int) $p['id'],
                    'store_id' => (int) $p['store_id'],
                    'store_name' => (string) ($names[(int) $p['store_id']]['name'] ?? ''),
                    'amount_rial' => (int) $p['amount_rial'],
                    'status' => (string) $p['status'],
                    'order_id' => (string) $p['order_id'],
                    'is_test' => (int) $p['is_test'] === 1,
                    'created_at' => (string) $p['created_at'],
                    'paid_at' => (string) ($p['paid_at'] ?? ''),
                    'expires_at' => (string) $p['expires_at'],
                    'woo_notify_status' => (string) $p['woo_notify_status'],
                    'bank' => (string) ($p['bank'] ?? ''),
                    'sender' => (string) ($p['sender'] ?? ''),
                    'occurred_at' => (string) ($p['occurred_at'] ?? ''),
                    'reference' => (string) ($p['reference'] ?? ''),
                    'dest_last4' => (string) ($p['dest_last4'] ?? ''),
                    'has_txn' => $p['txn_id'] !== null,
                ];
            }
        }
        Http::apiSuccess(['transactions' => $rows, 'page' => $page, 'more' => $more, 'scope_store' => $req > 0 ? $req : null, 'server_time' => gmdate('c')]);
    }

    /** GET /api/v1/account/transactions/detail — جزئیات کامل یک پرداخت/تراکنش. */
    public static function accountTransactionDetail(): void
    {
        $device = self::authDevice(allowPending: true);
        $allowed = self::deviceStoreIds($device);
        $id   = (int) (Http::get('id', 0) ?: 0);
        $kind = (string) (Http::get('kind', 'payment') ?: 'payment');
        if ($id <= 0) {
            Http::apiError('missing_id', 'شناسه الزامی است.', 422);
        }
        if ($kind === 'txn') {
            $t = Transaction::find($id);
            if ($t === null || !in_array((int) $t['store_id'], $allowed, true)) {
                Http::apiError('not_found', 'تراکنش یافت نشد.', 404);
            }
            Http::apiSuccess(['kind' => 'txn', 'txn' => [
                'id' => (int) $t['id'], 'store_id' => (int) $t['store_id'],
                'store_name' => (string) (DB::val('SELECT name FROM stores WHERE id = ?', [(int) $t['store_id']]) ?? ''),
                'amount_rial' => (int) $t['amount_rial'], 'bank' => (string) $t['bank'],
                'sender' => (string) $t['sender'], 'occurred_at' => (string) $t['occurred_at'],
                'dest_last4' => (string) $t['dest_last4'], 'reference' => (string) $t['reference'],
                'status' => (string) $t['status'], 'created_at' => (string) $t['created_at'],
                'is_test' => (int) $t['is_test'] === 1,
            ]]);
        }
        $p = Payment::find($id);
        if ($p === null || !in_array((int) $p['store_id'], $allowed, true)) {
            Http::apiError('not_found', 'پرداخت یافت نشد.', 404);
        }
        $t = $p['transaction_id'] !== null ? Transaction::find((int) $p['transaction_id']) : null;
        Http::apiSuccess(['kind' => 'payment', 'payment' => [
            'id' => (int) $p['id'], 'store_id' => (int) $p['store_id'],
            'store_name' => (string) (DB::val('SELECT name FROM stores WHERE id = ?', [(int) $p['store_id']]) ?? ''),
            'order_id' => (string) $p['order_id'], 'order_key' => (string) $p['order_key'],
            'amount_rial' => (int) $p['amount_rial'], 'amount_base_rial' => (int) $p['amount_base_rial'],
            'status' => (string) $p['status'], 'is_test' => (int) $p['is_test'] === 1,
            'customer_name' => (string) $p['customer_name'],
            'created_at' => (string) $p['created_at'], 'paid_at' => (string) ($p['paid_at'] ?? ''),
            'expires_at' => (string) $p['expires_at'],
            'woo_notify_status' => (string) $p['woo_notify_status'],
        ], 'txn' => $t === null ? null : [
            'id' => (int) $t['id'], 'amount_rial' => (int) $t['amount_rial'],
            'bank' => (string) $t['bank'], 'sender' => (string) $t['sender'],
            'occurred_at' => (string) $t['occurred_at'], 'dest_last4' => (string) $t['dest_last4'],
            'reference' => (string) $t['reference'], 'status' => (string) $t['status'],
            'created_at' => (string) $t['created_at'],
        ]]);
    }

    // -------------------------------------------------------------- pairing

    /** POST /api/v1/auth/pair — exchange short pairing code for device credentials */
    public static function pair(): void
    {
        if (!RateLimiter::allow('pair:' . Http::ip(), 12, 3600)) {
            Http::apiError('rate_limited', 'تلاش‌های همراه‌سازی زیاد است؛ یک ساعت دیگر دوباره تلاش کنید.', 429);
        }
        $in = Http::jsonInput();
        if ($in === null) {
            Http::apiError('bad_json', 'بدنه درخواست JSON معتبر نیست.', 400);
        }
        $code = (string) ($in['code'] ?? '');
        if ($code === '') {
            Http::apiError('missing_code', 'کد همراه‌سازی را وارد کنید.', 422);
        }
        try {
            $creds = Device::pair(
                $code,
                (string) ($in['device_name'] ?? ''),
                (string) ($in['model'] ?? ''),
                (string) ($in['app_version'] ?? ''),
                (string) ($in['android_version'] ?? ''),
                // دور ۲۵ — افزودن فروشگاه: دستگاه فعلی به‌جای ردیف جدید، به فروشگاه کد جدید وصل می‌شود
                ((int) ($in['device_id'] ?? 0)) > 0 ? (int) $in['device_id'] : null,
                (string) ($in['device_secret'] ?? '') !== '' ? (string) $in['device_secret'] : null
            );
        } catch (RuntimeException $e) {
            Http::apiError('invalid_code', $e->getMessage(), 422);
        }
        $added = !empty($creds['added']);
        Notifier::devicePaired(['name' => (string) ($in['device_name'] ?? 'دستگاه')], (int) ($creds['store_id'] ?? 0));
        Http::apiSuccess([
            'device_id'     => $creds['id'],
            'device_secret' => $creds['secret'],
            'store'         => $creds['store'],
            'store_id'      => (int) ($creds['store_id'] ?? 0),
            'status'        => $creds['status'] ?? 'pending',
            'added_store'   => $added,
            'message'       => $added
                ? 'فروشگاه به همین دستگاه اضافه شد؛ از همین حالا می‌توانید بین فروشگاه‌هایتان جابه‌جا شوید.'
                : 'دستگاه ثبت شد. از پنل مدیریت، توسط مدیر تأیید شود تا تراکنش‌ها ارسال شوند.',
            'api_version'   => 'v1',
        ]);
    }

    // -------------------------------------------------------------- devices

    /** POST /api/v1/devices/register — update device metadata after pairing */
    public static function registerDevice(): void
    {
        $device = self::authDevice(allowPending: true);
        $in = Http::jsonInput();
        if ($in === null) {
            Http::apiError('bad_json', 'بدنه درخواست JSON معتبر نیست.', 400);
        }
        $data = [];
        if (!empty($in['device_name'])) {
            $data['name'] = mb_substr((string) $in['device_name'], 0, 120);
        }
        if (!empty($in['model'])) {
            $data['model'] = mb_substr((string) $in['model'], 0, 120);
        }
        if (!empty($in['app_version'])) {
            $data['app_version'] = mb_substr((string) $in['app_version'], 0, 20);
        }
        if ($data !== []) {
            DB::update('devices', $data, 'id = ?', [(int) $device['id']]);
        }
        Device::touchSeen((int) $device['id']);
        Http::apiSuccess(['registered' => true, 'status' => $device['status']]);
    }

    /** POST /api/v1/devices/heartbeat */
    public static function heartbeat(): void
    {
        $device = self::authDevice(allowPending: true);
        Device::touchSeen((int) $device['id']);
        $storeId = (int) $device['store_id'];
        $stats   = Payment::statsToday($storeId);
        Http::apiSuccess([
            'server_time'   => gmdate('c'),
            'device_status' => $device['status'],
            'sms_config_version' => CardSms::versionForStores(self::deviceStoreIds($device)),
            'store'         => ['name' => (string) (DB::val('SELECT name FROM stores WHERE id = ?', [$storeId]) ?? '')],
            'today'         => [
                'paid_count' => $stats['paid_count'],
                'paid_income_rial' => $stats['paid_income'],
                'transactions' => Transaction::todayCount($storeId),
                'failed' => (int) ($stats['failed'] ?? 0),
                'expired' => (int) ($stats['expired'] ?? 0),
            ],
            'pending' => [
                'payments'  => $stats['pending'],
                'unmatched' => (int) DB::val("SELECT COUNT(*) FROM transactions WHERE store_id = ? AND status = 'unmatched'", [$storeId]),
            ],
        ]);
    }

    /** GET /api/v1/devices/config — card/sender/parser config for the SMS pipeline (device auth). */
    public static function deviceConfig(): void
    {
        $device = self::authDevice(allowPending: true);
        // دور ۲۳ — کل حساب دستگاه: کارت‌های همهٔ فروشگاه‌ها (با store_id) تا
        // پیامکِ هر فروشگاه به کارت همان فروشگاه (و تراکنشِ همان فروشگاه) برود.
        Http::apiSuccess(CardSms::deviceConfigForStores(self::deviceStoreIds($device)));
    }

    /** GET /api/v1/store/status — dashboard data (device or store auth) */
    public static function storeStatus(): void
    {
        $deviceHeader = self::headerAny('X-Peakopay-Device', 'X-HamrahPay-Device');
        if ($deviceHeader !== null) {
            $device  = self::authDevice(allowPending: true);
            $storeId = self::deviceStore($device, (int) (Http::get('store_id', 0) ?: 0));
            $deviceRow = $device;
        } else {
            $auth   = self::authStore();
            $storeId = (int) $auth['store']['id'];
            $deviceRow = null;
        }
        $store  = Store::get($storeId);
        $stats  = Payment::statsToday($storeId);
        $lastTx = Transaction::lastForStore($storeId);
        Http::apiSuccess([
            'store' => [
                'name'     => (string) ($store['name'] ?? ''),
                'currency' => (string) ($store['currency'] ?? 'IRT'),
                'timezone' => (string) ($store['timezone'] ?? 'Asia/Tehran'),
            ],
            'today' => [
                'income_rial'     => $stats['paid_income'],
                'paid_count'      => $stats['paid_count'],
                'transactions'    => Transaction::todayCount($storeId),
                'unmatched'       => (int) DB::val("SELECT COUNT(*) FROM transactions WHERE store_id = ? AND status = 'unmatched'", [$storeId]),
                'failed'          => (int) ($stats['failed'] ?? 0),
                'expired'         => (int) ($stats['expired'] ?? 0),
            ],
            'totals' => [
                'income_rial' => Payment::totalIncome($storeId),
                'paid_count'  => (int) DB::val("SELECT COUNT(*) FROM payment_sessions WHERE store_id = ? AND status = 'paid' AND is_test = 0", [$storeId]),
            ],
            'pending_payments' => $stats['pending'],
            'last_transaction' => $lastTx === null ? null : [
                'amount_rial' => (int) $lastTx['amount_rial'],
                'bank'        => (string) $lastTx['bank'],
                'created_at'  => (string) $lastTx['created_at'],
                'status'      => (string) $lastTx['status'],
            ],
            'device' => $deviceRow === null ? null : [
                'id' => (int) $deviceRow['id'], 'name' => $deviceRow['name'],
                'status' => $deviceRow['status'],
                'last_seen_at' => $deviceRow['last_seen_at'],
            ],
            'server_time' => gmdate('c'),
        ]);
    }

    /** GET /api/v1/devices/payevents — آخرین پرداخت‌ها برای اعلان‌های اپ (poll سبک) */
    public static function paymentEvents(): void
    {
        $device = self::authDevice();
        if (!RateLimiter::allow('api:payevents:' . $device['id'], 90, 60)) {
            Http::apiError('rate_limited', 'تعداد درخواست‌ها زیاد است.', 429);
        }
        $sids = self::deviceStoreIds($device);
        $ph   = implode(',', array_fill(0, count($sids), '?'));
        $rows = DB::all(
            "SELECT id, store_id, order_id, amount_rial, status, is_test, updated_at
             FROM payment_sessions
             WHERE store_id IN ($ph)
             ORDER BY id DESC
             LIMIT 25",
            $sids
        );
        Http::apiSuccess(['events' => $rows, 'server_time' => gmdate('c')]);
    }

    // ------------------------------------------------------ device card manager
    // مدیریت کامل کارت‌ها/فرستنده‌ها/قالب‌ها از خود اپ اندروید (دستگاه فعال).

    /** GET /api/v1/devices/cards — فهرست کامل برای مدیریت (بدون شماره کارت کامل) */
    public static function deviceCards(): void
    {
        $device = self::authDevice();
        $sid    = self::deviceStore($device, (int) (Http::get('store_id', 0) ?: 0));
        $cards  = [];
        foreach (Card::all($sid) as $c) {
            $cid = (int) $c['id'];
            $cards[] = [
                'id'         => $cid,
                'label'      => (string) $c['label'],
                'bank_name'  => (string) $c['bank_name'],
                'holder'     => (string) $c['holder'],
                'last4'      => (string) $c['number_last4'],
                'iban'       => (string) ($c['iban'] ?? ''),
                'account'    => (string) ($c['account'] ?? ''),
                'enabled'    => (int) $c['is_active'] === 1,
                'is_default' => (int) $c['is_default'] === 1,
                'senders'    => array_column(CardSms::senders($cid), 'sender_raw'),
                'rules'      => array_map(static fn (array $r): array => [
                    'id' => (int) $r['id'], 'name' => (string) $r['name'],
                    'priority' => (int) $r['priority'], 'enabled' => (int) $r['enabled'] === 1,
                ], CardSms::rules($cid)),
            ];
        }
        Http::apiSuccess(['cards' => $cards, 'config_version' => CardSms::versionForStores(self::deviceStoreIds($device))]);
    }

    /** POST /api/v1/devices/cards/save — ساخت/ویرایش کارت (شماره فقط نوشتنی است) */
    public static function deviceCardSave(): void
    {
        $device = self::authDevice();
        if (!RateLimiter::allow('api:cardsave:' . $device['id'], 60, 60)) {
            Http::apiError('rate_limited', 'تعداد درخواست‌ها زیاد است؛ کمی بعد تلاش کنید.', 429);
        }
        $in  = Http::jsonInput();
        if (!is_array($in)) {
            Http::apiError('bad_json', 'بدنه درخواست JSON معتبر نیست.', 400);
        }
        $sid = self::deviceStore($device, (int) ($in['store_id'] ?? 0));
        $id  = (int) ($in['card_id'] ?? 0);
        if ($id > 0) {
            $c = Card::find($id);
            if ($c === null || (int) $c['store_id'] !== $sid) {
                Http::apiError('not_found', 'کارت متعلق به فروشگاه شما نیست.', 404);
            }
        }
        $number = trim((string) ($in['number'] ?? ''));
        $v = Validator::make(['number' => $number, 'bank_name' => (string) ($in['bank_name'] ?? ''), 'holder' => (string) ($in['holder'] ?? ''), 'label' => (string) ($in['label'] ?? ''), 'iban' => (string) ($in['iban'] ?? ''), 'account_number' => (string) ($in['account'] ?? '')])
            ->rule('label', 'nullable|maxlen:120', 'نام کارت')
            ->rule('bank_name', 'required|maxlen:80', 'نام بانک')
            ->rule('holder', 'required|minlen:3|maxlen:120', 'نام صاحب کارت')
            ->rule('number', ($id > 0 ? 'nullable|card' : 'required|card'), 'شماره کارت')
            ->rule('iban', 'nullable|maxlen:30|iban', 'شماره شبا')
            ->rule('account_number', 'nullable|maxlen:30', 'شماره حساب');
        if ($v->fails()) {
            Http::apiError('invalid_card', $v->firstError(), 422);
        }
        $data = [
            'label'    => (string) ($in['label'] ?? ''),
            'bank_name' => (string) $in['bank_name'],
            'holder'   => (string) $in['holder'],
            'number'   => $number,
            'iban'     => (string) ($in['iban'] ?? ''),
            'account'  => (string) ($in['account'] ?? ''),
        ];
        if ($id > 0) {
            Card::update($id, $sid, $data);
            Audit::log('card.updated_by_device', 'card=' . $id . ' device=' . (int) $device['id']);
        } else {
            $id = (int) Card::add($sid, $data);
            Audit::log('card.added_by_device', 'card=' . $id . ' device=' . (int) $device['id']);
        }
        $senders = $in['senders'] ?? [];
        if (is_string($senders)) {
            $senders = preg_split('#[\r\n]+#u', $senders) ?: [];
        }
        CardSms::setSenders($id, (array) $senders);
        CardSms::bumpVersion();
        Http::apiSuccess(['card_id' => $id, 'config_version' => CardSms::versionForStores(self::deviceStoreIds($device))]);
    }

    /** POST /api/v1/devices/cards/action — حذف/فعال‌سازی/پیش‌فرض */
    public static function deviceCardAction(): void
    {
        $device = self::authDevice();
        $in = Http::jsonInput();
        $id = (int) ($in['card_id'] ?? 0);
        $do = (string) ($in['do'] ?? '');
        $sid = self::deviceStore($device, (int) ($in['store_id'] ?? 0));
        $card = Card::find($id);
        if ($card === null || (int) $card['store_id'] !== $sid) {
            Http::apiError('not_found', 'کارت متعلق به فروشگاه شما نیست.', 404);
        }
        if ($do === 'delete') {
            Card::remove($id, $sid);
        } elseif ($do === 'toggle') {
            Card::setActive($id, $sid, (int) $card['is_active'] !== 1);
        } elseif ($do === 'default') {
            Card::makeDefault($id, $sid);
        } else {
            Http::apiError('bad_action', 'عملیات نامعتبر است.', 422);
        }
        CardSms::bumpVersion();
        Audit::log('card.action_by_device', 'card=' . $id . ' do=' . $do . ' device=' . (int) $device['id']);
        Http::apiSuccess(['done' => true, 'config_version' => CardSms::versionForStores(self::deviceStoreIds($device))]);
    }

    /** POST /api/v1/devices/analyze — تحلیل نمونه پیامک (همان موتور پنل) */
    public static function deviceAnalyze(): void
    {
        self::authDevice();
        $in = Http::jsonInput();
        $sample = (string) ($in['sample'] ?? '');
        if (mb_strlen($sample) < 6 || mb_strlen($sample) > 1600) {
            Http::apiError('bad_sample', 'متن نمونه باید بین ۶ تا ۱۶۰۰ نویسه باشد.', 422);
        }
        Http::apiSuccess(CardSmsEngine::analyzeSample($sample));
    }

    /** POST /api/v1/devices/rules/save — ساخت قالب از نمونه + انتخاب‌ها (همان موتور پنل) */
    public static function deviceRuleSave(): void
    {
        $device = self::authDevice();
        if (!RateLimiter::allow('api:rulesave:' . $device['id'], 60, 60)) {
            Http::apiError('rate_limited', 'تعداد درخواست‌ها زیاد است؛ کمی بعد تلاش کنید.', 429);
        }
        $in = Http::jsonInput();
        if (!is_array($in)) {
            Http::apiError('bad_json', 'بدنه درخواست JSON معتبر نیست.', 400);
        }
        $sid = self::deviceStore($device, (int) ($in['store_id'] ?? 0));
        $cardId = (int) ($in['card_id'] ?? 0);
        $card = Card::find($cardId);
        if ($card === null || (int) $card['store_id'] !== $sid) {
            Http::apiError('not_found', 'کارت متعلق به فروشگاه شما نیست.', 404);
        }
        $sample = (string) ($in['sample'] ?? '');
        $spans  = $in['spans'] ?? [];
        $name   = trim((string) ($in['name'] ?? '')) ?: ('قالب ' . gmdate('Hi'));
        $prio   = max(1, min(999, (int) ($in['priority'] ?? 10)));
        if (mb_strlen($sample) < 6 || !is_array($spans) || $spans === []) {
            Http::apiError('bad_input', 'نمونه یا بخش‌های انتخابی نامعتبر است.', 422);
        }
        try {
            $rule = CardSmsEngine::buildRule($sample, $spans, mb_substr($name, 0, 80), $prio);
        } catch (Throwable $e) {
            Http::apiError('build_failed', 'ساخت قالب از این نمونه ممکن نبود: ' . $e->getMessage(), 422);
        }
        $ruleId = (int) CardSms::addRule($cardId, $rule['name'], $prio, $rule, $sample);
        CardSms::markTested($ruleId);
        CardSms::bumpVersion();
        Audit::log('rule.added_by_device', 'rule=' . $ruleId . ' card=' . $cardId . ' device=' . (int) $device['id']);
        $run = CardSmsEngine::runRule($rule, CardSmsEngine::normalizeText($sample));
        Http::apiSuccess([
            'rule_id' => $ruleId,
            'ok' => $run['ok'], 'fields' => $run['fields'], 'amount_rial' => $run['amount_rial'],
            'missing' => $run['missing'] ?? [], 'config_version' => CardSms::version(),
        ]);
    }

    /** POST /api/v1/devices/rules/action — حذف/فعال‌سازی قالب */
    public static function deviceRuleAction(): void
    {
        $device = self::authDevice();
        $in = Http::jsonInput();
        $rid = (int) ($in['rule_id'] ?? 0);
        $do = (string) ($in['do'] ?? '');
        $sid = self::deviceStore($device, (int) ($in['store_id'] ?? 0));
        $rule = CardSms::rule($rid, $sid);
        if ($rule === null) {
            Http::apiError('not_found', 'قالب متعلق به فروشگاه شما نیست.', 404);
        }
        if ($do === 'delete') {
            CardSms::deleteRule((int) $rule['id']);
        } elseif ($do === 'toggle') {
            CardSms::toggleRule((int) $rule['id']);
        } else {
            Http::apiError('bad_action', 'عملیات نامعتبر است.', 422);
        }
        CardSms::bumpVersion();
        Audit::log('rule.action_by_device', 'rule=' . $rid . ' do=' . $do . ' device=' . (int) $device['id']);
        Http::apiSuccess(['done' => true, 'config_version' => CardSms::versionForStores(self::deviceStoreIds($device))]);
    }

    /** POST /api/v1/devices/test — تست خط لوله (فرستنده ← کارت ← قالب‌ها) */
    public static function deviceTest(): void
    {
        $device = self::authDevice();
        $in = Http::jsonInput();
        $sid = self::deviceStore($device, (int) ($in['store_id'] ?? 0));
        $sender = (string) ($in['sender'] ?? '');
        $text   = (string) ($in['text'] ?? '');
        $cardId = (int) ($in['card_id'] ?? 0);
        $card = trim($sender) !== ''
            ? CardSms::cardBySender($sender, $sid)
            : (Card::find($cardId) !== null && (int) Card::find($cardId)['store_id'] === $sid ? Card::find($cardId) : null);
        if ($card === null || (int) $card['is_active'] !== 1) {
            Http::apiError('no_card', 'این فرستنده به هیچ کارت فعلی ثبت نشده است. (نرمال‌شده: ' . CardSmsEngine::normalizeSender($sender) . ')', 422);
        }
        $rules = array_values(array_filter(CardSms::rules((int) $card['id']), static fn (array $r): bool => (int) $r['enabled'] === 1));
        $res = CardSmsEngine::runRules($rules, $text);
        if (!$res['ok']) {
            Http::apiError('no_match', ($res['reject_reason'] ?? 'پارس ناموفق بود.'), 422);
        }
        CardSms::markMatched((int) $res['rule_id']);
        Http::apiSuccess(['card' => (string) $card['bank_name'], 'matched_rule' => $res['rule_name'], 'fields' => $res['fields'], 'amount_rial' => $res['amount_rial'], 'currency' => $res['currency']]);
    }

    // ---------------------------------------------------------- manual confirm

    /** GET /api/v1/devices/pending — آیتم‌های در انتظار تصمیم فروشنده (device auth) */
    public static function devicePending(): void
    {
        $device = self::authDevice();
        $sid    = self::deviceStore($device, (int) (Http::get('store_id', 0) ?: 0));
        Device::touchSeen((int) $device['id']);

        // «در انتظار تأیید» = هر پرداخت باز (pending یا verifying) — تأیید دستی
        // در هر حالتی ممکن است، حتی وقتی تأیید خودکار روشن است (پیامک نرسید/پارس
        // نشد/واریز خارج از خط لوله). تراکنش وصل‌شده اگر باشد همراهش می‌آید.
        $awaiting = DB::all(
            "SELECT p.id, p.order_id, p.amount_rial, p.currency, p.created_at, p.expires_at,
                    COALESCE(t.id, 0) AS txn_id, COALESCE(t.amount_rial, 0) AS txn_amount,
                    COALESCE(t.bank, '') AS bank, COALESCE(t.sender, '') AS sender,
                    COALESCE(t.occurred_at, '') AS occurred_at, COALESCE(t.reference, '') AS reference
             FROM payment_sessions p
             LEFT JOIN transactions t ON t.id = p.transaction_id
             WHERE p.store_id = ? AND p.status IN ('pending','verifying')
             ORDER BY p.created_at DESC
             LIMIT 50",
            [$sid]
        );

        // تراکنش‌های بدون تطبیق + دلیل (برای تصمیم فروشنده)
        $unmatched = DB::all(
            "SELECT id, store_id, amount_rial, bank, sender, occurred_at, reference, dest_last4, is_test
             FROM transactions
             WHERE store_id = ? AND status = 'unmatched'
             ORDER BY id DESC
             LIMIT 50",
            [$sid]
        );
        foreach ($unmatched as &$u) {
            $u['reason'] = Matcher::unmatchedReason($u);
        }
        unset($u);

        // پرداخت‌های باز بدون تراکنش (کاندیدای اتصال دستی تراکنش unmatched)
        $open = DB::all(
            "SELECT id, order_id, amount_rial, created_at, expires_at
             FROM payment_sessions
             WHERE store_id = ? AND status = 'pending' AND transaction_id IS NULL AND expires_at > ?
             ORDER BY created_at DESC
             LIMIT 50",
            [$sid, DB::now()]
        );

        Http::apiSuccess([
            'awaiting'  => $awaiting,
            'unmatched' => $unmatched,
            'open'      => $open,
            'server_time' => gmdate('c'),
        ]);
    }

    /** POST /api/v1/devices/confirm — تأیید دستی پرداختِ در انتظار (device auth) */
    public static function deviceConfirm(): void
    {
        $device = self::authDevice();
        if (!RateLimiter::allow('api:confirm:' . $device['id'], 60, 60)) {
            Http::apiError('rate_limited', 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 429);
        }
        $in = Http::jsonInput();
        if (!is_array($in) || (int) ($in['payment_id'] ?? 0) <= 0) {
            Http::apiError('missing_payment', 'شناسه پرداخت الزامی است.', 422);
        }
        $paymentId = (int) $in['payment_id'];
        $payment = Payment::find($paymentId);
        if ($payment === null || !in_array((int) $payment['store_id'], self::deviceStoreIds($device), true)) {
            Http::apiError('forbidden', 'این پرداخت به فروشگاه‌های شما تعلق ندارد.', 403);
        }
        if (!Matcher::confirmManually($paymentId, (int) $payment['store_id'])) {
            Http::apiError('confirm_failed', 'این پرداخت قابل تأیید نیست — فقط پرداخت‌های «در انتظار تأیید» متعلق به فروشگاه شما.', 422);
        }
        Audit::log('payment.confirmed_by_device', 'payment=' . $paymentId . ' device=' . (int) $device['id']);
        $p = Payment::find($paymentId);
        Http::apiSuccess([
            'confirmed' => true,
            'payment'   => ['id' => $paymentId, 'status' => (string) ($p['status'] ?? 'paid'), 'order_id' => (string) ($p['order_id'] ?? '')],
        ]);
    }

    /** POST /api/v1/devices/attach — اتصال دستی تراکنش بدون تطبیق به پرداخت باز (device auth) */
    public static function deviceAttach(): void
    {
        $device = self::authDevice();
        if (!RateLimiter::allow('api:attach:' . $device['id'], 60, 60)) {
            Http::apiError('rate_limited', 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 429);
        }
        $in = Http::jsonInput();
        $txnId = (int) ($in['transaction_id'] ?? 0);
        $payId = (int) ($in['payment_id'] ?? 0);
        if ($txnId <= 0 || $payId <= 0) {
            Http::apiError('missing_ids', 'شناسه تراکنش و پرداخت الزامی است.', 422);
        }
        $txnRow = Transaction::find($txnId);
        $payRow = Payment::find($payId);
        $allowedStores = self::deviceStoreIds($device);
        if ($txnRow === null || $payRow === null
            || !in_array((int) $txnRow['store_id'], $allowedStores, true)
            || !in_array((int) $payRow['store_id'], $allowedStores, true)) {
            Http::apiError('forbidden', 'تراکنش/پرداخت به فروشگاه‌های شما تعلق ندارد.', 403);
        }
        if (!Matcher::attachManually($txnId, $payId, (int) $txnRow['store_id'])) {
            Http::apiError('attach_failed', 'اتصال ممکن نیست — تراکنش/پرداخت متعلق به فروشگاه شما نیست یا وضعیت‌شان اجازه نمی‌دهد.', 422);
        }
        Audit::log('payment.attached_by_device', 'payment=' . $payId . ' txn=' . $txnId . ' device=' . (int) $device['id']);
        $p = Payment::find($payId);
        Http::apiSuccess([
            'attached' => true,
            'payment'  => ['id' => $payId, 'status' => (string) ($p['status'] ?? 'paid'), 'order_id' => (string) ($p['order_id'] ?? '')],
        ]);
    }

    /**
     * دور ۲۵ — POST /api/v1/devices/dismiss : حذف تراکنشِ «نیاز به بررسی» از
     * فهرست بررسی‌ها (× کنار کارت در اپ). تراکنش dismissed می‌شود و از
     * شمارنده‌ها/فهرست‌های بررسی بیرون می‌آید.
     */
    public static function deviceDismiss(): void
    {
        $device = self::authDevice();
        if (!RateLimiter::allow('api:dismiss:' . $device['id'], 60, 60)) {
            Http::apiError('rate_limited', 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 429);
        }
        $in = Http::jsonInput();
        if (!is_array($in) || (int) ($in['transaction_id'] ?? 0) <= 0) {
            Http::apiError('missing_transaction', 'شناسه تراکنش الزامی است.', 422);
        }
        $txnId = (int) $in['transaction_id'];
        $txn = Transaction::find($txnId);
        if ($txn === null || !in_array((int) $txn['store_id'], self::deviceStoreIds($device), true)) {
            Http::apiError('forbidden', 'این تراکنش به فروشگاه‌های شما تعلق ندارد.', 403);
        }
        if (!Matcher::dismissTransaction($txnId, (int) $txn['store_id'])) {
            Http::apiError('dismiss_failed', 'این تراکنش قابل حذف از بررسی‌ها نیست — فقط تراکنش‌های «بدون تطبیق» حذف می‌شوند.', 422);
        }
        Http::apiSuccess(['dismissed' => true, 'transaction' => ['id' => $txnId, 'status' => 'dismissed']]);
    }

    /**
     * دور ۲۵ — POST /api/v1/devices/close : بستن/غیرفعال‌کردن پرداخت باز از اپ.
     * پرداخت failed می‌شود، رویداد cancelled به ووکامرس می‌رود (سفارش لغو می‌شود)
     * و تراکنشِ در حال بررسیِ چسبیده به آن آزاد می‌گردد.
     */
    public static function deviceClose(): void
    {
        $device = self::authDevice();
        if (!RateLimiter::allow('api:close:' . $device['id'], 60, 60)) {
            Http::apiError('rate_limited', 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 429);
        }
        $in = Http::jsonInput();
        if (!is_array($in) || (int) ($in['payment_id'] ?? 0) <= 0) {
            Http::apiError('missing_payment', 'شناسه پرداخت الزامی است.', 422);
        }
        $paymentId = (int) $in['payment_id'];
        $payment = Payment::find($paymentId);
        if ($payment === null || !in_array((int) $payment['store_id'], self::deviceStoreIds($device), true)) {
            Http::apiError('forbidden', 'این پرداخت به فروشگاه‌های شما تعلق ندارد.', 403);
        }
        $res = Matcher::closePayment($paymentId, (int) $payment['store_id']);
        if (!$res['ok']) {
            Http::apiError('close_failed', 'این پرداخت قابل بستن نیست — فقط پرداخت‌های باز «در انتظار تأیید» بسته می‌شوند.', 422);
        }
        $p = Payment::find($paymentId);
        Http::apiSuccess([
            'closed'       => true,
            'woo_notified' => (bool) $res['woo'],
            'payment'      => ['id' => $paymentId, 'status' => (string) ($p['status'] ?? 'failed'), 'order_id' => (string) ($p['order_id'] ?? '')],
        ]);
    }

    // ---------------------------------------------------------- transactions

    /** POST /api/v1/transactions — device upload; matching runs synchronously. */
    public static function uploadTransactions(): void
    {
        $device = self::authDevice(); // active devices only
        Device::touchSeen((int) $device['id']);
        if (!RateLimiter::allow('api:txn:' . $device['id'], 240, 60)) {
            Http::apiError('rate_limited', 'تعداد تراکنش‌های ارسالی زیاد است؛ چند لحظه بعد دوباره تلاش کنید.', 429);
        }
        $in = Http::jsonInput();
        if ($in === null) {
            Http::apiError('bad_json', 'بدنه درخواست JSON معتبر نیست.', 400);
        }
        $items = $in['transactions'] ?? $in['transaction'] ?? null;
        if (!is_array($items) || $items === []) {
            Http::apiError('missing_transactions', 'آرایه transactions الزامی است.', 422);
        }
        if (count($items) > 25) {
            Http::apiError('too_many', 'حداکثر ۲۵ تراکنش در هر درخواست.', 422);
        }

        $results = [];
        $anyMatched = false;
        foreach (array_values($items) as $i => $item) {
            try {
                $tx = self::normalizeTransaction($item);
            } catch (DomainException $e) {
                $results[] = ['index' => $i, 'result' => 'rejected', 'message' => $e->getMessage()];
                continue;
            }
            // ── per-card enforcement (defense in depth alongside the Android pipeline)
            // دور ۲۳: کارت باید متعلق به یکی از فروشگاه‌های «حسابِ» دستگاه باشد؛
            // فروشگاهِ تراکنش از کارت می‌آید (نه از دستگاه) — تطبیق/وب‌هوک همان فروشگاه.
            $cardStoreId = null;
            if (!empty($tx['card_id'])) {
                $card = Card::find((int) $tx['card_id']);
                if ($card === null || (int) $card['is_active'] !== 1 || !in_array((int) $card['store_id'], self::deviceStoreIds($device), true)) {
                    $results[] = ['index' => $i, 'result' => 'rejected', 'message' => 'کارت این تراکنش فعال نیست یا متعلق به فروشگاه‌های شما نیست.'];
                    continue;
                }
                $cardStoreId = (int) $card['store_id'];
            } elseif ((string) ($device['source'] ?? 'pair') !== 'login') {
                // دستگاهِ همراه‌سازی‌شده: کدش برای همین یک فروشگاه صادر شده — unambiguous
                $cardStoreId = (int) $device['store_id'];
            } else {
                // دستگاه حساب‌محور بدون card_id → فروشگاه نامشخص؛ طبق سیاستِ
                // «تخصیص خودکار به فروشگاه دلخواه ممنوع» رد می‌شود.
                $results[] = ['index' => $i, 'result' => 'rejected', 'message' => 'فروشگاه این تراکنش قابل تشخیص نیست — کارت تطبیق‌یافته ارسال نشده است.'];
                continue;
            }
            if (!empty($tx['fingerprint'])) {
                $dup = DB::val('SELECT id FROM transactions WHERE fingerprint = ?', [$tx['fingerprint']]);
                if ($dup !== null) {
                    $results[] = ['index' => $i, 'result' => 'duplicate', 'message' => 'این تراکنش قبلاً ثبت شده است (اثر انگشت یکسان).'];
                    continue;
                }
            }
            try {
                $res  = Matcher::process($tx, $device, false, $cardStoreId);
            } catch (Throwable $e) {
                Log::error('matcher failure: ' . $e->getMessage());
                $results[] = ['index' => $i, 'result' => 'error', 'message' => 'خطای داخلی در پردازش تراکنش.'];
                continue;
            }
            $anyMatched = $anyMatched || in_array($res['result'], ['matched', 'matched_pending'], true);
            $results[] = [
                'index'         => $i,
                'result'        => $res['result'],
                'transaction_id'=> (int) $res['transaction']['id'],
                'payment_id'    => $res['payment'] !== null ? (int) $res['payment']['id'] : null,
                'order_id'      => $res['payment'] !== null ? (string) $res['payment']['order_id'] : null,
                'payment_status'=> $res['payment']['status'] ?? null,
                // چرا تطبیق نخورد؟ (فارسی، برای نمایش در اپ/پنل)
                'message'       => $res['result'] === 'unmatched' ? ($res['reason'] ?? null) : null,
            ];
        }
        Device::touchSms((int) $device['id']);
        Device::touchSync((int) $device['id']);
        Http::apiSuccess(['results' => $results, 'matched' => $anyMatched]);
    }

    // ------------------------------------------------------------- internals

    /** @throws DomainException */
    private static function normalizeTransaction(array $item): array
    {
        $amount = (int) ($item['amount_rial'] ?? 0);
        if ($amount <= 0 || $amount > 100000000000) {
            throw new DomainException('مبلغ تراکنش نامعتبر است.');
        }
        $rawText = (string) ($item['raw_text'] ?? '');
        if (mb_strlen($rawText) > 1600) {
            $rawText = mb_substr($rawText, 0, 1600);
        }
        $destDigits = preg_replace('#\D#', '', (string) ($item['dest_last4'] ?? '')) ?? '';
        $fingerprint = (string) ($item['fingerprint'] ?? '');
        if ($fingerprint !== '' && (!ctype_xdigit($fingerprint) || strlen($fingerprint) !== 64)) {
            $fingerprint = '';
        }
        return [
            'card_id'     => (int) ($item['card_id'] ?? 0) > 0 ? (int) $item['card_id'] : null,
            'fingerprint' => $fingerprint !== '' ? $fingerprint : null,
            'currency'    => ($item['currency'] ?? '') === 'IRT' ? 'IRT' : 'IRR',
            'bank'        => (string) ($item['bank'] ?? ''),
            'sender'      => (string) ($item['sender'] ?? ''),
            'amount_rial' => $amount,
            'occurred_at' => self::toUtc((string) ($item['occurred_at'] ?? ''), (int) ($item['sms_time'] ?? 0)),
            'dest_last4'  => $destDigits !== '' ? substr($destDigits, -4) : '',
            'reference'   => trim((string) ($item['reference'] ?? '')),
            'raw_text'    => $rawText,
            'raw_hash'    => isset($item['raw_hash']) && ctype_xdigit((string) $item['raw_hash']) && strlen((string) $item['raw_hash']) === 64
                ? (string) $item['raw_hash']
                : null,
        ];
    }

    /**
     * Timestamp normalization for device uploads.
     * Accepted: ISO-8601 WITH explicit offset/Z (recommended for the Android
     * app — unambiguous), bare "Y-m-d H:i:s" (interpreted as UTC), or an epoch
     * via sms_time. Anything unparseable falls back to the epoch.
     */
    private static function toUtc(string $value, int $fallbackEpoch): string
    {
        $value = trim($value);
        if ($value !== '') {
            try {
                $hasOffset = str_contains($value, '+') || str_ends_with($value, 'Z') || str_contains($value, ' -');
                $dt = new DateTimeImmutable($value, $hasOffset ? null : new DateTimeZone('UTC'));
                return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (Throwable) {
                // fall through to epoch
            }
        }
        $epoch = $fallbackEpoch > 0 ? $fallbackEpoch : time();
        return gmdate('Y-m-d H:i:s', $epoch);
    }

    private static function paymentView(array $p, ?array $card, bool $withTransaction = false): array
    {
        $view = [
            'id'               => (int) $p['id'],
            'token'            => (string) $p['token'],
            'url'              => Config::url('pay/' . $p['token']),
            'order_id'         => (string) $p['order_id'],
            'status'           => (string) $p['status'],
            'amount_rial'      => (int) $p['amount_rial'],
            'amount_base_rial' => (int) $p['amount_base_rial'],
            'amount_display'   => (int) $p['amount_display'],
            'currency'         => (string) $p['currency'],
            'expires_at'       => (string) $p['expires_at'],
            'expires_in'       => max(0, strtotime((string) $p['expires_at']) - time()),
            'test'             => (int) $p['is_test'] === 1,
        ];
        if ($card !== null) {
            $view['card'] = [
                'bank'   => (string) $card['bank_name'],
                'holder' => (string) $card['holder'],
                'masked' => Crypto::maskCardPretty((string) $card['number']),
            ];
        }
        if ($withTransaction && $p['transaction_id'] !== null) {
            $txn = Transaction::find((int) $p['transaction_id']);
            if ($txn !== null) {
                $view['transaction'] = [
                    'id' => (int) $txn['id'], 'amount_rial' => (int) $txn['amount_rial'],
                    'reference' => (string) $txn['reference'], 'bank' => (string) $txn['bank'],
                    'occurred_at' => (string) $txn['occurred_at'],
                ];
            }
        }
        return $view;
    }

    // ------------------------------------------------------------ auth gates

    /**
     * خواندن هدر با نام جدید (X-Peakopay-*) و پذیرش نام قدیمی
     * (X-HamrahPay-*) برای سازگاری با افزونه ووکامرس نصب‌شده قبل از تغییر نام.
     */
    private static function headerAny(string $new, string $legacy): ?string
    {
        $v = Http::header($new);
        if ($v === null || $v === '') {
            $v = Http::header($legacy);
        }
        return $v;
    }

    /** @return array{store:array,key:array} */
    private static function authStore(): array
    {
        $storeId = (string) (self::headerAny('X-Peakopay-Store', 'X-HamrahPay-Store') ?? '');
        $bearer  = (string) (Http::header('Authorization') ?? '');
        $key     = '';
        if (preg_match('#^Bearer\s+(.+)$#i', $bearer, $m)) {
            $key = trim($m[1]);
        } elseif ($key === '') {
            $key = (string) (self::headerAny('X-Peakopay-Key', 'X-HamrahPay-Key') ?? '');
        }
        // پشتیبان: برخی هاست‌ها (CGI/FastCGI یا ModSecurity) هدرهای سفارشی را حذف می‌کنند —
        // همان اعتبارنامه‌ها در بدنه JSON هم پذیرفته می‌شوند (امنیت روی HTTPS یکسان است).
        if ($storeId === '' || $key === '') {
            $body = Http::jsonInput();
            if (is_array($body)) {
                $bStore = trim((string) ($body['store_id'] ?? ''));
                $bKey   = trim((string) ($body['api_key'] ?? ''));
                if ($storeId === '' && $bStore !== '') {
                    $storeId = $bStore;
                }
                if ($key === '' && $bKey !== '') {
                    $key = $bKey;
                }
            }
        }
        if ($storeId === '' || $key === '') {
            Audit::log('api.auth_failed', 'missing-credentials path=' . Http::path() . ' ua=' . mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80));
            Http::apiError('unauthorized', 'شناسه فروشگاه یا کلید API ارسال نشده است.', 401);
        }
        $auth = ApiKey::verify($storeId, $key);
        if ($auth === null) {
            if (!RateLimiter::allow('api:badkey:' . Http::ip(), 30, 300)) {
                Http::apiError('rate_limited', 'تلاش‌های ناموفق زیاد است.', 429);
            }
            Log::warn('api auth failed (store)');
            Audit::log('api.auth_failed', 'bad-key store=' . mb_substr($storeId, 0, 24) . ' path=' . Http::path());
            Http::apiError('unauthorized', 'شناسه فروشگاه یا کلید API نامعتبر است.', 401);
        }
        // دور ۲۳ — اولین درخواست موفق ووکامرس یعنی فروشگاه متصل است
        try {
            $sidNum = (int) ($auth['store']['id'] ?? 0);
            if ($sidNum > 0) {
                DB::q("UPDATE stores SET connection_status = 'connected' WHERE id = ? AND connection_status <> 'connected'", [$sidNum]);
            }
        } catch (Throwable) {
        }
        return $auth;
    }

    private static function authDevice(bool $allowPending = false): array
    {
        $devId = (string) (self::headerAny('X-Peakopay-Device', 'X-HamrahPay-Device') ?? '');
        $ts    = (string) (self::headerAny('X-Peakopay-Timestamp', 'X-HamrahPay-Timestamp') ?? '');
        $sig   = (string) (self::headerAny('X-Peakopay-Signature', 'X-HamrahPay-Signature') ?? '');
        if ($devId === '' || $ts === '' || $sig === '') {
            Http::apiError('unauthorized', 'اطلاعات احراز هویت دستگاه کامل نیست.', 401);
        }
        if (!ctype_digit($devId) || !ctype_digit($ts)) {
            Http::apiError('unauthorized', 'اطلاعات احراز هویت دستگاه نامعتبر است.', 401);
        }
        if (abs(time() - (int) $ts) > self::TS_WINDOW) {
            Http::apiError('stale_request', 'زمان درخواست با سرور هم‌خوان نیست. ساعت دستگاه را بررسی کنید.', 401);
        }
        $device = Device::find((int) $devId);
        if ($device === null) {
            Http::apiError('unauthorized', 'دستگاه یافت نشد؛ دوباره همراه‌سازی کنید.', 401);
        }
        if ($device['status'] === 'revoked') {
            Http::apiError('device_revoked', 'دسترسی این دستگاه از پنل مدیریت لغو شده است.', 403);
        }
        if ($device['status'] === 'pending' && !$allowPending) {
            Http::apiError('device_pending', 'این دستگاه هنوز توسط مدیر فروشگاه تأیید نشده است.', 403);
        }
        $secret = Device::secret((int) $devId);
        if ($secret === null) {
            Http::apiError('unauthorized', 'اعتبارسنجی دستگاه ممکن نیست.', 401);
        }
        $expected = Crypto::hmac($ts . '.' . Http::rawBody(), $secret);
        if (!Crypto::equal($expected, strtolower($sig))) {
            if (!RateLimiter::allow('api:badsig:' . $devId, 30, 300)) {
                Http::apiError('rate_limited', 'تلاش‌های ناموفق زیاد است.', 429);
            }
            Log::warn('device signature mismatch', ['device' => (int) $devId]);
            Http::apiError('unauthorized', 'امضای درخواست نامعتبر است.', 401);
        }
        return $device;
    }

    // ------------------------------------------------- multi-store scoping (دور ۲۳)

    /** فروشگاه‌های در دسترس دستگاه: حساب دستگاه وگرنه فقط فروشگاه خودش. */
    private static function deviceStoreIds(array $device): array
    {
        $accountId = (int) ($device['account_id'] ?? 0);
        if ($accountId > 0) {
            $ids = Account::storeIds($accountId);
            if ($ids !== []) {
                return $ids;
            }
        }
        return [(int) $device['store_id']];
    }

    /**
     * فروشگاه کاری برای endpointهای مدیریت کارت/داشبورد — پارامتر store_id
     * (از query یا بدنه، که caller پاس می‌دهد) فقط وقتی پذیرفته می‌شود که در
     * فروشگاه‌های حسابِ دستگاه باشد؛ در غیر این صورت ۴۰۳. مرز tenant-isolation.
     */
    private static function deviceStore(array $device, int $requested = 0): int
    {
        $allowed = self::deviceStoreIds($device);
        if ($requested > 0) {
            if (!in_array($requested, $allowed, true)) {
                Http::apiError('forbidden', 'این فروشگاه به این دستگاه/حساب تعلق ندارد.', 403);
            }
            return $requested;
        }
        return (int) $device['store_id'];
    }
}
