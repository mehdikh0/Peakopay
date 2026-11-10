<?php
/**
 * Peakopay — Cryptography.
 *
 * Sensitive values (card numbers, device secrets, webhook secrets, raw SMS
 * text…) are encrypted at rest with a key generated during installation.
 *
 * Primary cipher: libsodium secretbox (XSalsa20-Poly1305) when ext-sodium is
 * available. Fallback: OpenSSL AES-256-GCM (authenticated encryption) — so a
 * missing optional sodium extension never breaks the installation. Ciphertexts
 * are version-tagged ("s1:" / "o1:") and both formats can always be decrypted,
 * so moving hosts never loses data.
 */
declare(strict_types=1);

final class Crypto
{
    public static function key(): string
    {
        $hex = (string) Config::get('app_key', '');
        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            throw new RuntimeException('Application key is missing or malformed.');
        }
        return hex2bin($hex);
    }

    public static function generateKeyHex(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hasSodium(): bool
    {
        return extension_loaded('sodium') && function_exists('sodium_crypto_secretbox');
    }

    // --------------------------------------------------------------- at rest

    public static function encrypt(?string $plain): string
    {
        $plain = trim((string) $plain);
        if ($plain === '') {
            return '';
        }
        $key = self::key();
        if (self::hasSodium()) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ct    = sodium_crypto_secretbox($plain, $nonce, $key);
            return 's1:' . base64_encode($nonce . $ct);
        }
        $tag = '';
        $iv  = random_bytes(12);
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('OpenSSL AES-256-GCM encryption failed.');
        }
        return 'o1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(?string $blob): ?string
    {
        $blob = trim((string) $blob);
        if ($blob === '') {
            return null;
        }
        $key = self::key();
        if (str_starts_with($blob, 's1:')) {
            if (!self::hasSodium()) {
                throw new RuntimeException('Data was encrypted with libsodium but ext-sodium is not loaded on this server.');
            }
            $raw = base64_decode(substr($blob, 3), true);
            $n   = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if ($raw === false || strlen($raw) <= $n) {
                return null;
            }
            $pt = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
            return $pt === false ? null : $pt;
        }
        if (str_starts_with($blob, 'o1:')) {
            $raw = base64_decode(substr($blob, 3), true);
            if ($raw === false || strlen($raw) < 29) {
                return null;
            }
            $iv  = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ct  = substr($raw, 28);
            $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $pt === false ? null : $pt;
        }
        return null;
    }

    // ----------------------------------------------------------- primitives

    public static function sha256(string $data): string
    {
        return hash('sha256', $data);
    }

    public static function hmac(string $data, string $key): string
    {
        return hash_hmac('sha256', $data, $key);
    }

    public static function equal(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    public static function randomHex(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** URL-safe random token (default 40 chars). */
    public static function randomToken(int $length = 40): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max      = strlen($alphabet) - 1;
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /** WooCommerce API key: hpk_ + 40 hex chars. */
    public static function newApiKey(): string
    {
        return 'hpk_' . bin2hex(random_bytes(20));
    }

    /** Android device secret (used as an HMAC key). */
    public static function newDeviceSecret(): string
    {
        return 'hpd_' . bin2hex(random_bytes(24));
    }

    /** Human-friendly short pairing code (no ambiguous characters). */
    public static function newPairingCode(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    // ---------------------------------------------------------------- cards

    /** Strip separators/Persian digits, keep digits. */
    public static function normalizeCardNumber(string $number): string
    {
        $number = Helpers::enDigits($number);
        return preg_replace('#\D#', '', $number) ?? '';
    }

    /** Luhn check (Iranian cards are 16 digits and Luhn-valid). */
    public static function luhnValid(string $number): bool
    {
        $n = self::normalizeCardNumber($number);
        if (strlen($n) < 12 || strlen($n) > 19 || !ctype_digit($n)) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            $d = (int) $n[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    /** `621986******1234` */
    public static function maskCard(string $number): string
    {
        $n = self::normalizeCardNumber($number);
        if (strlen($n) < 10) {
            return str_repeat('*', strlen($n));
        }
        return substr($n, 0, 6) . str_repeat('*', strlen($n) - 10) . substr($n, -4);
    }

    /** `6219 86** **** 1234` */
    public static function maskCardPretty(string $number): string
    {
        $m = self::maskCard($number);
        return implode(' ', str_split($m, 4));
    }

    /** Group a full card number: `6219 8612 3456 1234` */
    public static function formatCard(string $number): string
    {
        return implode(' ', str_split(self::normalizeCardNumber($number), 4));
    }

    public static function last4(string $number): string
    {
        $n = self::normalizeCardNumber($number);
        return $n === '' ? '' : substr($n, -4);
    }

    public static function maskIban(?string $iban): string
    {
        $iban = trim((string) $iban);
        if ($iban === '') {
            return '';
        }
        return mb_substr($iban, 0, 4) . str_repeat('•', 18) . mb_substr($iban, -4);
    }
}
