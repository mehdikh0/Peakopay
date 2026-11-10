<?php
/**
 * Peakopay — مدل ورود دومرحله‌ای (TOTP + کدهای بازیابی + چالش‌های ورود).
 *
 * اصول امنیتی:
 *  - راز TOTP هرگز خام ذخیره نمی‌شود — همیشه Crypto::encrypt (AES-256-GCM /
 *    secretbox با کلید نصب) و فقط قبل از فعال‌سازی (برای نمایش QR) برمی‌گردد.
 *  - کدهای بازیابی فقط به‌صورت هش SHA-256 ذخیره می‌شوند؛ متن خام فقط یک بار
 *    هنگام تولید به کاربر نشان داده می‌شود.
 *  - جلوگیری از Replay: شمارهٔ گامِ پذیرفته‌شده (last_step) ذخیره می‌شود و هر
 *    گام فقط یک بار پذیرفته می‌شود.
 *  - چالش ورود: توکن تصادفی ۲۵۶ بیتی در نشست؛ فقط هش آن در دیتابیس.
 */
declare(strict_types=1);

final class TwoFactor
{
    public const CHALLENGE_TTL   = 600;   // ۱۰ دقیقه برای وارد کردن کد
    public const MAX_ATTEMPTS    = 5;     // سقف تلاش برای هر چالش
    public const RATE_MAX        = 5;     // حداکثر تلاش ناموفق…
    public const RATE_WINDOW     = 300;   // …در ۵ دقیقه (هر کاربر)
    public const RECOVERY_COUNT  = 10;    // تعداد کدهای بازیابی
    public const RESET_MIN_REASON = 10;   // حداقل طول دلیل ریست ادمینی

    // ---------------------------------------------------------- وضعیت

    /** آیا 2FA برای این کاربر «فعال» است (رمز عبور تأیید شده + کد اول تأیید شده). */
    public static function isEnabled(int $userId): bool
    {
        try {
            $row = DB::one('SELECT enabled FROM user_2fa WHERE user_id = ?', [$userId]);
            return $row !== null && (int) $row['enabled'] === 1;
        } catch (Throwable $e) {
            return false; // جدول هنوز مهاجرت نشده — مثل «بدون 2FA» رفتار کن
        }
    }

    /** ردیف پیکربندی (یا null). */
    public static function row(int $userId): ?array
    {
        try {
            return DB::one('SELECT * FROM user_2fa WHERE user_id = ?', [$userId]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** راز TOTP فقط وقتی هنوز فعال نشده (برای نمایش QR). فعال‌شده هرگز برنمی‌گردد. */
    public static function pendingSecret(int $userId): ?string
    {
        $row = self::row($userId);
        if ($row === null || (int) $row['enabled'] === 1) {
            return null;
        }
        return Crypto::decrypt((string) $row['secret_enc']);
    }

    // ---------------------------------------------------------- ثبت‌نام

    /**
     * ساخت راز تازه (شروع ثبت‌نام یا تعویض احرازگر). راز قبلی بی‌اثر می‌شود.
     * توجه: اگر 2FA فعال بود، فراخواننده باید قبل از این، رمز + کد فعلی را
     * تأیید کرده باشد (تعویض امن احرازگر).
     */
    public static function enroll(int $userId): string
    {
        $secret = Totp::generateSecret(20);
        $now    = DB::now();
        $enc    = Crypto::encrypt($secret);
        DB::q(
            'INSERT INTO user_2fa (user_id, secret_enc, enabled, enabled_at, last_step, created_at, updated_at)
             VALUES (?, ?, 0, NULL, 0, ?, ?)
             ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc), enabled = 0,
               enabled_at = NULL, last_step = 0, updated_at = VALUES(updated_at)',
            [$userId, $enc, $now, $now],
        );
        Audit::log('2fa.enroll', 'user=' . $userId . ' pending-activation');
        return $secret;
    }

    /**
     * فعال‌سازی: اولین کد باید درست باشد. در صورت موفقیت کدهای بازیابی تازه
     * ساخته می‌شوند و متن خام آنها برگردد (فقط همین یک بار).
     *
     * @return array<int, string>|false لیست کدهای بازیابی، یا false
     */
    public static function activate(int $userId, string $code): array|false
    {
        $row = self::row($userId);
        if ($row === null || (int) $row['enabled'] === 1) {
            return false;
        }
        $secret = Crypto::decrypt((string) $row['secret_enc']);
        if ($secret === null) {
            return false;
        }
        $step = Totp::verify($secret, $code, null, (int) $row['last_step']);
        if ($step === false) {
            return false;
        }
        DB::update('user_2fa', [
            'enabled'    => 1,
            'enabled_at' => DB::now(),
            'last_step'  => $step,
            'updated_at' => DB::now(),
        ], 'user_id = ?', [$userId]);
        Audit::log('2fa.activated', 'user=' . $userId);
        return self::generateRecoveryCodes($userId);
    }

    /**
     * اعتبارسنجی کد در ورود (یا تأیید عملیات حساس). با موفقیت، گام مصرف
     * می‌شود (Replay بعدی همان کد رد می‌شود).
     */
    public static function verifyCode(int $userId, string $code): bool
    {
        $row = self::row($userId);
        if ($row === null || (int) $row['enabled'] !== 1) {
            return false;
        }
        $secret = Crypto::decrypt((string) $row['secret_enc']);
        if ($secret === null) {
            return false;
        }
        $step = Totp::verify($secret, $code, null, (int) $row['last_step']);
        if ($step === false) {
            return false;
        }
        DB::update('user_2fa', ['last_step' => $step, 'updated_at' => DB::now()], 'user_id = ?', [$userId]);
        return true;
    }

    /** خاموش کردن 2FA (فراخواننده: رمز + کد فعلی را تأیید کرده). */
    public static function disable(int $userId): void
    {
        DB::q('DELETE FROM user_2fa WHERE user_id = ?', [$userId]);
        DB::q('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
        Audit::log('2fa.disabled', 'user=' . $userId);
    }

    // ---------------------------------------------------------- بازیابی

    /** ساخت ۱۰ کد بازیابی تازه — قبلی‌ها همه باطل می‌شوند. متن خام فقط یک بار برمی‌گردد. */
    public static function generateRecoveryCodes(int $userId): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // بدون I/O/0/1 — خوانا و بی‌ابهام
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 15; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                if ($j % 5 === 4 && $j < 14) {
                    $raw .= '-';
                }
            }
            $codes[] = $raw;
        }
        DB::q('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
        $now = DB::now();
        foreach ($codes as $c) {
            DB::insertIgnore('user_recovery_codes', [
                'user_id'    => $userId,
                'code_hash'  => hash('sha256', self::normalizeRecovery($c)),
                'created_at' => $now,
            ]);
        }
        Audit::log('2fa.recovery_regenerated', 'user=' . $userId . ' count=' . self::RECOVERY_COUNT);
        return $codes;
    }

    /** تعداد کدهای بازیابی مصرف‌نشده. */
    public static function recoveryRemaining(int $userId): int
    {
        try {
            return (int) (DB::val(
                'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL',
                [$userId],
            ) ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * مصرف یک کد بازیابی (یک‌بارمصرف، مقایسهٔ زمان-ثابت، اتمیک).
     * فراخواننده باید رمز عبور را هم تأیید کرده باشد.
     */
    public static function consumeRecoveryCode(int $userId, string $code): bool
    {
        $norm = self::normalizeRecovery($code);
        if ($norm === '') {
            return false;
        }
        $hash = hash('sha256', $norm);
        $rows = DB::all(
            'SELECT id, code_hash FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId],
        );
        foreach ($rows as $r) {
            if (hash_equals((string) $r['code_hash'], $hash)) {
                // فقط اگر همین الان مصرف نشده باشد (اتمیک)
                $stmt = DB::q(
                    'UPDATE user_recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL',
                    [DB::now(), (int) $r['id']],
                );
                if ($stmt->rowCount() > 0) {
                    Audit::log('2fa.recovery_used', 'user=' . $userId . ' code#' . (int) $r['id']);
                    return true;
                }
                return false; // هم‌زمانی — این کد همین الان مصرف شد
            }
        }
        return false;
    }

    private static function normalizeRecovery(string $code): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        return $c === '' ? '' : $c;
    }

    // ---------------------------------------------------------- چالش ورود

    /** ساخت چالش بعد از تأیید رمز عبور — توکن خام به نشست فراخواننده برمی‌گردد. */
    public static function createChallenge(int $userId): string
    {
        self::purgeExpired();
        $token = bin2hex(random_bytes(32));
        DB::insertIgnore('auth_challenges', [
            'token_hash' => hash('sha256', $token),
            'user_id'    => $userId,
            'attempts'   => 0,
            'ip'         => Http::ip(),
            'expires_at' => date('Y-m-d H:i:s', time() + self::CHALLENGE_TTL),
            'created_at' => DB::now(),
        ]);
        return $token;
    }

    /** ردیف چالش معتبر (اگر منقضی/تلاش‌شده نباشد). */
    public static function challenge(string $token): ?array
    {
        $row = DB::one('SELECT * FROM auth_challenges WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($row === null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            DB::q('DELETE FROM auth_challenges WHERE token_hash = ?', [hash('sha256', $token)]);
            return null;
        }
        return $row;
    }

    /** ثبت یک تلاش ناموفق؛ با رسیدن به سقف، چالش می‌میرد. */
    public static function failChallenge(string $token): void
    {
        $h = hash('sha256', $token);
        DB::q('UPDATE auth_challenges SET attempts = attempts + 1 WHERE token_hash = ?', [$h]);
        $row = DB::one('SELECT attempts FROM auth_challenges WHERE token_hash = ?', [$h]);
        if ($row !== null && (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            DB::q('DELETE FROM auth_challenges WHERE token_hash = ?', [$h]);
        }
    }

    /** حذف چالش + برگرداندن شناسهٔ کاربر (تکمیل ورود). */
    public static function resolveChallenge(string $token): ?int
    {
        $h = hash('sha256', $token);
        $row = DB::one('SELECT user_id FROM auth_challenges WHERE token_hash = ?', [$h]);
        if ($row === null) {
            return null;
        }
        DB::q('DELETE FROM auth_challenges WHERE token_hash = ?', [$h]);
        return (int) $row['user_id'];
    }

    public static function purgeExpired(): void
    {
        try {
            DB::q('DELETE FROM auth_challenges WHERE expires_at < ?', [DB::now()]);
        } catch (Throwable $e) {
            // بی‌ضرر — دفعهٔ بعد
        }
    }

    /** سقف نرخ تلاش‌های ناموفق کد (ضد brute-force). */
    public static function rateLimited(int $userId): bool
    {
        return !RateLimiter::allow('2fa:' . $userId, self::RATE_MAX, self::RATE_WINDOW);
    }

    public static function clearRate(int $userId): void
    {
        RateLimiter::clear('2fa:' . $userId);
    }

    // ---------------------------------------------------------- سیاست

    /** سیاست «2FA الزامی برای مدیران» — پیش‌فرض خاموش (قفل‌شدن ناخواسته نمی‌سازد). */
    public static function enforcedFor(array $user): bool
    {
        $role = (string) ($user['role'] ?? '');
        return in_array($role, ['owner', 'admin'], true) && Setting::bool('twofactor_required_admins', false);
    }

    // ---------------------------------------------------------- ابطال نشست

    /** همهٔ نشست‌های کاربر را باطل می‌کند (نشست‌های با login_at قدیمی‌تر می‌میرند). */
    public static function revokeSessions(int $userId): void
    {
        DB::q(
            'INSERT INTO user_session_revocations (user_id, revoked_at) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE revoked_at = VALUES(revoked_at)',
            [$userId, DB::now()],
        );
    }

    /** اگر نشست‌های این کاربر ابطل شده‌اند: زمان ابطال (timestamp) یا null. */
    public static function revokedAfter(int $userId): ?int
    {
        try {
            $v = DB::val('SELECT revoked_at FROM user_session_revocations WHERE user_id = ?', [$userId]);
            return $v === null || $v === '' ? null : (int) strtotime((string) $v);
        } catch (Throwable $e) {
            return null;
        }
    }

    // ---------------------------------------------------------- ریست ادمینی

    /**
     * ریست 2FA توسط مدیر مجاز (owner). فراخواننده: نقش + CSRF + رمز ادمین را
     * تأیید کرده. کاربر با ورود بعدی باید احرازگر جدید پیکربندی کند.
     */
    public static function adminReset(array $admin, int $targetUserId, string $reason): bool
    {
        $target = DB::one('SELECT id, name, email, store_id FROM users WHERE id = ?', [$targetUserId]);
        if ($target === null || !self::isEnabled($targetUserId)) {
            return false;
        }
        DB::q('DELETE FROM user_2fa WHERE user_id = ?', [$targetUserId]);
        DB::q('DELETE FROM user_recovery_codes WHERE user_id = ?', [$targetUserId]);
        self::revokeSessions($targetUserId);
        DB::insert('twofactor_resets', [
            'admin_user_id'  => (int) $admin['id'],
            'target_user_id' => $targetUserId,
            'reason'         => mb_substr(trim($reason), 0, 480),
            'ip'             => Http::ip(),
            'created_at'     => DB::now(),
        ]);
        // اعلان به کاربر: بنر درون‌پنل از مجرای twofactor_resets.seen_at
        // (layout_admin می‌بیندد) — طبق تصمیم محصول، کانال ایمیل برای ریست حذف شد.
        Audit::log('2fa.admin_reset', 'admin=' . (int) $admin['id'] . ' target=' . $targetUserId . ' reason=' . mb_substr(trim($reason), 0, 200));
        return true;
    }

    /** آخرین ریست ادمینی دیده‌نشدهٔ این کاربر (برای اعلان درون‌پنل). */
    public static function unseenReset(int $userId): ?array
    {
        try {
            return DB::one(
                'SELECT r.*, a.name AS admin_name FROM twofactor_resets r
                 LEFT JOIN users a ON a.id = r.admin_user_id
                 WHERE r.target_user_id = ? AND r.seen_at IS NULL ORDER BY r.id DESC LIMIT 1',
                [$userId],
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function markResetsSeen(int $userId): void
    {
        try {
            DB::q('UPDATE twofactor_resets SET seen_at = ? WHERE target_user_id = ? AND seen_at IS NULL', [DB::now(), $userId]);
        } catch (Throwable $e) {
            // بی‌ضرر
        }
    }
}
