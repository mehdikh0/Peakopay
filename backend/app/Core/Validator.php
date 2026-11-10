<?php
/**
 * Peakopay — Input validation with Persian error messages.
 *
 * Usage:
 *   $v = Validator::make($_POST)
 *       ->rule('db_host', 'required|maxlen:190', 'هاست دیتابیس')
 *       ->rule('card_number', 'required|card', 'شماره کارت');
 *   if ($v->fails()) { ... $v->errors() ... }
 *   $clean = $v->values();
 *
 * Persian/Arabic digits are normalized to Latin before validation.
 */
declare(strict_types=1);

final class Validator
{
    private array $errors = [];
    private array $clean  = [];
    private array $data;

    public function __construct(array $data)
    {
        $norm = [];
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $v = trim(Helpers::enDigits($v));
            }
            $norm[(string) $k] = $v;
        }
        $this->data = $norm;
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function rule(string $field, string $rules, ?string $label = null): self
    {
        $label ??= $field;
        $value = $this->data[$field] ?? null;
        $isNullable = false;
        $required   = false;

        foreach (explode('|', $rules) as $rule) {
            $rule = trim($rule);
            if ($rule === '' ) {
                continue;
            }
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            if ($name === 'nullable') {
                $isNullable = true;
                continue;
            }
            if ($name === 'required') {
                $required = true;
            }
        }

        $isEmpty = $value === null || (is_string($value) && $value === '');
        if ($isEmpty) {
            if ($required) {
                $this->errors[$field] = "«{$label}» الزامی است.";
                $this->clean[$field]  = '';
            } elseif ($isNullable || !$required) {
                $this->clean[$field] = null;
            }
            if ($isEmpty) {
                return $this;
            }
        }

        $this->clean[$field] = $value;

        foreach (explode('|', $rules) as $rule) {
            $rule = trim($rule);
            if ($rule === '' || $rule === 'nullable' || $rule === 'required') {
                continue;
            }
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $msg = $this->check($name, $arg, $value, $label, $field);
            if ($msg !== null) {
                $this->errors[$field] ??= $msg;
            }
        }
        return $this;
    }

    private function check(string $name, ?string $arg, mixed $v, string $label, string $field): ?string
    {
        $s = (string) $v;
        switch ($name) {
            case 'int':
                return (is_int($v) || ctype_digit(ltrim($s, '-'))) ? null : "«{$label}» باید عدد باشد.";
            case 'numeric':
                return is_numeric($s) ? null : "«{$label}» باید عدد باشد.";
            case 'min':
                return (is_numeric($s) && (float) $s >= (float) $arg) ? null : "«{$label}» باید حداقل {$arg} باشد.";
            case 'max':
                return (is_numeric($s) && (float) $s <= (float) $arg) ? null : "«{$label}» حداکثر می‌تواند {$arg} باشد.";
            case 'minlen':
                return mb_strlen($s) >= (int) $arg ? null : "«{$label}» باید حداقل {$arg} کاراکتر باشد.";
            case 'maxlen':
                return mb_strlen($s) <= (int) $arg ? null : "«{$label}» حداکثر {$arg} کاراکتر می‌تواند باشد.";
            case 'email':
                return filter_var($s, FILTER_VALIDATE_EMAIL) ? null : "«{$label}» معتبر نیست.";
            case 'url':
                $u = filter_var($s, FILTER_VALIDATE_URL);
                if ($u === false) {
                    return "«{$label}» باید آدرس معتبر باشد.";
                }
                return in_array(parse_url($u, PHP_URL_SCHEME), ['http', 'https'], true) ? null : "«{$label}» باید http یا https باشد.";
            case 'mobile':
                return preg_match('#^09\d{9}$#', $s) ? null : "«{$label}» باید شماره موبایل معتبر (۰۹xxxxxxxxx) باشد.";
            case 'card':
                return (strlen(Crypto::normalizeCardNumber($s)) === 16 && Crypto::luhnValid($s))
                    ? null
                    : "«{$label}» معتبر نیست (شماره کارت باید ۱۶ رقم و معتبر باشد).";
            case 'iban':
                return self::ibanValid($s) ? null : "«{$label}» معتبر نیست (شماره شبا ۲۴ رقم با IR است).";
            case 'in':
                $opts = explode(';', (string) $arg);
                return in_array($s, $opts, true) ? null : "«{$label}» یکی از این گزینه‌ها باید باشد: " . implode('، ', $opts);
            case 'bool':
                return in_array($s, ['0', '1', 'true', 'false', 'on', ''], true) ? null : "«{$label}» باید بله/خیر باشد.";
            case 'regex':
                return preg_match((string) $arg, $s) === 1 ? null : "«{$label}» قالب معتبر ندارد.";
            case 'digits':
                return preg_match('#^\d{1,' . (int) $arg . '}$#', $s) ? null : "«{$label}» باید حداکثر {$arg} رقم باشد.";
        }
        return null;
    }

    /** Generic IBAN mod-97 check with IR length validation. */
    public static function ibanValid(string $iban): bool
    {
        $iban = strtoupper(preg_replace('#[\s\-]#', '', Helpers::enDigits($iban)) ?? '');
        if (!preg_match('#^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$#', $iban)) {
            return false;
        }
        if (str_starts_with($iban, 'IR') && strlen($iban) !== 26) {
            return false;
        }
        $moved = substr($iban, 4) . substr($iban, 0, 4);
        $num   = '';
        foreach (str_split($moved) as $ch) {
            $num .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        // mod 97 via bcmath-free arithmetic
        $rem = 0;
        foreach (str_split($num) as $d) {
            $rem = ($rem * 10 + (int) $d) % 97;
        }
        return $rem === 1;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> field => first error */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : reset($this->errors);
    }

    public function values(): array
    {
        return $this->clean;
    }

    public function value(string $field, mixed $default = null): mixed
    {
        return $this->clean[$field] ?? $default;
    }
}
