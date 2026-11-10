<?php
/**
 * Peakopay — Bank SMS parser (PHP implementation).
 *
 * Used by the admin "Test tools" page to simulate/parse bank SMS and by the
 * automated test-suite with real-world message fixtures. The Android app ships
 * an equivalent Kotlin implementation driven by the same rule format
 * (config/sms-rules.json) — rules are data, not code, so the merchant can add
 * or adjust patterns for any bank without touching the app.
 */
declare(strict_types=1);

final class SmsParser
{
    private static ?array $rulesCache = null;

    /** @return array{id:string,bank:string,amount_rial:int,dest_last4:string,reference:string,occurred_at:string,raw:string} */
    public static function parse(string $text, string $sender = '', ?int $deviceTime = null): array
    {
        $text = trim($text);
        $norm = self::normalize($text);
        if ($norm === '' || mb_strlen($norm) > 1600) {
            throw new DomainException('متن پیامک خالی یا خیلی طولانی است.');
        }

        $rule = self::findRule($norm, $sender);
        if ($rule === null) {
            throw new DomainException('این پیامک شبیه هیچ الگوی واریز بانکی شناخته‌شده‌ای نیست.');
        }
        foreach (($rule['reject_keywords'] ?? []) as $kw) {
            if ($kw !== '' && str_contains($norm, $kw)) {
                throw new DomainException('این پیامک تراکنش واریز نیست (شامل «' . $kw . '» است).');
            }
        }

        $amount = self::extract($norm, $rule['amount_patterns'] ?? []);
        if ($amount === null) {
            throw new DomainException('مبلغ در پیامک پیدا نشد.');
        }
        [$amountValue, $isToman] = $amount;
        if ($amountValue <= 0) {
            throw new DomainException('مبلغ نامعتبر است.');
        }

        $last4 = null;
        if (!empty($rule['card_patterns'])) {
            $card = self::extract($norm, $rule['card_patterns']);
            if ($card !== null) {
                $digits = preg_replace('#\D#', '', $card[0]) ?? '';
                $last4  = substr($digits, -4);
            }
        }

        $ref = '';
        if (!empty($rule['reference_patterns'])) {
            $r = self::extract($norm, $rule['reference_patterns']);
            if ($r !== null) {
                $ref = mb_substr($r[0], 0, 40);
            }
        }

        $bank = (string) ($rule['bank'] ?? '');
        if ($bank === '' && !empty($rule['bank_name_patterns'])) {
            $b = self::extract($norm, $rule['bank_name_patterns']);
            if ($b !== null) {
                $bank = $b[1] ?? $b[0];
            }
        }

        return [
            'id'          => (string) ($rule['id'] ?? 'unknown'),
            'bank'        => $bank,
            'amount_rial' => (int) ($isToman ? $amountValue * 10 : $amountValue),
            'dest_last4'  => (string) ($last4 ?? ''),
            'reference'   => $ref,
            'occurred_at' => self::extractTime($norm, $deviceTime),
            'raw'         => $text,
        ];
    }

    /** @return array{id:string,label:string,rules:array}|null public for the test-suite */
    public static function findRule(string $normalizedText, string $sender): ?array
    {
        foreach (self::rules() as $rule) {
            if (!empty($rule['senders'])) {
                $match = false;
                foreach ($rule['senders'] as $s) {
                    if ($s !== '' && stripos($sender, $s) !== false) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }
            $hasDeposit = false;
            foreach (($rule['deposit_keywords'] ?? []) as $kw) {
                if ($kw !== '' && str_contains($normalizedText, $kw)) {
                    $hasDeposit = true;
                    break;
                }
            }
            $reject = false;
            foreach (($rule['reject_keywords'] ?? []) as $kw) {
                if ($kw !== '' && str_contains($normalizedText, $kw)) {
                    $reject = true;
                    break;
                }
            }
            if ($hasDeposit && !$reject) {
                return $rule;
            }
        }
        return null;
    }

    public static function rules(): array
    {
        if (self::$rulesCache !== null) {
            return self::$rulesCache;
        }
        $custom = Setting::get('sms_rules', '');
        if ($custom !== '') {
            $decoded = json_decode($custom, true);
            if (is_array($decoded) && !empty($decoded['rules']) && is_array($decoded['rules'])) {
                return self::$rulesCache = $decoded['rules'];
            }
        }
        $file = HP_BASE . '/config/sms-rules.json';
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && !empty($decoded['rules'])) {
                return self::$rulesCache = $decoded['rules'];
            }
        }
        return self::$rulesCache = [];
    }

    /** Persian digits → Latin, Arabic decimal separator, etc. */
    public static function normalize(string $text): string
    {
        $text = Helpers::enDigits($text);
        return str_replace(['،', '٫', '٬'], [',', '.', ','], $text);
    }

    /** @return array{0:string,1:bool}|null [amount digits, is_toman] */
    private static function extract(string $text, array $patterns): ?array
    {
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            if (preg_match('/' . str_replace('/', '\/', $pattern) . '/u', $text, $m)) {
                $digits = preg_replace('#[^\d]#', '', $m[1] ?? '') ?? '';
                if ($digits !== '') {
                    $isToman = isset($m[2]) ? str_contains($m[2], 'تومان') : false;
                    return [$digits, $isToman];
                }
            }
        }
        return null;
    }

    /** Jalali/Gregorian date+time inside the SMS → UTC; falls back to device time. */
    private static function extractTime(string $norm, ?int $deviceTime): string
    {
        $ts = null;
        if (preg_match('#(\d{4})/(\d{1,2})/(\d{1,2})#', $norm, $d) && preg_match('#(\d{1,2}):(\d{2})(?::(\d{2}))?#', $norm, $t)) {
            $y = (int) $d[1];
            $mo = (int) $d[2];
            $day = (int) $d[3];
            $h = (int) $t[1];
            $mi = (int) $t[2];
            $s = (int) ($t[3] ?? 0);
            if ($y > 1700) {
                // Gregorian already
                $ts = gmmktime($h, $mi, $s, $mo, $day, $y);
            } elseif ($y >= 1300 && $y <= 1500) {
                [$gy, $gm, $gd] = self::j2g($y, $mo, $day);
                $ts = gmmktime($h, $mi, $s, $gm, $gd, $gy);
            }
            if ($ts !== null) {
                // bank SMS timestamps are Tehran local time (UTC+3:30)
                $ts -= 3 * 3600 + 30 * 60;
                // sanity: not in the future, not older than a week
                if ($ts > time() + 900 || $ts < time() - 7 * 86400) {
                    $ts = null;
                }
            }
        }
        if ($ts === null) {
            $ts = $deviceTime ?? time();
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /** Jalali → Gregorian (farsiweb algorithm). @return array{int,int,int} */
    public static function j2g(int $jy, int $jm, int $jd): array
    {
        $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        $jy -= 979;
        $jm -= 1;
        $jd -= 1;
        $jDayNo = 365 * $jy + intdiv($jy, 33) * 8 + intdiv($jy % 33 + 3, 4);
        for ($i = 0; $i < $jm; $i++) {
            $jDayNo += $jDaysInMonth[$i];
        }
        $jDayNo += $jd;
        $gDayNo = $jDayNo + 79;
        $gy = 1600 + 400 * intdiv($gDayNo, 146097);
        $gDayNo %= 146097;
        $leap = true;
        if ($gDayNo >= 36525) {
            $gDayNo--;
            $gy += 100 * intdiv($gDayNo, 36524);
            $gDayNo %= 36524;
            if ($gDayNo >= 365) {
                $gDayNo++;
            } else {
                $leap = false;
            }
        } elseif ($gDayNo < 365) {
            $leap = false;
        }
        $gy += 4 * intdiv($gDayNo, 1461); // 1461 = days in 4 years
        $gDayNo %= 1461;
        if ($gDayNo >= 366) {
            $leap = false;
            $gDayNo--;
            $gy += intdiv($gDayNo, 365);
            $gDayNo %= 365;
        }
        $gd = $gDayNo + 1;
        $salA = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        for ($gm = 0; $gm < 13 && $gd > $salA[$gm]; $gm++) {
            $gd -= $salA[$gm];
        }
        return [$gy, $gm, $gd];
    }
}
