<?php
/**
 * Peakopay — Card SMS engine (server side).
 *
 * Deterministic, declarative per-card SMS parsing:
 *   - normalizeSender(): canonical sender identity (+98/0098/0 … → bare digits)
 *   - analyzeSample():  suggest field spans in a pasted sample (no AI — heuristics)
 *   - buildRule():      turn admin-approved spans into a JSON rule
 *                      (static skeleton + typed dynamic slots; never executable code)
 *   - runRules():       try a card's rules by priority; first full match wins
 *
 * Rule format (data only, validated transforms — SMS is untrusted input and is
 * never evaluated as code):
 * {
 *   "v": 1, "name": "...", "priority": 10, "currency_default": "IRR",
 *   "fields": [ {"key":"amount","pattern":"[0-9,]+","before":"مبلغ ","after":" ریال",
 *                "transforms":["strip_separators"], "currency_from_text":true} ],
 *   "required": ["amount"]
 * }
 */
declare(strict_types=1);

final class CardSmsEngine
{
    public const FIELD_KEYS = [
        'amount'         => 'مبلغ',
        'txn_type'       => 'نوع تراکنش',
        'card_last4'     => 'شماره کارت (۴ رقم آخر)',
        'balance'        => 'مانده',
        'date'           => 'تاریخ',
        'time'           => 'ساعت',
        'reference'      => 'شماره پیگیری',
        'txn_id'         => 'شناسه تراکنش',
        'bank'           => 'نام بانک',
    ];

    /** الگوهای مجاز هر فیلد (allow-list) — عمومی تا تست/سرویس‌ها هم ببینند */
    public const TYPE_PATTERNS = [
        'amount'     => '[0-9][0-9،٬,\.]{0,18}[0-9]',
        'txn_type'   => 'واریز|برداشت|خرید|پرداخت|انتقال',
        // ران کامل شماره کارت (کامل/ماسک‌شده/۴رقمی) — تبدیل last4 چهار رقم آخر را می‌دهد
        'card_last4' => '[0-9*][0-9* \-]{2,28}[0-9*]',
        'balance'    => '[0-9][0-9،٬,\.]{0,18}[0-9]',
        'date'       => '[0-9]{4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,2}',
        'time'       => '[0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?',
        'reference'  => '[0-9]{3,20}',
        'txn_id'     => '[0-9]{3,20}',
        'bank'       => '[آ-یA-Za-z ]{2,24}',
    ];

    /**
     * انواع «کلیدواژه‌ای»: الگویشان خودش معنا دارد و بی‌انکر هم قابل اعتماد است
     * (مثلاً الگوی txn_type لیست «واریز|برداشت|…» است — همین که anywhere بگیرد،
     * جهت تراکنش را تعیین می‌کند و ردِ برداشت همین‌جا رخ می‌دهد). انواع عددی
     * (مبلغ/مانده/کارت/…) فقط با انکر یا وقتی هیچ انکری نیست حدس آزاد می‌خورند.
     */
    private const BARE_KEYWORD_TYPES = ['txn_type', 'bank'];

    // ------------------------------------------------------------ text/sender

    /** Persian/Arabic digits → Latin, kill RTL marks/Arabic punctuation, collapse spaces. */
    public static function normalizeText(string $text): string
    {
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
        $t = str_replace($fa, $en, $text);
        $t = str_replace(["\xE2\x80\x8C", "\xE2\x80\x8F", "\xE2\x80\x8E", "\xE2\x80\xAE", "\xEF\xBB\xBF"], ' ', $t); // ZWNJ, RLM, LRM, RLO, BOM
        $t = str_replace(['٬', '،'], ',', $t);
        $t = str_replace('٫', '.', $t);
        // حروف عربی → فارسی (بانك/ملي در پیامک بانک ملی با ك/ي عربی می‌آید)
        $t = str_replace(['ي', 'ك'], ['ی', 'ک'], $t);
        $t = str_replace("\xE2\x80\x8B", ' ', $t); // ZWSP
        $t = preg_replace('#[ \t\x{00A0}]+#u', ' ', $t) ?? $t;
        return trim($t);
    }

    /**
     * Canonical sender: digits only, then strip 00 / 98 (when plausible) / leading 0.
     * Exact-match comparison only — never substring matching.
     *   30001234 / +9830001234 / 009830001234  →  30001234
     *   02112345678 / +982112345678            →  2112345678
     */
    public static function normalizeSender(string $sender): string
    {
        $t = self::normalizeText($sender);
        // فرستنده حرفی (الفبایی/الفبایی-عددی) — برخی بانک‌ها و فین‌تک‌ها به‌جای
        // شماره کوتاه، «نام فرستنده» می‌فرستند (مثل BlooIr). حروف حفظ می‌شوند
        // (بزرگ/کوچک بی‌تفاوت) تا با پاک‌کردن ارقام گم نشوند. قرینه Kotlin.
        if (preg_match('#[A-Za-z\x{0600}-\x{06FF}]#u', $t)) {
            $a = preg_replace('#[^A-Za-z0-9\x{0600}-\x{06FF}]#u', '', $t) ?? '';
            return trim(mb_strtoupper($a));
        }
        $d = preg_replace('#[^0-9]#', '', $t) ?? '';
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '00') && strlen($d) > 4) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '98') && strlen($d) > 8) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '0') && strlen($d) > 10) {
            $d = substr($d, 1);
        }
        return $d;
    }

    /** Currency from text near/inside message: IRR (ریال/ريال) or IRT (تومان). */
    public static function detectCurrency(string $normalizedText, string $default = 'IRR'): string
    {
        if (preg_match('#تومان#u', $normalizedText)) {
            return 'IRT';
        }
        if (preg_match('#ر[یي]ال#u', $normalizedText)) {
            return 'IRR';
        }
        return $default;
    }

    public static function toRial(string $digits, string $currency): int
    {
        $n = (int) preg_replace('#[^0-9]#', '', $digits);
        return $currency === 'IRT' ? $n * 10 : $n;
    }

    // ------------------------------------------------------------ learning

    /**
     * Suggest field spans in a sample (heuristic, deterministic — «آموزش فرمت»، نه هوش مصنوعی).
     * Returns segments for direct rendering (server slices — no client offset math):
     *   ['segments' => [['text'=>'...','key'=>null|'amount','span'=>['start','end','key','confidence']]], ...]
     */
    public static function analyzeSample(string $sample): array
    {
        $norm = self::normalizeText($sample);
        if ($norm === '' || mb_strlen($norm) > 1600) {
            throw new DomainException('متن نمونه خالی یا خیلی طولانی است.');
        }
        $spans = [];
        $add = static function (int $s, int $e, ?string $key, float $conf) use (&$spans): void {
            foreach ($spans as $sp) {
                if ($s < $sp['end'] && $e > $sp['start']) {
                    return; // overlap — keep first (structural spans claim their digits)
                }
            }
            $spans[] = ['start' => $s, 'end' => $e, 'key' => $key, 'confidence' => $conf];
        };
        $m = [];
        // ── 1) structural spans first (card, date, time, references, bank, txn_type)
        // کارت: ران کامل پس از کلیدواژه (کامل مثل 6219-8612-3456-7856، ماسک‌شده ****7856 یا ۴رقمی)؛
        // تبدیل last4 هنگام اجرا چهار رقم آخر را استخراج می‌کند.
        if (preg_match('#(?:کارت|card)\s*[: ]*(?:\*{0,4}\s*)?([0-9*][0-9* \-]{2,28}[0-9*])(?![0-9])#iu', $norm, $m, PREG_OFFSET_CAPTURE)) {
            $add($m[1][1], $m[1][1] + strlen($m[1][0]), 'card_last4', 0.9);
        }
        if (preg_match('#([0-9]{4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,2})#', $norm, $m, PREG_OFFSET_CAPTURE)) {
            $add($m[1][1], $m[1][1] + strlen($m[1][0]), 'date', 0.8);
        }
        if (preg_match('#([0-9]{1,2}:[0-9]{2})#', $norm, $m, PREG_OFFSET_CAPTURE)) {
            $add($m[1][1], $m[1][1] + strlen($m[1][0]), 'time', 0.7);
        }
        foreach (
            [
                'reference' => '#(?:پیگیری|رهگیری|مرجع|ارجاع)\s*[:؛]?\s*([0-9]{3,20})#u',
                'txn_id'    => '#(?:شناسه|شماره تراکنش)\s*[:؛]?\s*([0-9]{3,20})#u',
            ] as $key => $rx
        ) {
            if (preg_match($rx, $norm, $m, PREG_OFFSET_CAPTURE)) {
                $add($m[1][1], $m[1][1] + strlen($m[1][0]), $key, 0.75);
            }
        }
        if (preg_match('#بانک\s*([آ-ی ]{2,24})#u', $norm, $m, PREG_OFFSET_CAPTURE)) {
            $add($m[1][1], $m[1][1] + strlen($m[1][0]), 'bank', 0.6);
        }
        if (preg_match('#(واریز|برداشت|خرید|پرداخت|انتقال)#u', $norm, $m, PREG_OFFSET_CAPTURE)) {
            $add($m[0][1], $m[0][1] + strlen($m[0][0]), 'txn_type', 0.8);
        }
        // ── 2) amount/balance last, with context windows so unrelated numbers
        //       (card tails, dates, reference digits) are never misclaimed.
        // نکته رجکس: مقدار کامل گرفته می‌شود — حتی وقتی «ریال/تومان» بلافاصله بعدش است
        // (باگ قبلی: «25,000,000 ریال» به «25,000» بریده می‌شد).
        // نکته lookbehind: «:» حذف شد تا «مانده:153,175,934» (چسبیده با دونقطه) هم کاندیدا شود؛
        // قطعات زمان (13:17→«17») توسط دروازه بافت (مانده/مبلغ/ریال/+) رد می‌شوند.
        if (preg_match_all('#(?<![\-0-9/.])([0-9][0-9,\.]{1,18}[0-9])(?![0-9])#u', $norm, $m, PREG_OFFSET_CAPTURE)) {
            foreach (array_reverse($m[1]) as [$val, $off]) {
                $before = self::ctx($norm, $off, true, 72);
                $after  = self::ctx($norm, $off + strlen($val), false, 12);
                // «مانده/موجودی» با فاصله کلمات واسط هم deterministic است: «مانده قابل استفاده: X»
                // (پنجره/گپ بر حسب نویسه؛ متن فارسی ۲ بایت در بایت است)
                $isBalance = preg_match('#(?:مانده|موجودی)\D{0,48}$#u', $before) === 1;
                $isAmount  = $isBalance
                    ? false
                    : (preg_match('#مبلغ\D{0,48}$#u', $before) === 1
                        || preg_match('#^\D{0,4}(?:ر[یي]ال|تومان)#u', $after) === 1
                        || preg_match('#^\s*\+#u', $after) === 1); // بانک ملی: «111,798,088+» = واریز
                if ($isBalance) {
                    $add($off, $off + strlen($val), 'balance', 0.9);
                } elseif ($isAmount) {
                    $add($off, $off + strlen($val), 'amount', 0.85);
                }
            }
        }

        // ── 3) هر ران عددیِ پوشش‌داده‌نشده = کاندیدای «قابل انتخاب» (بدون پیشنهاد نوع).
        //       مدیر می‌تواند از dropdown نوعش را دستی انتخاب کند (مثل حساب/شناسه در فرمت بانک ملی).
        if (preg_match_all('#[*0-9][0-9*,.\-:]{0,28}[*0-9]#u', $norm, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$run, $off]) {
                $add((int) $off, (int) $off + strlen((string) $run), null, 0.0);
            }
        }

        usort($spans, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        // segments for rendering
        $segments = [];
        $pos = 0;
        foreach ($spans as $sp) {
            if ($sp['start'] > $pos) {
                $segments[] = ['text' => substr($norm, $pos, $sp['start'] - $pos), 'key' => null, 'span' => null];
            }
            $segments[] = [
                'text' => substr($norm, $sp['start'], $sp['end'] - $sp['start']),
                'key'  => $sp['key'],
                'span' => [$sp['start'], $sp['end'], $sp['key'], $sp['confidence']],
                'candidate' => $sp['key'] === null,
            ];
            $pos = $sp['end'];
        }
        if ($pos < strlen($norm)) {
            $segments[] = ['text' => substr($norm, $pos), 'key' => null, 'span' => null];
        }
        return ['normalized' => $norm, 'segments' => $segments, 'spans' => $spans];
    }

    /**
     * پنجره بافت حول یک آفست بایتی — همیشه در مرز نویسه.
     *
     * نکته حیاتی: substr خالص می‌تواند وسط نویسه فارسی برش بزند؛ آنگاه رشته
     * UTF-8 نامعتبر می‌شود و هر رجکسی با پرچم /u روی آن «بی‌صدا» شکست می‌خورد
     * (preg_match همان false برمی‌گرداند) — دقیقاً ریشه‌ی گم‌شدن «موجودی/مانده»
     * در بعضی نمونه‌ها و پیدا شدنشان در بعضی دیگر (بسته به محل برش).
     * mb_strcut هرگز نویسه را نمی‌شکافد.
     */
    private static function ctx(string $s, int $byteOff, bool $before, int $maxBytes = 40): string
    {
        if ($before) {
            $start = max(0, $byteOff - $maxBytes);
            return mb_strcut($s, $start, $byteOff - $start, 'UTF-8');
        }
        return mb_strcut($s, $byteOff, $maxBytes, 'UTF-8');
    }

    /**
     * Build a rule from admin-approved spans (byte offsets over the NORMALIZED sample).
     * Static parts around each span become escaped context anchors; the span becomes a
     * typed capture slot. Result is data (JSON), never executable code.
     */
    public static function buildRule(string $sample, array $spans, string $name = '', int $priority = 10): array
    {
        $norm = self::normalizeText($sample);
        $clean = [];
        foreach ($spans as $sp) {
            if (!is_array($sp) || count($sp) < 3) {
                continue;
            }
            $s = (int) ($sp['start'] ?? $sp[0] ?? 0);
            $e = (int) ($sp['end'] ?? $sp[1] ?? 0);
            $key = (string) ($sp['key'] ?? $sp[2] ?? '');
            if ($key === '' || !isset(self::TYPE_PATTERNS[$key])) {
                continue;
            }
            if ($s < 0 || $e <= $s || $e > strlen($norm)) {
                continue;
            }
            $clean[] = ['start' => $s, 'end' => $e, 'key' => $key];
        }
        // هر کلید فقط یک‌بار (نخستین رخداد) — دو «amount» یعنی خطای انتخاب
        $seen = [];
        $clean = array_values(array_filter($clean, static function (array $c) use (&$seen): bool {
            if (isset($seen[$c['key']])) {
                return false;
            }
            $seen[$c['key']] = true;
            return true;
        }));
        // amount is mandatory for a financial rule
        $hasAmount = false;
        foreach ($clean as $c) {
            if ($c['key'] === 'amount') {
                $hasAmount = true;
            }
        }
        if (!$hasAmount) {
            throw new DomainException('حداقل باید یک «مبلغ» در نمونه انتخاب شود.');
        }
        usort($clean, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $fields = [];
        foreach ($clean as $i => $c) {
            $before = $i === 0 ? '' : substr($norm, $clean[$i - 1]['end'], $c['start'] - $clean[$i - 1]['end']);
            $after  = '';
            if (isset($clean[$i + 1])) {
                $after = substr($norm, $c['end'], $clean[$i + 1]['start'] - $c['end']);
            }
            $transforms = [];
            if ($c['key'] === 'amount' || $c['key'] === 'balance') {
                $transforms[] = 'strip_separators';
            }
            if ($c['key'] === 'card_last4') {
                $transforms[] = 'last4'; // از ران کامل شماره کارت، چهار رقم آخر
            }
            $f = [
                'key'     => $c['key'],
                'pattern' => self::TYPE_PATTERNS[$c['key']],
                'before'  => self::shortCtx($before, true),
                'after'   => self::shortCtx($after, false),
                'transforms' => $transforms,
            ];
            if ($c['key'] === 'amount') {
                $f['currency_from_text'] = true;
            }
            $fields[] = $f;
        }
        return [
            'v' => 1,
            'name' => $name !== '' ? $name : 'قالب ' . date('His'),
            'priority' => $priority,
            'currency_default' => self::detectCurrency($norm, 'IRR'),
            'fields' => $fields,
            'required' => ['amount'],
        ];
    }

    /**
     * بافت ایستا برای انکر — حداکثر ۲۴ نویسه؛ لبه‌های رقم‌مانند حذف می‌شوند تا
     * انکر فقط «متن ثابت» باشد و روی پیامک‌هایی با ارقام متفاوت هم بیفتد.
     *
     * نکته دقت: برای انکرِ «قبل»، انتهای بافت (نزدیک‌ترین قسمت به جای‌خالی) نگه
     * داشته می‌شود — قطع از سر باعث می‌شد انکر وسط کلمه بریده شود و بی‌اثر گردد
     * (روی پیامک بعدی نمی‌گرفت و فیلد به حدس bare سقوط می‌کرد).
     */
    private static function shortCtx(string $s, bool $tail = true): string
    {
        $s = trim($s);
        $s = (string) preg_replace('#^[0-9,\.\s\-،؛:]+#u', '', $s);
        $s = (string) preg_replace('#[0-9,\.\s\-،؛:]+$#u', '', $s);
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if (mb_strlen($s) > 24) {
            $s = $tail ? mb_substr($s, -24) : mb_substr($s, 0, 24);
        }
        return $s;
    }

    // ------------------------------------------------------------ execution

    /**
     * Run rules (already sorted by priority) against a message.
     * @return array{ok:bool,rule_id?:int,rule_name?:string,confidence:string,fields:array,missing?:array,currency:string,amount_rial?:int,reject_reason?:string}
     */
    public static function runRules(array $rules, string $text): array
    {
        $norm = self::normalizeText($text);
        if ($norm === '' || mb_strlen($norm) > 1600) {
            return ['ok' => false, 'confidence' => 'None', 'fields' => [], 'missing' => ['message'], 'reject_reason' => 'پیامک خالی یا خیلی طولانی است.'];
        }
        // قواعد به‌ترتیب اولویت امتحان می‌شوند؛ در صورت تطبیق چند قالب، خاص‌ترین
        // (بیشترین فیلد استخراج‌شده) برنده می‌شود تا قالب‌های «گشاد» قالب دقیق را نبلعند.
        $best = null;
        $bestScore = -1;
        foreach ($rules as $r) {
            $out = self::runRule($r['rule'] ?? [], $norm);
            if ($out['ok']) {
                // فقط استخراج‌های «انکرشده» امتیاز دارند — قالب گشاد که با حدس bare فیلد می‌گیرد
                // نباید قالب دقیق‌تر (با انکرهای واقعی) را در انتخاب ببلعد.
                $score = (int) ($out['anchored'] ?? 0) * 100 - (int) ($r['priority'] ?? 100);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['ok' => true, 'rule_id' => (int) ($r['id'] ?? 0), 'rule_name' => (string) ($r['name'] ?? ''), 'confidence' => $out['confidence'], 'fields' => $out['fields'], 'currency' => $out['currency'], 'amount_rial' => $out['amount_rial']];
                }
            }
        }
        if ($best !== null) {
            return $best;
        }
        return ['ok' => false, 'confidence' => 'None', 'fields' => [], 'missing' => ['amount'], 'reject_reason' => 'هیچ قالبی از قالب‌های این کارت با پیامک مطابقت کامل نداشت (مبلغ یا فیلدهای الزامی پیدا نشد).'];
    }

    /** Run one rule; every pattern/transform is validated against allow-lists. */
    public static function runRule(array $rule, string $normalizedText): array
    {
        if (($rule['v'] ?? 0) !== 1 || !is_array($rule['fields'] ?? null)) {
            return ['ok' => false, 'fields' => [], 'missing' => ['rule'], 'currency' => 'IRR', 'amount_rial' => 0, 'confidence' => 'None'];
        }
        $extracted = [];
        $contextHits = 0;
        $contextTries = 0;
        $anchored = 0; // فیلدهایی که با انکر متن ثابت (نه حدس bare) گرفته شده‌اند
        $missing = [];
        foreach ($rule['fields'] as $f) {
            $key  = (string) ($f['key'] ?? '');
            if (!isset(self::TYPE_PATTERNS[$key])) {
                continue; // unknown field key — ignore (rules are data; validate keys)
            }
            $pat  = (string) ($f['pattern'] ?? '');
            $b    = (string) ($f['before'] ?? '');
            $a    = (string) ($f['after'] ?? '');
            // validate pattern: only allow listed shape for the key (defense against tampered rule JSON)
            if ($pat !== self::TYPE_PATTERNS[$key]) {
                $pat = self::TYPE_PATTERNS[$key];
            }
            $val = null;
            // بین انکر و مقدار، جداکننده‌های رایج tolerate می‌شوند: «مبلغ: 25,000,000» با انکر «مبلغ» هم می‌گیرد
            if ($b !== '') {
                $rx = '#' . preg_quote($b, '#') . '[\s:؛،.\-]*(' . $pat . ')#u';
                if (preg_match($rx, $normalizedText, $m)) {
                    $val = $m[1];
                }
            }
            if ($val === null && $a !== '') {
                $rx = '#(' . $pat . ')[\s:؛،.\-]*' . preg_quote($a, '#') . '#u';
                if (preg_match($rx, $normalizedText, $m)) {
                    $val = $m[1];
                }
            }
            $usedBare = false;
            $canBare = ($b === '' && $a === '') || in_array($key, self::BARE_KEYWORD_TYPES, true);
            if ($val === null && $canBare) {
                // آخرین چاره: الگوی لخت — فقط برای انواع کلیدواژه‌ای (txn_type/bank)
                // یا وقتی هیچ انکری وجود ندارد. اگر انکرِ عددی باشد ولی نگیرد، فیلد
                // نباید با حدس آزاد پر شود؛ وگرنه «45,000-» برداشتِ بانک ملی هم
                // به‌عنوان مبلغ match می‌شد.
                if (preg_match('#(' . $pat . ')#u', $normalizedText, $m)) {
                    $val = $m[1];
                    $usedBare = true; // حدس بدون انکر — در رتبه‌بندی قواعد کم‌وزن است
                }
            }
            if ($val !== null && !$usedBare && ($b !== '' || $a !== '')) {
                $anchored++;
            }
            if ($val === null) {
                if (in_array($key, $rule['required'] ?? ['amount'], true)) {
                    $missing[] = $key;
                }
                continue;
            }
            if ($b !== '' || $a !== '') {
                $contextTries++;
                if (mb_substr_count($normalizedText, $val) === 1) {
                    $contextHits++;
                }
            }
            foreach ((array) ($f['transforms'] ?? []) as $t) {
                if ($t === 'strip_separators') {
                    $val = preg_replace('#[,\.]#', '', $val);
                } elseif ($t === 'normalize_digits') {
                    $val = self::normalizeText($val);
                } elseif ($t === 'last4') {
                    // فقط ارقام را نگه دار، چهار رقم آخر (شماره کارت کامل/ماسک‌شده)
                    $digits = preg_replace('#[^0-9]#', '', $val);
                    $val = strlen((string) $digits) > 4 ? substr((string) $digits, -4) : $digits;
                }
            }
            $extracted[$key] = mb_substr(trim((string) $val), 0, 60);
        }
        if ($missing !== []) {
            return ['ok' => false, 'fields' => $extracted, 'missing' => $missing, 'currency' => 'IRR', 'amount_rial' => 0, 'confidence' => 'None', 'anchored' => $anchored];
        }
        // transaction must be a deposit
        if (isset($extracted['txn_type']) && preg_match('#برداشت|خرید#u', $extracted['txn_type'])) {
            return ['ok' => false, 'fields' => $extracted, 'missing' => ['txn_type'], 'currency' => 'IRR', 'amount_rial' => 0, 'confidence' => 'None', 'anchored' => $anchored, 'reject_reason' => 'تراکنش واریز نیست (برداشت/خرید).'];
        }
        $amountRaw = $extracted['amount'] ?? '';
        $amountDigits = preg_replace('#[^0-9]#', '', $amountRaw);
        if ($amountDigits === '' || (int) $amountDigits <= 0) {
            return ['ok' => false, 'fields' => $extracted, 'missing' => ['amount'], 'currency' => 'IRR', 'amount_rial' => 0, 'confidence' => 'None'];
        }
        $currency = self::detectCurrency($normalizedText, (string) ($rule['currency_default'] ?? 'IRR'));
        $rial = self::toRial($amountDigits, $currency);
        $confidence = ($contextTries === 0 || $contextHits / max(1, $contextTries) >= 0.7) ? 'High' : 'Medium';
        return ['ok' => true, 'fields' => $extracted, 'missing' => [], 'currency' => $currency, 'amount_rial' => $rial, 'confidence' => $confidence, 'anchored' => $anchored];
    }

    /** Deterministic duplicate fingerprint (strong hash over transaction attributes). */
    public static function fingerprint(int $cardId, string $sender, int $amountRial, string $reference, string $rawHash): string
    {
        return hash('sha256', $cardId . '|' . self::normalizeSender($sender) . '|' . $amountRial . '|' . trim($reference) . '|' . $rawHash);
    }
}
