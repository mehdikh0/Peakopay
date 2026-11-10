<?php
/**
 * Peakopay — unit tests: ورود دومرحله‌ای (TOTP + کد QR + کدهای بازیابی + چالش‌ها).
 *
 * تست‌بردارهای RFC 6238/4226 به‌صورت محلی + ویژگی‌های امنیتی مدل TwoFactor.
 */
declare(strict_types=1);

section('Totp — Base32');

t('base32 encode RFC 4648 vector', Totp::base32Encode("\x00") === 'AA' && Totp::base32Encode("\xFF") === '74');
t('base32 roundtrip 20 bytes', (static function (): bool {
    for ($i = 0; $i < 20; $i++) {
        $b = random_bytes(20);
        if (Totp::base32Decode(Totp::base32Encode($b)) !== $b) {
            return false;
        }
    }
    return true;
})());
t('base32 decode tolerates spaces/lowercase', Totp::base32Decode('jbsw y3dp ehpk 3pxp') === Totp::base32Decode('JBSWY3DPEHPK3PXP'));
eq('base32 of known vector', 'JBSWY3DPEHPK3PXP', Totp::base32Encode(hex2bin('48656c6c6f21deadbeef')));

section('Totp — RFC 4226/6238 test vectors');

// راز استاندارد تست: ASCII "12345678901234567890"
$rfcSecret = Totp::base32Encode('12345678901234567890');
eq('rfc secret base32', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $rfcSecret);
// بردارهای RFC 6238 (HMAC-SHA1) — ۸ رقمی؛ ۶ رقمی = ۶ رقم آخر همان مقدار است
$vectors = [
    59          => '94287082',
    1111111109  => '07081804',
    1111111111  => '14050471',
    1234567890  => '89005924',
    2000000000  => '69279037',
    20000000000 => '65353130',
];
foreach ($vectors as $time => $code8) {
    eq("rfc6238 t=$time (8-digit)", $code8, Totp::codeAt($rfcSecret, $time, 8));
    eq("rfc6238 t=$time (6-digit)", substr($code8, 2), Totp::codeAt($rfcSecret, $time, 6));
}

section('Totp — verify/drift/replay/format');

$secret = Totp::generateSecret(20);
t('generated secret is 32 base32 chars (160-bit)', strlen($secret) === 32 && preg_match('/^[A-Z2-7]+$/', $secret) === 1);
$now = 1700000000;
$code = Totp::codeAt($secret, $now);
t('exact-time code accepted', Totp::verify($secret, $code, $now) !== false);
t('code with spaces/hyphens accepted', Totp::verify($secret, ' ' . substr($code, 0, 3) . '-' . substr($code, 3) . ' ', $now) !== false);
t('previous-window code accepted (drift -30s)', Totp::verify($secret, Totp::codeAt($secret, $now - 30), $now) !== false);
t('next-window code accepted (drift +30s)', Totp::verify($secret, Totp::codeAt($secret, $now + 30), $now) !== false);
t('two-windows-ago code rejected (drift limit)', Totp::verify($secret, Totp::codeAt($secret, $now - 60), $now) === false);
$wrong = strrev($code);
t('wrong code rejected', Totp::verify($secret, $wrong === $code ? '000000' : $wrong, $now) === false);
t('non-numeric rejected', Totp::verify($secret, 'abc12x', $now) === false);
t('5-digit rejected', Totp::verify($secret, substr($code, 0, 5), $now) === false);
// replay: گام پذیرفته‌شده دوباره پذیرفته نمی‌شود
$step = Totp::verify($secret, $code, $now);
t('accepted step returned', is_int($step) && $step === Totp::step($now));
t('replay of same code rejected', Totp::verify($secret, $code, $now, $step) === false);
t('older code after acceptance rejected', Totp::verify($secret, Totp::codeAt($secret, $now - 30), $now, $step) === false);
t('next code after acceptance accepted', Totp::verify($secret, Totp::codeAt($secret, $now + 30), $now, $step) !== false);

section('Totp — otpauth URI');

$uri = Totp::otpauthUri($secret, 'ali@shop.ir');
t('uri scheme and label', str_starts_with($uri, 'otpauth://totp/Peakopay%3Aali%40shop.ir?'));
t('uri carries secret/issuer/params', str_contains($uri, 'secret=' . $secret) && str_contains($uri, 'issuer=Peakopay') && str_contains($uri, 'period=30') && str_contains($uri, 'digits=6') && str_contains($uri, 'algorithm=SHA1'));

section('QrCode — محلی، بدون منبع خارجی');

$svg = QrCode::svg($uri, 4, 4);
t('qr output is inline SVG', str_starts_with($svg, '<?xml') && str_contains($svg, '<svg xmlns="http://www.w3.org/2000/svg"'));
t('qr has no external resources (fully offline)', !preg_match('#(https?:(?!\\/\\/(?:www\\.)?w3\\.org)|<image|xlink:href|url\\()#', $svg));
$m = QrCode::encode($uri);
$n = count($m);
t('matrix is square n×n for otpauth uri', $n % 4 === 1 && $n >= 21 && count($m[0]) === $n);
// ساختار: یابنده‌ها + ماژول تیره + زمان‌بند
$finderOk = true;
foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$fx, $fy]) {
    for ($d = -3; $d <= 3; $d++) {
        $ring = abs($d);
        if ($m[$fy][$fx + $d] !== ($ring === 2 ? 0 : 1) || $m[$fy + $d][$fx] !== ($ring === 2 ? 0 : 1)) {
            $finderOk = false;
        }
    }
}
t('finder patterns (7×7 rings) intact', $finderOk);
t('dark module at (row 4V+9, col 8)', $m[$n - 8][8] === 1);
$timingOk = true;
for ($i = 8; $i < $n - 8; $i++) {
    if ($m[6][$i] !== ($i % 2 === 0 ? 1 : 0) || $m[$i][6] !== ($i % 2 === 0 ? 1 : 0)) {
        $timingOk = false;
    }
}
t('timing patterns intact', $timingOk);
// بردار شناخته‌شده: این ماتریس با دیکدر مستقل (zbar) و کتابخانهٔ مرجع
// (qrcode 8.2 — تطبیق بیتی کامل در همهٔ نسخه‌ها/ماسک‌ها) راستی‌آزمایی شده؛
// هش آن به‌عنوان regression فریز شده است (آخرین راستی‌آزمایی: تطبیق بیتی با qrcode 8.2 + دیکد zbar پس از ری‌برند Peakopay).
$known = 'otpauth://totp/Peakopay%3Aali%40shop.ir?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Peakopay&algorithm=SHA1&digits=6&period=30';
$km = QrCode::encode($known);
$hash = md5(implode('', array_map(static fn ($r) => implode('', $r), $km)));
eq('known-answer matrix (zbar+reference-verified)', '3b457028158d3091ad377c18dd49cc82', $hash);
throws('payload over capacity throws', InvalidArgumentException::class, static function (): void {
    QrCode::encode(str_repeat('x', 300));
});

section('TwoFactor — enroll/activate/verify (DB)');

$admin = User::findByEmail($GLOBALS['T']['user_email']);
$uid = (int) $admin['id'];
t('fresh user has no 2FA', !TwoFactor::isEnabled($uid));
$sec = TwoFactor::enroll($uid);
t('enroll returns base32 secret', is_string($sec) && strlen($sec) === 32);
$row = TwoFactor::row($uid);
$enc = (string) $row['secret_enc'];
t('secret stored encrypted (not plaintext)', $enc !== $sec && (str_starts_with($enc, 's1:') || str_starts_with($enc, 'o1:')));
t('pending secret decrypts before activation', TwoFactor::pendingSecret($uid) === $sec);
t('not enabled before first code', !TwoFactor::isEnabled($uid));
t('activation with wrong code fails', TwoFactor::activate($uid, '000000') === false);
$tNow = time();
$goodCode = Totp::codeAt($sec, $tNow);
$codes = TwoFactor::activate($uid, $goodCode);
t('activation with correct code succeeds', is_array($codes) && count($codes) === TwoFactor::RECOVERY_COUNT);
t('enabled after activation', TwoFactor::isEnabled($uid));
t('pending secret no longer exposed after activation', TwoFactor::pendingSecret($uid) === null);
t('same code rejected at login (replay)', !TwoFactor::verifyCode($uid, $goodCode));
$code2 = Totp::codeAt($sec, $tNow + 30);
t('next-step code accepted at login', TwoFactor::verifyCode($uid, $code2));
t('replay of next-step code rejected', !TwoFactor::verifyCode($uid, $code2));

section('TwoFactor — recovery codes');

$dbRows = DB::all('SELECT * FROM user_recovery_codes WHERE user_id = ?', [$uid]);
t('10 codes stored', count($dbRows) === TwoFactor::RECOVERY_COUNT);
$hashOk = true;
foreach ($dbRows as $r) {
    if (!preg_match('/^[0-9a-f]{64}$/', (string) $r['code_hash'])) {
        $hashOk = false;
    }
}
t('stored values are sha256 hex (not plaintext)', $hashOk && !in_array($codes[0], array_column($dbRows, 'code_hash'), true));
$rc = $codes[0];
t('recovery code consumed successfully', TwoFactor::consumeRecoveryCode($uid, $rc));
t('same recovery code rejected on reuse', !TwoFactor::consumeRecoveryCode($uid, $rc));
t('format-insensitive: dashes/case ignored', TwoFactor::consumeRecoveryCode($uid, strtolower(str_replace('-', '', $codes[1]))));
t('unknown recovery code rejected', !TwoFactor::consumeRecoveryCode($uid, 'AAAAA-BBBBB-CCCCC'));
eq('remaining count reflects usage', TwoFactor::RECOVERY_COUNT - 2, TwoFactor::recoveryRemaining($uid));
$old = $codes[2];
$newCodes = TwoFactor::generateRecoveryCodes($uid);
t('regeneration creates 10 new codes', is_array($newCodes) && count($newCodes) === TwoFactor::RECOVERY_COUNT);
t('regeneration invalidates previous codes', !TwoFactor::consumeRecoveryCode($uid, $old));
t('new code works', TwoFactor::consumeRecoveryCode($uid, $newCodes[3]));

section('TwoFactor — challenges & rate limit');

$tok = TwoFactor::createChallenge($uid);
$tRow = TwoFactor::challenge($tok);
t('challenge created and bound to user', $tRow !== null && (int) $tRow['user_id'] === $uid);
$expiredTok = TwoFactor::createChallenge($uid);
DB::q('UPDATE auth_challenges SET expires_at = ? WHERE token_hash = ?', [date('Y-m-d H:i:s', time() - 1), hash('sha256', $expiredTok)]);
t('expired challenge rejected', TwoFactor::challenge($expiredTok) === null);
t('challenge survives until attempts exhausted', (static function (string $tok): bool {
    for ($i = 0; $i < TwoFactor::MAX_ATTEMPTS - 1; $i++) {
        TwoFactor::failChallenge($tok);
    }
    return TwoFactor::challenge($tok) !== null;
})($tok));
TwoFactor::failChallenge($tok);
t('challenge dies after max attempts', TwoFactor::challenge($tok) === null);
$tok2 = TwoFactor::createChallenge($uid);
t('resolveChallenge deletes and returns user', TwoFactor::resolveChallenge($tok2) === $uid && TwoFactor::challenge($tok2) === null);
TwoFactor::clearRate($uid);
t('rate limiter blocks after repeated attempts', (static function (int $uid): bool {
    TwoFactor::clearRate($uid);
    for ($i = 0; $i < TwoFactor::RATE_MAX; $i++) {
        if (TwoFactor::rateLimited($uid)) {
            return false; // زودتر از حد مسدود شد
        }
    }
    return TwoFactor::rateLimited($uid); // تلاش (RATE_MAX+1)-ام مسدود
})($uid));
TwoFactor::clearRate($uid);

section('TwoFactor — policy & session revocation');

t('policy default off (no lockout)', !TwoFactor::enforcedFor($admin));
Setting::set('twofactor_required_admins', '1');
t('policy on: owner enforced', TwoFactor::enforcedFor($admin));
$opEmail = 'op2fa@test.local';
User::create((int) $admin['account_id'], (int) $admin['store_id'], 'اپراتور تست', $opEmail, 'Passw0rd!23', 'operator');
$operator = User::findByEmail($opEmail);
t('policy on: operator NOT enforced', !TwoFactor::enforcedFor($operator));
Setting::set('twofactor_required_admins', '0');
t('policy off again', !TwoFactor::enforcedFor($admin));

TwoFactor::revokeSessions($uid);
t('revocation timestamp recorded', TwoFactor::revokedAfter($uid) !== null && TwoFactor::revokedAfter($uid) <= time());
sleep(1);
TwoFactor::revokeSessions($uid);
t('revocation bumps forward', TwoFactor::revokedAfter($uid) >= time());

section('TwoFactor — admin reset');

$m2Email = 'm2fa@test.local';
User::create((int) $admin['account_id'], (int) $admin['store_id'], 'عضو ۲فا', $m2Email, 'Passw0rd!23', 'operator');
$m2u = User::findByEmail($m2Email);
$m2 = (int) $m2u['id'];
$sec2 = TwoFactor::enroll($m2);
TwoFactor::activate($m2, Totp::codeAt($sec2, time()));
t('member 2FA enabled', TwoFactor::isEnabled($m2));
t('admin reset rejects unknown user', !TwoFactor::adminReset($admin, 999999, 'دلیل تست کافی است'));
TwoFactor::adminReset($admin, $m2, 'گوشی کاربر گم شده و کد بازیابی ندارد — تست');
t('after admin reset: 2FA off', !TwoFactor::isEnabled($m2));
t('after admin reset: recovery codes gone', TwoFactor::recoveryRemaining($m2) === 0);
t('after admin reset: sessions revoked', TwoFactor::revokedAfter($m2) !== null);
$resetRow = DB::one('SELECT * FROM twofactor_resets WHERE target_user_id = ? ORDER BY id DESC', [$m2]);
t('reset audit row: admin/target/reason/ip recorded', $resetRow !== null
    && (int) $resetRow['admin_user_id'] === (int) $admin['id']
    && (string) $resetRow['reason'] === 'گوشی کاربر گم شده و کد بازیابی ندارد — تست'
    && $resetRow['ip'] !== '');
t('unseen reset visible to user', TwoFactor::unseenReset($m2) !== null);
TwoFactor::markResetsSeen($m2);
t('reset marked seen', TwoFactor::unseenReset($m2) === null);
t('old secret not retrievable after reset (row deleted)', TwoFactor::pendingSecret($m2) === null && DB::one('SELECT * FROM user_2fa WHERE user_id = ?', [$m2]) === null);
$sec2b = TwoFactor::enroll($m2);
$codes2b = TwoFactor::activate($m2, Totp::codeAt($sec2b, time()));
t('re-enrollment generates new recovery codes', is_array($codes2b) && count($codes2b) === TwoFactor::RECOVERY_COUNT);
$au = DB::all("SELECT action FROM audit_logs WHERE action LIKE '2fa.%' ORDER BY id");
t('audit trail covers 2fa lifecycle', count($au) > 0);

section('TwoFactor — disable');

TwoFactor::disable($uid);
t('disable removes enrollment', !TwoFactor::isEnabled($uid) && TwoFactor::row($uid) === null);
t('disable removes recovery codes', TwoFactor::recoveryRemaining($uid) === 0);
TwoFactor::disable($m2);
