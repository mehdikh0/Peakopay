<?php
/** Tests: encryption at rest, key handling, card masking/validation. */
declare(strict_types=1);

section('Crypto: encrypt/decrypt round-trip + tamper detection');
$plain = 'شماره کارت: 6219860000000019 — سلام';
$enc = Crypto::encrypt($plain);
t('encrypt returns prefixed ciphertext', str_starts_with($enc, (Crypto::hasSodium() ? 's1:' : 'o1:')));
eq('decrypt(encrypt(x)) === x', $plain, Crypto::decrypt($enc));
eq('empty input → empty blob', '', Crypto::encrypt(''));
eq('empty blob → null', null, Crypto::decrypt(''));

$tampered = substr($enc, 0, -4) . 'AAAA';
t('tampered ciphertext rejected', Crypto::decrypt($tampered) === null);

$blob2 = substr($enc, 0, 3) . base64_encode(random_bytes(40));
t('garbage blob rejected', Crypto::decrypt($blob2) === null);

section('Crypto: key + tokens');
$key = Crypto::generateKeyHex();
t('generated key is 64 hex chars', ctype_xdigit($key) && strlen($key) === 64);
t('api key format', fnmatch('hpk_*', Crypto::newApiKey()) && strlen(Crypto::newApiKey()) === 44);
t('device secret format', str_starts_with(Crypto::newDeviceSecret(), 'hpd_'));
t('pairing code alphabet (no ambiguous chars)', !preg_match('#[O0Il1]#', Crypto::newPairingCode(50)));
eq('hmac deterministic', Crypto::hmac('a.b', 'k'), Crypto::hmac('a.b', 'k'));
t('hash_equals works', Crypto::equal('abc', 'abc') && !Crypto::equal('abc', 'abd'));

section('Crypto: card helpers');
eq('card normalization strips separators', '6219860000000019', Crypto::normalizeCardNumber('6219-8600 0000 0019'));
t('luhn valid card accepted', Crypto::luhnValid('6219860000000019'));
t('luhn invalid card rejected', !Crypto::luhnValid('6219860000000018'));
eq('mask', '621986******0019', Crypto::maskCard('6219860000000019'));
eq('mask pretty', '6219 86** **** 0019', Crypto::maskCardPretty('6219860000000019'));
eq('format grouped', '6219 8600 0000 0019', Crypto::formatCard('6219-8600-0000-0019'));
eq('last4', '0019', Crypto::last4('6219860000000019'));
t('short garbage not luhn-valid', !Crypto::luhnValid('1234'));
t('iban valid (IR)', Validator::ibanValid('IR820540102680020817909002'));
t('iban invalid checksum rejected', !Validator::ibanValid('IR820540102680020817909003'));
