<?php
/**
 * Peakopay — Android devices + short-lived pairing codes.
 *
 * Device secrets are encrypted at rest (they are HMAC keys, so the server must
 * be able to verify request signatures). Pairing codes are stored hashed and
 * are single-use with a 10-minute lifetime.
 */
declare(strict_types=1);

final class Device
{
    public const CODE_TTL_MINUTES = 10;

    // ------------------------------------------------------------ pairing

    /** Create a short-lived pairing code for the store. Returns [code, expiresAt]. */
    public static function issuePairingCode(int $storeId): array
    {
        // invalidate old unused codes for this store
        DB::q('UPDATE pairing_codes SET used_at = ? WHERE store_id = ? AND used_at IS NULL', [DB::now(), $storeId]);
        $code = Crypto::newPairingCode(8);
        $exp  = gmdate('Y-m-d H:i:s', time() + self::CODE_TTL_MINUTES * 60);
        DB::insert('pairing_codes', [
            'store_id'   => $storeId,
            'code_hash'  => Crypto::sha256($code),
            'expires_at' => $exp,
            'created_at' => DB::now(),
        ]);
        return [$code, $exp];
    }

    /**
     * Exchange a pairing code for device credentials. The device starts as
     * PENDING and must be approved from the admin panel.
     *
     * @return array{id:int,secret:string,store:string}  throws on invalid code
     */
    /**
     * Exchange a pairing code for device credentials. The device starts as
     * «pending» and an admin approves it from the panel.
     *
     * دور ۲۵ — افزودن فروشگاه (چنداکانتی): اگر دستگاهِ هم‌اکنون متصل و فعال،
     * اعتبارنامهٔ خودش (device_id+device_secret) را همراه کد بفرستد، به‌جای
     * ساختن ردیف جدید، همان دستگاه به فروشگاه/حسابِ کد جدید pointing می‌شود
     * (وضعیت active و secret قبلی حفظ می‌شود) — مثل افزودن اکانت در واتساپ.
     */
    public static function pair(string $code, string $name, string $model, string $appVersion, string $androidVersion, ?int $upgradeDeviceId = null, ?string $upgradeSecret = null): array
    {
        $code  = strtoupper(preg_replace('#[^A-Za-z0-9]#', '', Helpers::enDigits(trim($code))) ?? '');
        $hash  = Crypto::sha256($code);
        $row   = DB::one('SELECT * FROM pairing_codes WHERE code_hash = ? AND used_at IS NULL AND expires_at > ?', [$hash, DB::now()]);
        if ($row === null) {
            throw new RuntimeException('کد همراه‌سازی نامعتبر یا منقضی شده است.');
        }
        $store = DB::one('SELECT * FROM stores WHERE id = ?', [$row['store_id']]);
        if ($store === null) {
            throw new RuntimeException('فروشگاه یافت نشد.');
        }

        // ── ارتقای دستگاه موجود (افزودن فروشگاه به همین گوشی) ──
        $upgrade = null;
        if ($upgradeDeviceId !== null && $upgradeDeviceId > 0 && $upgradeSecret !== null && $upgradeSecret !== '') {
            $existing = Device::find($upgradeDeviceId);
            if ($existing !== null && $existing['status'] !== 'revoked' && Device::secret($upgradeDeviceId) === $upgradeSecret) {
                $upgrade = $existing;
            } else {
                throw new RuntimeException('اعتبارنامهٔ دستگاه فعلی معتبر نیست؛ دوباره وارد شوید.');
            }
        }

        $secret = Crypto::newDeviceSecret();
        $now    = DB::now();
        if ($upgrade !== null) {
            // همان دستگاه، فروشگاه/حساب جدید — secret و تاریخ تأیید قبلی حفظ می‌شود
            DB::update('devices', [
                'store_id'        => (int) $row['store_id'],
                'account_id'      => $store['account_id'] !== null ? (int) $store['account_id'] : null,
                'app_version'     => mb_substr($appVersion, 0, 20),
                'android_version' => mb_substr($androidVersion, 0, 20),
            ], 'id = ?', [(int) $upgrade['id']]);
            DB::q('UPDATE pairing_codes SET used_at = ? WHERE id = ?', [$now, $row['id']]);
            Audit::log('device.store_added', 'device=' . (int) $upgrade['id'] . ' store=' . (int) $row['store_id'] . ' name=' . $name);
            return [
                'id'        => (int) $upgrade['id'],
                'secret'    => $upgradeSecret ?: Device::secret((int) $upgrade['id']) ?: $secret,
                'store'     => (string) $store['name'],
                'store_id'  => (int) $store['id'],
                'status'    => (string) $upgrade['status'],
                'added'     => true,
            ];
        }

        $deviceId = DB::insert('devices', [
            'store_id'        => (int) $row['store_id'],
            // دور ۲۳ — دستگاهِ همراه‌سازی‌شده هم به حسابِ فروشگاه متصل است
            // (چندفروشگاهی: پیکربندی کارت‌های همهٔ فروشگاه‌های حساب را می‌گیرد)
            'account_id'      => $store['account_id'] !== null ? (int) $store['account_id'] : null,
            'name'            => mb_substr($name ?: 'دستگاه بدون نام', 0, 120),
            'model'           => mb_substr($model, 0, 120),
            'android_version' => mb_substr($androidVersion, 0, 20),
            'app_version'     => mb_substr($appVersion, 0, 20),
            'status'          => 'pending',
            'secret_enc'      => Crypto::encrypt($secret),
            'paired_at'       => $now,
        ]);
        DB::q('UPDATE pairing_codes SET used_at = ? WHERE id = ?', [$now, $row['id']]);
        Audit::log('device.paired', 'device=' . $deviceId . ' name=' . $name);
        return ['id' => $deviceId, 'secret' => $secret, 'store' => (string) $store['name'], 'store_id' => (int) $store['id'], 'status' => 'pending'];
    }

    // ------------------------------------------------------------- queries

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM devices WHERE id = ?', [$id]);
    }

    public static function secret(int $deviceId): ?string
    {
        $row = DB::one('SELECT secret_enc FROM devices WHERE id = ?', [$deviceId]);
        return $row === null ? null : Crypto::decrypt((string) $row['secret_enc']);
    }

    public static function all(int $storeId): array
    {
        // دستگاه‌های این فروشگاه + دستگاه‌های حساب‌محورِ همین حساب (دور ۲۳) —
        // دستگاهِ «ورود با حساب» کارت‌های همهٔ فروشگاه‌های حساب را پردازش می‌کند.
        $accountId = DB::val('SELECT account_id FROM stores WHERE id = ?', [$storeId]);
        if ($accountId !== null && (int) $accountId > 0) {
            return DB::all(
                'SELECT * FROM devices WHERE store_id = ? OR account_id = ? ORDER BY id DESC',
                [$storeId, (int) $accountId],
            );
        }
        return DB::all('SELECT * FROM devices WHERE store_id = ? ORDER BY id DESC', [$storeId]);
    }

    public static function setStatus(int $id, int $storeId, string $status): bool
    {
        // دور ۲۳ — دستگاه حساب‌محور هم از هر صفحهٔ فروشگاهِ همان حساب قابل مدیریت است
        $ok = DB::update('devices', [
            'status'      => $status,
            'approved_at' => $status === 'active' ? DB::now() : null,
        ], 'id = ? AND (store_id = ? OR account_id = (SELECT account_id FROM stores WHERE id = ?))', [$id, $storeId, $storeId]) > 0;
        if ($ok) {
            Audit::log('device.' . $status, 'device=' . $id);
        }
        return $ok;
    }

    public static function rename(int $id, int $storeId, string $name): void
    {
        DB::update('devices', ['name' => mb_substr($name, 0, 120)], 'id = ? AND store_id = ?', [$id, $storeId]);
        Audit::log('device.rename', 'device=' . $id . ' name=' . $name);
    }

    /** Rotate the device secret — revokes old credentials immediately. */
    public static function rotateSecret(int $id): ?string
    {
        $secret = Crypto::newDeviceSecret();
        DB::update('devices', ['secret_enc' => Crypto::encrypt($secret)], 'id = ?', [$id]);
        return $secret;
    }

    public static function touchSeen(int $id): void
    {
        DB::update('devices', ['last_seen_at' => DB::now()], 'id = ?', [$id]);
    }

    public static function touchSms(int $id): void
    {
        DB::update('devices', ['last_sms_at' => DB::now()], 'id = ?', [$id]);
    }

    public static function touchSync(int $id): void
    {
        DB::update('devices', ['last_sync_at' => DB::now()], 'id = ?', [$id]);
    }
}
