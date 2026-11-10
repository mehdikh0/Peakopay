<?php
/** Tests: input validation (Persian digits, card, mobile, ranges). */
declare(strict_types=1);

section('Validator');
$v = Validator::make(['name' => '  علی  ', 'age' => '30', 'card' => '۶۲۱۹-۸۶۰۰-۰۰۰۰-۰۰۱۹', 'phone' => '۰۹۱۲۱۲۳۴۵۶۷']);
$v->rule('name', 'required|minlen:2', 'نام')
  ->rule('age', 'required|int|min:18|max:99', 'سن')
  ->rule('card', 'required|card', 'کارت')
  ->rule('phone', 'nullable|mobile', 'موبایل')
  ->rule('email', 'nullable|email', 'ایمیل');
t('all valid passes', !$v->fails());
eq('persian digits normalized', '6219-8600-0000-0019', $v->value('card'));
eq('persian phone normalized', '09121234567', $v->value('phone'));
eq('trim applied', 'علی', $v->value('name'));

$v2 = Validator::make(['card' => '6219860000000018', 'email' => 'nope', 'n' => '']);
$v2->rule('card', 'required|card', 'کارت')->rule('email', 'required|email', 'ایمیل')->rule('n', 'required', 'نام');
t('invalid inputs fail', $v2->fails());
t('error mentions card', isset($v2->errors()['card']));
t('error mentions email', isset($v2->errors()['email']));
t('error mentions required', isset($v2->errors()['n']));

$v3 = Validator::make(['mode' => 'weird']);
$v3->rule('mode', 'required|in:a;b;c', 'حالت');
t('in: rejects out-of-list', $v3->fails());

$v4 = Validator::make(['url' => 'javascript:alert(1)']);
$v4->rule('url', 'nullable|url', 'آدرس');
t('url: rejects non-http scheme', $v4->fails());

// Log redaction
section('Log redaction');
$msg = 'payment for card 6219860000000019 key hpk_ab12cd34ef567890 done';
$red = Log::redact($msg);
t('card number redacted', !str_contains($red, '6219860000000019'));
t('api key redacted', !str_contains($red, 'hpk_ab12cd34ef567890'));
