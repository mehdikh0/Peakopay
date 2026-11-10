<?php
/**
 * Peakopay — Destination cards. Card numbers are encrypted at rest; only the
 * last 4 digits are stored in the clear for matching and display.
 */
declare(strict_types=1);

final class Card
{
    public static function add(int $storeId, array $in): int
    {
        $number = Crypto::normalizeCardNumber((string) $in['number']);
        $id = DB::insert('cards', [
            'store_id'    => $storeId,
            'label'       => mb_substr((string) ($in['label'] ?? ''), 0, 120),
            'bank_name'   => $in['bank_name'],
            'holder'      => $in['holder'],
            'number_enc'  => Crypto::encrypt($number),
            'number_last4'=> Crypto::last4($number),
            'iban_enc'    => Crypto::encrypt($in['iban'] ?? ''),
            'account_enc' => Crypto::encrypt($in['account'] ?? ''),
            'is_active'   => 1,
            'is_default'  => 0,
            'created_at'  => DB::now(),
        ]);
        if ((int) DB::val('SELECT COUNT(*) FROM cards WHERE store_id = ?', [$storeId]) === 1) {
            DB::update('cards', ['is_default' => 1], 'id = ?', [$id]);
        }
        return $id;
    }

    public static function update(int $id, int $storeId, array $in): void
    {
        $data = [
            'label'     => mb_substr((string) ($in['label'] ?? ''), 0, 120),
            'bank_name' => $in['bank_name'],
            'holder'    => $in['holder'],
            'iban_enc'  => Crypto::encrypt($in['iban'] ?? ''),
            'account_enc' => Crypto::encrypt($in['account'] ?? ''),
        ];
        if (!empty($in['number'])) {
            $number = Crypto::normalizeCardNumber((string) $in['number']);
            $data['number_enc']   = Crypto::encrypt($number);
            $data['number_last4'] = Crypto::last4($number);
        }
        DB::update('cards', $data, 'id = ? AND store_id = ?', [$id, $storeId]);
    }

    /** Full card row with decrypted number (payment page / detail only). */
    public static function find(int $id): ?array
    {
        $row = DB::one('SELECT * FROM cards WHERE id = ?', [$id]);
        if ($row !== null) {
            $row['number']    = (string) (Crypto::decrypt((string) $row['number_enc']) ?? '');
            $row['iban']      = (string) (Crypto::decrypt((string) $row['iban_enc']) ?? '');
            $row['account']   = (string) (Crypto::decrypt((string) $row['account_enc']) ?? '');
            $row['mask']      = Crypto::maskCardPretty($row['number']);
        }
        return $row;
    }

    /** List for admin (masked, never decrypts card numbers). */
    public static function all(int $storeId): array
    {
        return array_map(static function (array $row): array {
            $row['mask']      = '•••• •••• •••• ' . $row['number_last4'];
            $row['has_iban']  = (string) $row['iban_enc'] !== '';
            return $row;
        }, DB::all('SELECT id, label, bank_name, holder, number_last4, iban_enc, account_enc, is_active, is_default, created_at FROM cards WHERE store_id = ? ORDER BY is_default DESC, id', [$storeId]));
    }

    public static function activeDefault(int $storeId): ?array
    {
        $id = DB::val('SELECT id FROM cards WHERE store_id = ? AND is_active = 1 ORDER BY is_default DESC, id LIMIT 1', [$storeId]);
        return $id === null ? null : self::find((int) $id);
    }

    public static function setActive(int $id, int $storeId, bool $active): void
    {
        DB::update('cards', ['is_active' => $active ? 1 : 0], 'id = ? AND store_id = ?', [$id, $storeId]);
    }

    public static function makeDefault(int $id, int $storeId): void
    {
        DB::update('cards', ['is_default' => 0], 'store_id = ?', [$storeId]);
        DB::update('cards', ['is_default' => 1, 'is_active' => 1], 'id = ? AND store_id = ?', [$id, $storeId]);
    }

    public static function remove(int $id, int $storeId): bool
    {
        $inUse = (int) DB::val("SELECT COUNT(*) FROM payment_sessions WHERE card_id = ? AND status IN ('pending','verifying')", [$id]);
        if ($inUse > 0) {
            return false;
        }
        DB::q('DELETE FROM cards WHERE id = ? AND store_id = ?', [$id, $storeId]);
        return true;
    }
}
