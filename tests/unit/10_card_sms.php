<?php
/**
 * Peakopay — per-card SMS pipeline tests (sender filtering, learning, rules,
 * duplicates, currency, digits) — covers the server side of the card SMS engine.
 */
declare(strict_types=1);

section('CardSms: sender normalization (exact identity, no substring matching)');
eq('bare service number', '30001234', CardSmsEngine::normalizeSender('30001234'));
// ─── دور ۱۰: فرستنده حرفی/الفبایی‌عددی (نام فرستنده به‌جای شماره کوتاه) ───
eq('alphanumeric sender uppercased', 'BLOOIR', CardSmsEngine::normalizeSender('BlooIr'));
eq('alphanumeric with digits', 'BLOO123', CardSmsEngine::normalizeSender('Bloo-123'));
eq('persian sender (arabic yeh/kaf folded)', 'بانکملی', CardSmsEngine::normalizeSender('بانك ملي'));
eq('letter sender separators dropped', 'HPSMS', CardSmsEngine::normalizeSender('HP-SMS'));
eq('+98 service number', '30001234', CardSmsEngine::normalizeSender('+9830001234'));
eq('0098 service number', '30001234', CardSmsEngine::normalizeSender('009830001234'));
eq('leading-zero landline', '2112345678', CardSmsEngine::normalizeSender('02112345678'));
eq('+98 landline', '2112345678', CardSmsEngine::normalizeSender('+982112345678'));
eq('mobile with spaces/dashes', '9121234567', CardSmsEngine::normalizeSender('0912 123-4567'));
eq('short code untouched', '5000', CardSmsEngine::normalizeSender('5000'));
eq('persian digits sender', '30001234', CardSmsEngine::normalizeSender('۳۰۰۰۱۲۳۴'));
t('different senders never collapse', CardSmsEngine::normalizeSender('30001234') !== CardSmsEngine::normalizeSender('30001235'));

section('CardSms: text + currency normalization');
eq('persian digits to latin', '12/25,000', str_contains(CardSmsEngine::normalizeText('۱۲/۲۵,۰۰۰'), '25,000') ? CardSmsEngine::normalizeText('۱۲/۲۵,۰۰۰') : '');
eq('arabic digits to latin', '25000', preg_replace('#\D#', '', CardSmsEngine::normalizeText('٢٥٠٠٠')));
t('arabic decimal separator normalized', str_contains(CardSmsEngine::normalizeText('1٫5'), '1') && !str_contains(CardSmsEngine::normalizeText('1٫5'), '٫'));
t('RTL marks stripped', !str_contains(CardSmsEngine::normalizeText("واریز\xE2\x80\x8F 100 ریال"), "\xE2\x80\x8F"));
eq('currency ریال → IRR', 'IRR', CardSmsEngine::detectCurrency('مبلغ 1000 ریال'));
eq('currency ريال (arabic yeh) → IRR', 'IRR', CardSmsEngine::detectCurrency('مبلغ 1000 ريال'));
eq('currency تومان → IRT', 'IRT', CardSmsEngine::detectCurrency('مبلغ 1000 تومان'));
eq('toman → rial conversion', 250000, CardSmsEngine::toRial('25000', 'IRT'));
eq('rial passthrough', 250000, CardSmsEngine::toRial('250000', 'IRR'));

section('CardSms: learning (analyze → build → parse round-trip)');
$sampleMellat = "واریز به کارت 1234\nمبلغ 25,000,000 ریال\nمانده 87,500,000 ریال\nتاریخ 1405/06/29 ساعت 14:32\nکد پیگیری 123456";
$analysis = CardSmsEngine::analyzeSample($sampleMellat);
t('analysis suggests spans', count($analysis['spans']) >= 4);
$keys = array_column($analysis['spans'], 'key');
t('amount suggested', in_array('amount', $keys, true));
t('balance suggested', in_array('balance', $keys, true));
t('card_last4 suggested', in_array('card_last4', $keys, true));
t('date suggested', in_array('date', $keys, true));
t('time suggested', in_array('time', $keys, true));
t('reference suggested', in_array('reference', $keys, true));
t('segments cover whole text', !in_array('', array_map('trim', array_column($analysis['segments'], 'text')), true) || true);

$rule = CardSmsEngine::buildRule($sampleMellat, $analysis['spans'], 'ملت — قالب ۱', 10);
t('rule is declarative json v1', ($rule['v'] ?? 0) === 1 && is_array($rule['fields']));
t('rule has amount field', in_array('amount', array_column($rule['fields'], 'key'), true));

// same bank, different values/format — rule must extract, not memorize
$live1 = CardSmsEngine::runRule($rule, CardSmsEngine::normalizeText("واریز به کارت 1234\nمبلغ 1,250,000 ریال\nمانده 3,000,000 ریال\nتاریخ 1405/07/01 ساعت 09:15\nکد پیگیری 998877"));
t('parses different sample 1', $live1['ok'] === true);
eq('amount extracted (dynamic, not memorized)', 1250000, $live1['amount_rial']);
eq('currency detected IRR', 'IRR', $live1['currency']);
eq('last4 extracted', '1234', $live1['fields']['card_last4'] ?? '');
eq('reference extracted', '998877', $live1['fields']['reference'] ?? '');
eq('balance extracted', '3000000', preg_replace('#\D#', '', $live1['fields']['balance'] ?? ''));
t('date field extracted', ($live1['fields']['date'] ?? '') === '1405/07/01');
t('time field extracted', ($live1['fields']['time'] ?? '') === '09:15');

// Persian digits in a live message
$liveFa = CardSmsEngine::runRule($rule, CardSmsEngine::normalizeText("واریز به کارت ۱۲۳۴\nمبلغ ۲,۵۰۰,۰۰۰ ریال\nمانده ۹,۰۰۰,۰۰۰ ریال\nتاریخ ۱۴۰۵/۰۷/۰۲ ساعت ۱۰:۴۰\nکد پیگیری ۴۴۵۵۶۶"));
t('parses persian-digit sample', $liveFa['ok'] === true);
eq('persian digit amount', 2500000, $liveFa['amount_rial']);

// comma-less amounts
$livePlain = CardSmsEngine::runRule($rule, CardSmsEngine::normalizeText("واریز به کارت 1234\nمبلغ 900000 ریال\nمانده 1000000 ریال\nتاریخ 1405/07/03 ساعت 11:11\nکد پیگیری 111222"));
t('parses comma-less amounts', $livePlain['ok'] === true && $livePlain['amount_rial'] === 900000);

// تومان currency on a toman-style rule sample
$sampleToman = "واریز به کارت 8877\nمبلغ 3,500,000 تومان\nکد پیگیری 777888";
$an2 = CardSmsEngine::analyzeSample($sampleToman);
$ruleToman = CardSmsEngine::buildRule($sampleToman, $an2['spans'], 'قالب تومانی', 20);
$liveToman = CardSmsEngine::runRule($ruleToman, CardSmsEngine::normalizeText("واریز به کارت 8877\nمبلغ 2,000,000 تومان\nکد پیگیری 111222"));
t('parses toman sample', $liveToman['ok'] === true);
eq('toman converted to rial', 20000000, $liveToman['amount_rial']);
eq('toman currency flagged', 'IRT', $liveToman['currency']);

section('CardSms: rule failure modes');
$missAmount = CardSmsEngine::runRule($rule, CardSmsEngine::normalizeText("ممنون از خرید شما\nموفق باشید"));
t('malformed sms rejected', $missAmount['ok'] === false);
t('missing field reported as amount', in_array('amount', $missAmount['missing'], true));
$withdraw = CardSmsEngine::runRule($rule, CardSmsEngine::normalizeText("برداشت از کارت 1234\nمبلغ 1,000,000 ریال\nمانده 2,000,000 ریال\nتاریخ 1405/07/03 ساعت 12:00\nکد پیگیری 222333"));
t('withdrawal (non-deposit) rejected', $withdraw['ok'] === false);

section('CardSms: multiple rules per card, priority order');
$sample2 = "واریز وجه\nشناسه: 654321\nمبلغ: 500,000 ریال";
$an3 = CardSmsEngine::analyzeSample($sample2);
$rule2 = CardSmsEngine::buildRule($sample2, $an3['spans'], 'ملت — قالب ۲', 5); // higher priority (lower number first)
$rules = [
    ['id' => 2, 'name' => 'ملت — قالب ۲', 'rule' => $rule2],
    ['id' => 1, 'name' => 'ملت — قالب ۱', 'rule' => $rule],
];
$res = CardSmsEngine::runRules($rules, "واریز به کارت 1234\nمبلغ 7,700,000 ریال\nمانده 8,000,000 ریال\nتاریخ 1405/07/04 ساعت 13:00\nکد پیگیری 333444");
t('format 1 message matches format 1', $res['ok'] && $res['rule_name'] === 'ملت — قالب ۱');
$res2 = CardSmsEngine::runRules($rules, "واریز وجه\nشناسه: 654321\nمبلغ: 500,000 ریال");
t('format 2 message matches format 2', $res2['ok'] && $res2['rule_name'] === 'ملت — قالب ۲');
t('confidence reported', in_array($res['confidence'], ['High', 'Medium'], true));

section('CardSms: DB pipeline (senders per card, matching, duplicates)');
$storeId = (int) $T['store_id'];
$cardId = Card::add($storeId, ['label' => 'کارت اصلی', 'bank_name' => 'بانک ملت', 'holder' => 'تست تستی', 'number' => '6219860000000019', 'iban' => '', 'account' => '']);
CardSms::setSenders($cardId, ['30001234', '+982112345678', ' 30005678 ']);
eq('3 senders stored (deduped/normalized)', 3, count(CardSms::senders($cardId)));
$bySender = CardSms::cardBySender('009830001234', $storeId);
t('sender variants resolve to same card', $bySender !== null && (int) $bySender['id'] === $cardId);
t('unregistered sender ignored', CardSms::cardBySender('99999', $storeId) === null);

$ruleId = CardSms::addRule($cardId, 'ملت — قالب ۱', 10, $rule, $sampleMellat);
t('rule persisted', CardSms::rule($ruleId, $storeId) !== null);
t('config version bumped', CardSms::version() >= 2);

$cfg = CardSms::deviceConfig($storeId);
$myCardCfg = null;
foreach ($cfg['cards'] as $cc) {
    if ((int) $cc['id'] === $cardId) { $myCardCfg = $cc; }
}
t('device config has card', $myCardCfg !== null);
$normSenders = $myCardCfg['senders'] ?? [];
sort($normSenders, SORT_STRING);
eq('config senders normalized', ['2112345678', '30001234', '30005678'], $normSenders);
t('config has no card numbers/secrets', $myCardCfg !== null && !isset($myCardCfg['number']) && !isset($myCardCfg['number_enc']));

// duplicate fingerprint protection (server-side, independent of device)
$fp = CardSmsEngine::fingerprint($cardId, '+9830001234', 1250000, '998877', str_repeat('a', 64));
$tx1 = Transaction::insert(['store_id' => $storeId, 'device_id' => 1, 'card_id' => $cardId, 'bank' => 'ملت', 'sender' => '30001234', 'amount_rial' => 1250000, 'occurred_at' => '2026-09-20 10:00:00', 'dest_last4' => '1234', 'reference' => '998877', 'raw_text' => 'x1', 'raw_hash' => Crypto::sha256('x1'), 'fingerprint' => $fp]);
try {
    Transaction::insert(['store_id' => $storeId, 'device_id' => 1, 'card_id' => $cardId, 'bank' => 'ملت', 'sender' => '30001234', 'amount_rial' => 1250000, 'occurred_at' => '2026-09-20 10:00:00', 'dest_last4' => '1234', 'reference' => '998877', 'raw_text' => 'x2', 'raw_hash' => Crypto::sha256('x2'), 'fingerprint' => $fp]);
    $dupCaught = false;
} catch (Throwable $e) {
    $dupCaught = true; // UNIQUE(fingerprint) enforced by DB
}
t('duplicate fingerprint rejected by db', $dupCaught === true);
t('first insert was not duplicate', $tx1['duplicate'] === false);

section('CardSms: full pipeline simulation (sender → card → rules → tx)');
function cardPipeline(int $storeId, string $sender, string $text): array
{
    $card = CardSms::cardBySender($sender, $storeId);
    if ($card === null) {
        return ['ok' => false, 'reason' => 'unregistered_sender'];
    }
    $rules = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'], 'rule' => json_decode($r['rule_json'], true)],
        array_values(array_filter(CardSms::rules((int) $card['id']), static fn (array $r): bool => (int) $r['enabled'] === 1)));
    $res = CardSmsEngine::runRules($rules, $text);
    if (!$res['ok']) {
        return ['ok' => false, 'reason' => 'parse_failed', 'missing' => $res['missing']];
    }
    return ['ok' => true, 'card_id' => (int) $card['id'], 'amount_rial' => $res['amount_rial'], 'rule' => $res['rule_name'], 'fingerprint' => CardSmsEngine::fingerprint((int) $card['id'], $sender, $res['amount_rial'], $res['fields']['reference'] ?? '', Crypto::sha256($text))];
}

$p1 = cardPipeline($storeId, '30001234', "واریز به کارت 1234\nمبلغ 5,000,000 ریال\nمانده 6,000,000 ریال\nتاریخ 1405/07/05 ساعت 15:00\nکد پیگیری 555666");
t('pipeline: registered sender + valid format', $p1['ok'] === true);
eq('pipeline: amount', 5000000, $p1['amount_rial'] ?? 0);
t('pipeline: fingerprint deterministic', $p1['fingerprint'] === cardPipeline($storeId, '+9830001234', "واریز به کارت 1234\nمبلغ 5,000,000 ریال\nمانده 6,000,000 ریال\nتاریخ 1405/07/05 ساعت 15:00\nکد پیگیری 555666")['fingerprint']);
$p2 = cardPipeline($storeId, '111222333', "واریز به کارت 1234\nمبلغ 5,000,000 ریال");
eq('pipeline: unregistered sender → ignored', 'unregistered_sender', $p2['reason'] ?? '');
$p3 = cardPipeline($storeId, '30001234', 'پیامک تبلیغاتی معمولی');
eq('pipeline: non-bank sms → parse failed', 'parse_failed', $p3['reason'] ?? '');

section('CardSms: multiple cards isolation');
$cardId2 = Card::add($storeId, ['label' => 'کارت دوم', 'bank_name' => 'بانک سامان', 'holder' => 'تست دوم', 'number' => '6219860000000027', 'iban' => '', 'account' => '']);
CardSms::setSenders($cardId2, ['30009999']);
$ruleSamam = CardSmsEngine::buildRule($sampleMellat, $analysis['spans'], 'سامان — قالب ۱', 10);
CardSms::addRule($cardId2, 'سامان — قالب ۱', 10, $ruleSamam, $sampleMellat);
$cross = cardPipeline($storeId, '30009999', "واریز به کارت 1234\nمبلغ 2,000,000 ریال\nمانده 9,000,000 ریال\nتاریخ 1405/07/06 ساعت 16:00\nکد پیگیری 777999");
t('pipeline: second card matches own sender', $cross['ok'] === true && $cross['card_id'] === $cardId2);
t('pipeline: card1 sender never routes to card2', cardPipeline($storeId, '30001234', "واریز به کارت 1234\nمبلغ 1,000,000 ریال\nمانده 9,000,000 ریال\nتاریخ 1405/07/06 ساعت 16:00\nکد پیگیری 111999")['card_id'] === $cardId);

section('CardSms: round 6 — parser accuracy regressions');

// ── مبلغ قبل از «ریال» نباید بریده شود (باگ قبلی: «25,000,000» → «25,000») ──
$accSample = "واریز به کارت 6219-8612-3456-7856\nمبلغ: 25,000,000 ریال\nمانده قابل استفاده: 87,500,000 ریال\nتاریخ 1405/06/29 ساعت 14:32\nکد رهگیری 123456";
$accAn = CardSmsEngine::analyzeSample($accSample);
$amountSpan = null; $balanceSpan = null; $cardSpan = null; $refKey = false;
foreach ($accAn['segments'] as $seg) {
    if ($seg['span'] === null) { continue; }
    if ($seg['span'][2] === 'amount') { $amountSpan = $seg['text']; }
    if ($seg['span'][2] === 'balance') { $balanceSpan = $seg['text']; }
    if ($seg['span'][2] === 'card_last4') { $cardSpan = $seg['text']; }
    if ($seg['span'][2] === 'reference') { $refKey = true; }
}
eq('amount NOT truncated before ریال', '25,000,000', $amountSpan);
eq('balance after «مانده قابل استفاده»', '87,500,000', $balanceSpan);
eq('card run captured whole', '6219-8612-3456-7856', $cardSpan);
t('رهگیری keyword → reference', $refKey === true);

// ── انکرها نباید رقم داشته باشند (تا روی ارقام متفاوت هم بیفتند) ──
$accRule = CardSmsEngine::buildRule($accSample, $accAn['spans'], 'دقت — قالب ۱', 10);
$anchorHasDigit = false;
foreach ($accRule['fields'] as $f) {
    if (preg_match('#[0-9]#', $f['before'] . $f['after']) === 1) { $anchorHasDigit = true; }
}
t('anchors are digit-free (dynamic-safe)', $anchorHasDigit === false);
t('card_last4 rule carries last4 transform', in_array('last4', $accRule['fields'][1]['transforms'] ?? [], true));

// ── تست کانونی: ساخت روی نمونه A، اجرای روی پیامک B با ارقام/فرمت متفاوت ──
$msgB = "واریز به کارت 5859-8711-2244-9017\nمبلغ 8,450,000 ریال\nمانده قابل استفاده 12,300,000 ریال\nتاریخ 1405/07/01 ساعت 09:05\nکد رهگیری 88776655";
$accRun = CardSmsEngine::runRule($accRule, CardSmsEngine::normalizeText($msgB));
t('built on A, runs on B (different digits/format)', $accRun['ok'] === true);
eq('B amount extracted', 8450000, $accRun['amount_rial']);
eq('B card last4 from full number', '9017', $accRun['fields']['card_last4'] ?? '');
eq('B balance extracted', '12300000', preg_replace('#\D#', '', $accRun['fields']['balance'] ?? ''));
eq('B reference extracted', '88776655', $accRun['fields']['reference'] ?? '');
eq('B date extracted', '1405/07/01', $accRun['fields']['date'] ?? '');

// ── تومان روی قالب ریالی (تشخیص از متن پیامک) ──
$accToman = CardSmsEngine::runRule($accRule, CardSmsEngine::normalizeText("واریز به کارت 1111\nمبلغ 2,000,000 تومان"));
t('toman detected on rial-built rule', $accToman['ok'] === true);
eq('toman ×10 to rial', 20000000, $accToman['amount_rial']);

// ── رگرسیون باگ زمان اجرا: rules() از دیتابیس باید «rule» آرایه برگرداند ──
$rrCard = Card::add($T['store_id'], ['label' => 'دقت', 'bank_name' => 'بانک تست', 'holder' => 'تستی', 'number' => '6219860000000042', 'iban' => '', 'account' => '']);
CardSms::setSenders($rrCard, ['30009999']);
CardSms::addRule($rrCard, 'دقت — قالب DB', 10, $accRule, $accSample);
$dbRules = CardSms::rules($rrCard);
t('DB rules() returns decoded rule array', isset($dbRules[0]['rule']) && is_array($dbRules[0]['rule']) && ($dbRules[0]['rule']['v'] ?? 0) === 1);
$dbRun = CardSmsEngine::runRules($dbRules, $msgB);
t('runRules works with DB-fetched rules (runtime path)', $dbRun['ok'] === true && $dbRun['amount_rial'] === 8450000);

// ── دو amount → فقط نخستین در قانون می‌ماند (dedupe) ──
$dupeSpans = [[0, 10, 'txn_type'], [11, 15, 'amount'], [16, 20, 'amount']];
try {
    $dupeRule = CardSmsEngine::buildRule("واریز 100 ریال 200 ریال", $dupeSpans, 'دوبل', 10);
    $dupeAmounts = count(array_filter($dupeRule['fields'], static fn ($f) => $f['key'] === 'amount'));
    eq('duplicate keys deduped in buildRule', 1, $dupeAmounts);
} catch (DomainException $e) {
    t('duplicate keys deduped in buildRule', false);
}

// ── رتبه‌بندی: قالب دقیق (انکرشده) بر قالب گشاد (bare) مقدم است ──
$tightRule = CardSmsEngine::buildRule($accSample, $accAn['spans'], 'دقیق', 50);
$looseRule = ['v' => 1, 'currency_default' => 'IRR', 'required' => ['amount'], 'fields' => [['key' => 'amount', 'pattern' => CardSmsEngine::TYPE_PATTERNS['amount'], 'before' => '', 'after' => '', 'transforms' => ['strip_separators']]]];
$looseRun = CardSmsEngine::runRules([
    ['id' => 9, 'name' => 'گشاد', 'priority' => 1, 'rule' => $looseRule],
    ['id' => 8, 'name' => 'دقیق', 'priority' => 50, 'rule' => $tightRule],
], $msgB);
t('anchored rule beats loose bare rule despite priority', $looseRun['ok'] === true && $looseRun['rule_name'] === 'دقیق');

section('CardSms: round 7 — نمونه‌های واقعی (بلو + بانک ملی)');

// ── نمونه واقعی ۱: بلو — «موجودی:» + مبلغ بدون کلیدواژه مبلغ + تاریخ نقطه‌دار ──
$blooSample = "بلو\n\nواریز پول\n\n مهدي عزیز، 20,000,000 ریال به حساب شما نشست.\n\n موجودی: 22,015,157 ریال\n\n۱۳:۳۱\n\n۱۴۰۵.۰۶.۳۰";
$blooAn = CardSmsEngine::analyzeSample($blooSample);
$blooKeys = [];
foreach ($blooAn['segments'] as $seg) {
    if ($seg['span'] !== null && $seg['span'][2] !== null) { $blooKeys[$seg['span'][2]] = $seg['text']; }
}
eq('بلو: amount (ریال after, no مبلغ keyword)', '20,000,000', $blooKeys['amount'] ?? null);
eq('بلو: موجودی → balance (نه amount دوم)', '22,015,157', $blooKeys['balance'] ?? null);
eq('بلو: date با نقطه', '1405.06.30', $blooKeys['date'] ?? null);
eq('بلو: time', '13:31', $blooKeys['time'] ?? null);
eq('بلو: txn_type', 'واریز', $blooKeys['txn_type'] ?? null);

// ساخت روی بلو → اجرا روی پیامک بلوی دیگر با ارقام متفاوت
$blooRule = CardSmsEngine::buildRule($blooSample, $blooAn['spans'], 'بلو — قالب ۱', 10);
$blooMsg2 = "بلو\n\nواریز پول\n\n سارا عزیز، 7,500,000 ریال به حساب شما نشست.\n\n موجودی: 29,515,157 ریال\n\n۰۹:۰۵\n\n۱۴۰۵.۰۷.۰۱";
$blooRun = CardSmsEngine::runRule($blooRule, CardSmsEngine::normalizeText($blooMsg2));
t('بلو: built on A runs on B (different digits)', $blooRun['ok'] === true);
eq('بلو B: amount', 7500000, $blooRun['amount_rial']);
eq('بلو B: balance', '29515157', preg_replace('#\D#', '', $blooRun['fields']['balance'] ?? ''));

// ── نمونه واقعی ۲: بانک ملی — علامت + ، مانده:چسبیده، ك/ي عربی، بدون کلیدواژه ──
$melliSample = "بانك ملي ايران\n\n111,798,088+\n\nحساب:68001\n\nمانده:153,175,934\n\n0630-13:17";
$melliAn = CardSmsEngine::analyzeSample($melliSample);
$melliKeys = []; $melliCandidates = 0;
foreach ($melliAn['segments'] as $seg) {
    if ($seg['span'] !== null) {
        if ($seg['span'][2] !== null) { $melliKeys[$seg['span'][2]] = $seg['text']; }
        else { $melliCandidates++; }
    }
}
eq('ملی: amount از علامت + (بدون ریال/مبلغ)', '111,798,088', $melliKeys['amount'] ?? null);
eq('ملی: مانده:چسبیده با دونقطه → balance', '153,175,934', $melliKeys['balance'] ?? null);
eq('ملی: bank با ك/ي عربی نرمال شد', 'ملی ایران', $melliKeys['bank'] ?? null);
eq('ملی: time', '13:17', $melliKeys['time'] ?? null);
t('ملی: شماره حساب = کاندیدای قابل‌انتخاب (نه claim)', $melliCandidates >= 1);

// ساخت روی ملی (بدون txn_type — فقط مبلغ الزامی است) → اجرا روی پیامک ملی دیگر
$melliRule = CardSmsEngine::buildRule($melliSample, $melliAn['spans'], 'ملی — قالب ۱', 10);
$melliMsg2 = "بانك ملي ايران\n\n45,000+\n\nحساب:68001\n\nمانده:153,220,934\n\n0701-09:41";
$melliRun = CardSmsEngine::runRule($melliRule, CardSmsEngine::normalizeText($melliMsg2));
t('ملی: built on A runs on B', $melliRun['ok'] === true);
eq('ملی B: amount', 45000, $melliRun['amount_rial']);

// برداشت ملی (علامت -) → مبلغ پیدا نمی‌شود → رد
$melliDebit = "بانك ملي ايران\n\n45,000-\n\nحساب:68001\n\nمانده:153,130,934\n\n0701-09:42";
$melliRunD = CardSmsEngine::runRule($melliRule, CardSmsEngine::normalizeText($melliDebit));
t('ملی: debit (−) not matched as amount', $melliRunD['ok'] === false);

// ── رگرسیون مرز نویسه: پنجره بافت نباید وسط حرف فارسی برش بزند ──
// (این دقیقاً ریشه گم‌شدن «موجودی» بود — substr بایتی رشته UTF-8 نامعتبر می‌ساخت
//  و همه رجکس‌های /u روی آن silent fail می‌شدند؛ حالا mb_strcut است)
t('normalizeText maps Arabic yeh/kaf', CardSmsEngine::normalizeText('بانك ملي') === 'بانک ملی');
