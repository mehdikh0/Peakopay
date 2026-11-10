<?php
/**
 * Peakopay — مولد کد QR کاملاً محلی (Pure PHP، بدون هیچ وابستگی).
 *
 * چرا محلی؟ کد QR صفحهٔ پیکربندی ورود دومرحله‌ای حاوی «راز TOTP» است —
 * فرستادنش به سرویس‌های خارجی QR یعنی افشای راز و وابستگی به اینترنتِ
 * بین‌المللی. این کلاس بدون اینترنت و بدون کتابخانهٔ خارجی (حتی GD) کار
 * می‌کند و خروجی SVG درون‌خطی می‌دهد.
 *
 * پشتیبانی: حالت بایت (byte mode)، سطح تصحیح خطای L، نسخه‌های ۱ تا ۱۰
 * (تا ~۲۷۰ بایت داده — برای نشانی‌های otpauth:// کاملاً کافی است)، انتخاب
 * خودکار نسخه، هر ۸ ماسک با ارزیابی جریمهٔ استاندارد، اطلاعات فرمت/نسخه.
 *
 * مرجع الگوریتم: ISO/IEC 18004.
 */
declare(strict_types=1);

final class QrCode
{
    /** ظرفیت داده (بایت) — حالت بایت، ECC L، نسخه‌های ۱..۱۰. */
    private const CAPACITY_L = [19, 34, 55, 80, 108, 136, 156, 194, 232, 274];

    /** ساختار بلوک‌های RS: [ [تعداد بلوک، دادهٔ بلوک، ecc بلوک], ... ] — ECC L. */
    private const BLOCKS_L = [
        1  => [[1, 19, 7]],
        2  => [[1, 34, 10]],
        3  => [[1, 55, 15]],
        4  => [[1, 80, 20]],
        5  => [[1, 108, 26]],
        6  => [[2, 68, 18]],
        7  => [[2, 78, 20]],
        8  => [[2, 97, 24]],
        9  => [[2, 116, 30]],
        10 => [[2, 68, 18], [2, 69, 18]],
    ];

    /** مکان مرکز الگوهای تراز (نسخه‌های ۲..۱۰). */
    private const ALIGN = [
        2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    public const ECC_L_BITS = 0b01; // بیت‌های سطح L در اطلاعات فرمت

    // ------------------------------------------------------------ public

    /**
     * کد QR به‌صورت رشتهٔ SVG (درون‌خطی، بدون هیچ منبع خارجی).
     *
     * @param string $text  داده (برای otpauth بهتر است ASCII باشد)
     * @param float  $scale ضریب هر ماژول (px)
     * @param int    $quiet حاشیهٔ سکوت (تعداد ماژول — استاندارد ۴)
     */
    public static function svg(string $text, float $scale = 3.0, int $quiet = 4): string
    {
        $matrix = self::encode($text);
        $n    = count($matrix);
        $size = ($n + 2 * $quiet) * $scale;
        // یک path پیوسته از ماژول‌های تیره — خروجی فشرده و تمیز
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if ($matrix[$y][$x] === 1) {
                    $x0 = $x;
                    while ($x < $n && $matrix[$y][$x] === 1) {
                        $x++;
                    }
                    $px = ($x0 + $quiet) * $scale;
                    $py = ($y + $quiet) * $scale;
                    $w  = ($x - $x0) * $scale;
                    $path .= 'M' . self::f($px) . ' ' . self::f($py)
                        . 'h' . self::f($w) . 'v' . self::f($scale) . 'h-' . self::f($w) . 'z';
                } else {
                    $x++;
                }
            }
        }
        $svg  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::f($size) . ' ' . self::f($size)
            . '" width="' . self::f($size) . '" height="' . self::f($size)
            . '" shape-rendering="crispEdges" role="img" aria-label="QR code">';
        $svg .= '<rect width="' . self::f($size) . '" height="' . self::f($size) . '" fill="#ffffff"/>';
        if ($path !== '') {
            $svg .= '<path d="' . $path . '" fill="#1E2438"/>';
        }
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * رمزگذاری کامل → ماتریس دودویی (سطرها: آرایهٔ 0/1). مرحلهٔ تست‌پذیرِ svg().
     * @return array<int, array<int, int>>
     */
    public static function encode(string $text): array
    {
        if ($text === '') {
            throw new InvalidArgumentException('QR payload is empty.');
        }
        $version = self::pickVersion(strlen($text));
        $n       = 17 + 4 * $version;

        // ۱) جریان بیت: نشانگر حالت بایت + شمارنده + داده + پایان‌دهنده + پدینگ
        $bits  = '0100'; // byte mode
        $cnt   = strlen($text);
        $bits .= str_pad(decbin($cnt), $version >= 10 ? 16 : 8, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $cnt; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }
        $capacityBits = self::CAPACITY_L[$version - 1] * 8;
        $term = min(4, max(0, $capacityBits - strlen($bits)));
        if ($term > 0) {
            $bits .= str_pad('', $term, '0');
        }
        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 8) * 8), '0', STR_PAD_LEFT);
        $pad  = ['11101100', '00010001']; // 0xEC, 0x11
        $pi   = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $pad[$pi++ % 2];
        }
        $dataBytes = [];
        for ($i = 0, $l = strlen($bits); $i < $l; $i += 8) {
            $dataBytes[] = (int) bindec(substr($bits, $i, 8));
        }

        // ۲) Reed-Solomon روی بلوک‌ها + درهم‌تنیدگی
        $final = self::interleave($dataBytes, self::BLOCKS_L[$version]);

        // ۳) ماتریس + الگوهای تابع
        $m  = array_fill(0, $n, array_fill(0, $n, 0));
        $fn = array_fill(0, $n, array_fill(0, $n, 0)); // 1 = ماژول تابع (ماسک نمی‌شود)
        self::drawFunctionPatterns($m, $fn, $version, $n);

        // ۴) انتخاب بهترین ماسک (هر ۸ ماسک + جریمهٔ استاندارد)
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = self::placeData($m, $fn, $final, $n, $mask);
            self::drawFormatInfo($cand, $n, $mask);
            $score = self::penalty($cand, $n);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $cand;
            }
        }
        return $best;
    }

    // ------------------------------------------------------------ steps

    private static function pickVersion(int $len): int
    {
        for ($v = 1; $v <= 10; $v++) {
            $countBits = $v >= 10 ? 16 : 8;
            if (4 + $countBits + $len * 8 <= self::CAPACITY_L[$v - 1] * 8) {
                return $v;
            }
        }
        throw new InvalidArgumentException('QR payload too long for versions 1-10 (max ~270 bytes at ECC L).');
    }

    /** RS-ECC هر بلوک + درهم‌تنیدگی داده و ECC (ISO 18004 §8.5). */
    private static function interleave(array $data, array $blocks): array
    {
        $dataChunks = [];
        $eccChunks  = [];
        $pos = 0;
        foreach ($blocks as [$count, $dlen, $elen]) {
            for ($b = 0; $b < $count; $b++) {
                $chunk = array_slice($data, $pos, $dlen);
                $dataChunks[] = $chunk;
                $eccChunks[]  = self::rsEcc($chunk, $elen);
                $pos += $dlen;
            }
        }
        $out = [];
        $maxD = max(array_map('count', $dataChunks));
        for ($i = 0; $i < $maxD; $i++) {
            foreach ($dataChunks as $ch) {
                if (isset($ch[$i])) {
                    $out[] = $ch[$i];
                }
            }
        }
        $maxE = max(array_map('count', $eccChunks));
        for ($i = 0; $i < $maxE; $i++) {
            foreach ($eccChunks as $ch) {
                if (isset($ch[$i])) {
                    $out[] = $ch[$i];
                }
            }
        }
        return $out;
    }

    /** جبر GF(256) با چندجمله‌ای اول 0x11D — جدول‌های log/exp تنبل. */
    private static function gfTables(): array
    {
        static $exp = null, $log = null;
        if ($exp !== null) {
            return [$exp, $log];
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        return [$exp, $log];
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        [$exp, $log] = self::gfTables();
        return $exp[$log[$a] + $log[$b]];
    }

    /** α^i در GF(256). */
    private static function gfAlpha(int $i): int
    {
        [$exp] = self::gfTables();
        return $exp[$i % 255];
    }

    /** کدهای تصحیح Reed-Solomon برای یک بلوک داده (تقسیم ترکیبی). */
    private static function rsEcc(array $data, int $ecLen): array
    {
        // چندجمله‌ای مولد (ضرایب نزولی): gen = ∏(x - α^i)، i = 0..ecLen-1
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $b    = [1, self::gfAlpha($i)]; // (x + α^i) نزولی
            $next = array_fill(0, count($gen) + 1, 0);
            for ($a = 0, $ga = count($gen); $a < $ga; $a++) {
                for ($c = 0; $c < 2; $c++) {
                    $next[$a + $c] ^= self::gfMul($gen[$a], $b[$c]);
                }
            }
            $gen = $next;
        }
        // data·x^ecLen mod gen — تفریق‌های پیاپی
        $out = array_merge($data, array_fill(0, $ecLen, 0));
        $dl  = count($data);
        for ($i = 0; $i < $dl; $i++) {
            $coef = $out[$i];
            if ($coef !== 0) {
                for ($j = 1; $j <= $ecLen; $j++) {
                    $out[$i + $j] ^= self::gfMul($gen[$j], $coef);
                }
            }
        }
        return array_slice($out, $dl);
    }

    /** الگوهای تابع: یابنده‌ها + جداکننده‌ها + زمان‌بند + تراز + ماژول تیره + رزرو فرمت/نسخه. */
    private static function drawFunctionPatterns(array &$m, array &$fn, int $version, int $n): void
    {
        // الگوی یابنده ۷×۷ در سه گوشه + جداکننده‌های L شکلِ روشن (فقط سمت‌های داخلی)
        $drawFinder = static function (int $cx, int $cy) use (&$m, &$fn): void {
            for ($dy = -3; $dy <= 3; $dy++) {
                for ($dx = -3; $dx <= 3; $dx++) {
                    $ring = max(abs($dx), abs($dy));
                    $fn[$cy + $dy][$cx + $dx] = 1;
                    $m[$cy + $dy][$cx + $dx]  = ($ring === 2) ? 0 : 1; // حلقهٔ میانی روشن
                }
            }
        };
        $sep = static function (int $x, int $y) use (&$m, &$fn): void {
            $m[$y][$x]  = 0;
            $fn[$y][$x] = 1;
        };
        $drawFinder(3, 3);
        $drawFinder($n - 4, 3);
        $drawFinder(3, $n - 4);
        // جداکننده‌های روشن — هر یابنده فقط در دو سمت داخلیِ خود (درون ماتریس)
        for ($i = 0; $i <= 7; $i++) {
            $sep($i, 7);          // بالا-چپ: ردیف ۷
            $sep(7, $i);          // بالا-چپ: ستون ۷
            $sep($n - 1 - $i, 7); // بالا-راست: ردیف ۷
            $sep($n - 8, $i);     // بالا-راست: ستون n-8
            $sep($i, $n - 8);     // پایین-چپ: ردیف n-8
            $sep(7, $n - 1 - $i); // پایین-چپ: ستون ۷
        }

        // الگوی زمان‌بند (ردیف/ستون ۶) — تیره در مضرب‌های زوج
        for ($i = 8; $i < $n - 8; $i++) {
            $dark = ($i % 2) === 0 ? 1 : 0;
            $fn[6][$i] = 1;
            $m[6][$i]  = $dark;
            $fn[$i][6] = 1;
            $m[$i][6]  = $dark;
        }

        // الگوهای تراز (نسخهٔ ≥۲) — مرکزها؛ روی یابنده‌ها نمی‌نشینند
        if ($version >= 2) {
            foreach (self::ALIGN[$version] as $cy) {
                foreach (self::ALIGN[$version] as $cx) {
                    $onFinder = ($cx <= 8 && $cy <= 8)
                        || ($cx >= $n - 9 && $cy <= 8)
                        || ($cx <= 8 && $cy >= $n - 9);
                    if ($onFinder) {
                        continue;
                    }
                    for ($dy = -2; $dy <= 2; $dy++) {
                        for ($dx = -2; $dx <= 2; $dx++) {
                            $ring = max(abs($dx), abs($dy));
                            $fn[$cy + $dy][$cx + $dx] = 1;
                            $m[$cy + $dy][$cx + $dx]  = $ring === 1 ? 0 : 1;
                        }
                    }
                }
            }
        }

        // ماژول تیرهٔ ثابت: (ردیف 4V+9، ستون ۸)
        $fn[$n - 8][8] = 1;
        $m[$n - 8][8]  = 1;

        // رزرو ناحیهٔ اطلاعات فرمت (دو کپی)
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $fn[$i][8] = 1;   // ستون ۸، ردیف‌های ۰..۸ (جز ردیف ۶ = زمان‌بند)
                $fn[8][$i] = 1;   // ردیف ۸، ستون‌های ۰..۸ (جز ستون ۶)
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $fn[8][$n - 1 - $i] = 1; // ردیف ۸، ستون‌های n-1..n-8
            $fn[$n - 1 - $i][8] = 1; // ستون ۸، ردیف‌های n-1..n-8
        }

        // اطلاعات نسخه (نسخه‌های ≥۷) — دو بلوک ۶×۳ متقارن
        if ($version >= 7) {
            $bits = self::versionBits($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = (int) (($bits >> $i) & 1);
                $r = intdiv($i, 3);
                $c = $n - 11 + ($i % 3);
                $m[$r][$c] = $bit;
                $fn[$r][$c] = 1;
                $m[$c][$r] = $bit;
                $fn[$c][$r] = 1;
            }
        }
    }

    /** ۱۸ بیت اطلاعات نسخه: BCH(18,6) با مولد 0x1F25. */
    private static function versionBits(int $version): int
    {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        return ($version << 12) | ($rem & 0xFFF);
    }

    /** ۱۵ بیت اطلاعات فرمت (ECC L + ماسک) — BCH(15,5) با مولد 0x537 و ماسک 0x5412. */
    private static function formatBits(int $mask): int
    {
        $data = (self::ECC_L_BITS << 3) | $mask;
        $rem  = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        return (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
    }

    /** نوشتن دو کپی اطلاعات فرمت برای ماسکِ انتخاب‌شده. */
    private static function drawFormatInfo(array &$m, int $n, int $mask): void
    {
        $bits = self::formatBits($mask);
        for ($i = 0; $i < 15; $i++) {
            $mod = (int) (($bits >> $i) & 1);
            // کپی بالای-چپ: ستون ۸ + ردیف ۸ (تا ستون ۵)
            if ($i < 6) {
                $m[$i][8] = $mod;
            } elseif ($i === 6) {
                $m[7][8] = $mod;
            } elseif ($i === 7) {
                $m[8][8] = $mod;
            } elseif ($i === 8) {
                $m[8][7] = $mod;
            } else {
                $m[8][14 - $i] = $mod;
            }
            // کپی دوم: ردیف ۸ (بالا-راست) + ستون ۸ (پایین-چپ)
            if ($i < 8) {
                $m[8][$n - 1 - $i] = $mod;
            } else {
                $m[$n - 15 + $i][8] = $mod;
            }
        }
    }

    /** چیدمان زیگزاگی داده + اعمال ماسک. */
    private static function placeData(array $m, array $fn, array $final, int $n, int $mask): array
    {
        $c = array_map(static fn ($r) => array_map('intval', $r), $m);
        $bitIdx = 0;
        $total  = count($final) * 8;
        $up     = true;
        // ستون‌ها جفت‌جفت از راست؛ در سمت چپ، مقادیر ۶ و ۴ و ۲ به ۵ و ۳ و ۱
        // شیفت می‌شوند تا ستون زمان‌بند (۶) رد شود و ستون ۰ هم پوشیده بماند.
        for ($i = $n - 1; $i > 0; $i -= 2) {
            $col = ($i <= 6) ? $i - 1 : $i;
            if ($col < 1) {
                break;
            }
            for ($y = 0; $y < $n; $y++) {
                $row = $up ? $n - 1 - $y : $y;
                for ($dx = 0; $dx < 2; $dx++) {
                    $x = $col - $dx;
                    if ($fn[$row][$x] === 1) {
                        continue;
                    }
                    $bit = 0;
                    if ($bitIdx < $total) {
                        $bit = ($final[intdiv($bitIdx, 8)] >> (7 - ($bitIdx % 8))) & 1;
                    }
                    if (self::maskBit($mask, $x, $row)) {
                        $bit ^= 1;
                    }
                    $c[$row][$x] = $bit;
                    $bitIdx++;
                }
            }
            $up = !$up;
        }
        return $c;
    }

    private static function maskBit(int $mask, int $x, int $y): bool
    {
        // x = ستون، y = ردیف — فرمول‌های استاندارد ISO 18004
        return match ($mask) {
            0 => ($y + $x) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($y + $x) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => ($y * $x) % 2 + ($y * $x) % 3 === 0,
            6 => (($y * $x) % 2 + ($y * $x) % 3) % 2 === 0,
            7 => (($y * $x) % 3 + ($y + $x) % 2) % 2 === 0,
            default => false,
        };
    }

    /** ارزیابی جریمهٔ استاندارد ماسک (ISO 18004 §8.8.2). */
    private static function penalty(array $m, int $n): int
    {
        $score = 0;
        // N1 (ردیف‌های ≥۵) + N3 (الگوی شبیه یابنده) — افقی
        for ($y = 0; $y < $n; $y++) {
            $runColor = $m[$y][0];
            $runLen = 1;
            $row = '';
            for ($x = 1; $x < $n; $x++) {
                $row .= (string) $m[$y][$x - 1];
                if ($m[$y][$x] === $runColor) {
                    $runLen++;
                    if ($runLen === 5) {
                        $score += 3;
                    } elseif ($runLen > 5) {
                        $score += 1;
                    }
                } else {
                    $runColor = $m[$y][$x];
                    $runLen = 1;
                }
            }
            $row .= (string) $m[$y][$n - 1];
            $score += substr_count($row, '10111010000') * 40;
            $score += substr_count($row, '00001011101') * 40;
        }
        // N1 + N3 — عمودی
        for ($x = 0; $x < $n; $x++) {
            $runColor = $m[0][$x];
            $runLen = 1;
            $col = '';
            for ($y = 1; $y < $n; $y++) {
                $col .= (string) $m[$y - 1][$x];
                if ($m[$y][$x] === $runColor) {
                    $runLen++;
                    if ($runLen === 5) {
                        $score += 3;
                    } elseif ($runLen > 5) {
                        $score += 1;
                    }
                } else {
                    $runColor = $m[$y][$x];
                    $runLen = 1;
                }
            }
            $col .= (string) $m[$n - 1][$x];
            $score += substr_count($col, '10111010000') * 40;
            $score += substr_count($col, '00001011101') * 40;
        }
        // N2: بلوک‌های ۲×۲ هم‌رنگ
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        // N4: نسبت ماژول‌های تیره
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $dark += $m[$y][$x];
            }
        }
        $percent = $dark * 100 / ($n * $n);
        $score += (int) (floor(abs($percent - 50) / 5) * 10);
        return $score;
    }

    private static function f(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
