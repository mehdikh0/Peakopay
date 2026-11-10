<?php
/** Tests: bank SMS parser with fixtures. */
declare(strict_types=1);

$fixtures = require __DIR__ . '/../fixtures/sms.php';

section('SmsParser: fixtures');
foreach ($fixtures as $name => $fx) {
    try {
        $parsed = SmsParser::parse($fx['text'], $fx['sender']);
        if ($fx['expect'] === null) {
            t("{$name}: rejected (as expected)", false, 'was parsed: ' . json_encode($parsed, JSON_UNESCAPED_UNICODE));
            continue;
        }
        $ok = ($parsed['amount_rial'] === $fx['expect']['amount_rial'])
           && ($parsed['dest_last4'] === $fx['expect']['dest_last4'])
           && ($parsed['reference'] === $fx['expect']['reference']);
        t("{$name}: amount={$parsed['amount_rial']} last4={$parsed['dest_last4']} ref={$parsed['reference']}", $ok);
    } catch (DomainException $e) {
        t("{$name}: rejected", $fx['expect'] === null, $fx['expect'] === null ? '' : 'unexpected rejection: ' . $e->getMessage());
    }
}

section('SmsParser: time extraction');
// تست تاریخ-مستقل: تاریخ شمسیِ «۲ روز پیش» را در لحظه اجرا می‌سازیم (پنجره ۷روزه پارسر)
$g2j = static function (int $gy, int $gm, int $gd): array {
    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = $gy <= 1600 ? 0 : 979;
    $gy -= $gy <= 1600 ? 621 : 1600;
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) - 80 + $gd + $gdm[$gm - 1];
    $jy += 33 * intdiv($days, 12053);
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
    $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
    return [$jy, $jm, $jd];
};
$tz = new DateTimeZone('Asia/Tehran');
$refTeh = (new DateTimeImmutable('@' . (time() - 2 * 86400)))->setTimezone($tz);
[$jy, $jm, $jd] = $g2j((int) $refTeh->format('Y'), (int) $refTeh->format('n'), (int) $refTeh->format('j'));
$jDate = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
$parsed = SmsParser::parse("واریز مبلغ 100,000 ريال به کارت 6219-86••-••••-0019 تاریخ: {$jDate} ساعت: 09:15", 'Mellat');
$local = (new DateTimeImmutable($parsed['occurred_at'] . ' UTC'))->setTimezone($tz);
eq("jalali {$jDate} 09:15 → Tehran 09:15 local", '09:15', $local->format('H:i'));
t("converted date is the intended day ({$refTeh->format('Y-m-d')})", $local->format('Y-m-d') === $refTeh->format('Y-m-d'));

$parsed2 = SmsParser::parse("واریز مبلغ 100,000 ريال به کارت 6219-86••-••••-0019", 'Mellat', time() - 120);
t('missing date falls back to device time', abs(strtotime($parsed2['occurred_at']) - (time() - 120)) <= 5);

section('SmsParser: unit sanity');
eq('digit normalization (separators preserved)', '1,234,567', SmsParser::normalize('۱،۲۳۴٬۵۶۷'));
t('findRule ignores non-deposit SMS', SmsParser::findRule(SmsParser::normalize('سلام حال شما؟'), 'Mellat') === null);
