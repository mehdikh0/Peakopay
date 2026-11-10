<?php
/**
 * Peakopay — Normalized bank transactions uploaded by Android devices.
 *
 * States: received → matched → confirmed | unmatched | duplicate | rejected
 * A transaction is globally unique by raw SMS hash and can pay at most ONE
 * payment (unique index on payment_id).
 */
declare(strict_types=1);

final class Transaction
{
    /**
     * Insert a normalized transaction. Idempotent: if the same raw SMS hash was
     * already uploaded, the existing row is returned with duplicate=true.
     *
     * @return array{row:array, duplicate:bool}
     */
    public static function insert(array $tx): array
    {
        $raw    = (string) ($tx['raw_text'] ?? '');
        $hash   = (string) ($tx['raw_hash'] ?? self::hashRaw($tx['device_id'] ?? 0, $raw, (string) ($tx['occurred_at'] ?? ''), (int) ($tx['amount_rial'] ?? 0)));
        $exists = DB::one('SELECT * FROM transactions WHERE raw_hash = ?', [$hash]);
        if ($exists !== null) {
            return ['row' => $exists, 'duplicate' => true];
        }
        $id = DB::insert('transactions', [
            'store_id'    => (int) $tx['store_id'],
            'device_id'   => (int) $tx['device_id'],
            'card_id'     => !empty($tx['card_id']) ? (int) $tx['card_id'] : null,
            'bank'        => mb_substr((string) ($tx['bank'] ?? ''), 0, 60),
            'sender'      => mb_substr((string) ($tx['sender'] ?? ''), 0, 60),
            'amount_rial' => (int) $tx['amount_rial'],
            'currency'    => in_array($tx['currency'] ?? 'IRR', ['IRR', 'IRT'], true) ? ($tx['currency'] ?? 'IRR') : 'IRR',
            'fingerprint' => !empty($tx['fingerprint']) ? (string) $tx['fingerprint'] : null,
            'occurred_at' => (string) $tx['occurred_at'],
            'dest_last4'  => mb_substr((string) ($tx['dest_last4'] ?? ''), 0, 4),
            'reference'   => mb_substr((string) ($tx['reference'] ?? ''), 0, 80),
            'raw_hash'    => $hash,
            'raw_enc'     => Crypto::encrypt(mb_substr($raw, 0, 1600)),
            'status'      => 'received',
            'is_test'     => !empty($tx['is_test']) ? 1 : 0,
            'created_at'  => DB::now(),
        ]);
        return ['row' => self::find($id), 'duplicate' => false];
    }

    public static function hashRaw(int $deviceId, string $rawText, string $occurredAt, int $amountRial): string
    {
        return Crypto::sha256($deviceId . '|' . trim($rawText) . '|' . $occurredAt . '|' . $amountRial);
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT id, store_id, device_id, bank, sender, amount_rial, currency, occurred_at, dest_last4, reference, raw_hash, status, payment_id, is_test, created_at FROM transactions WHERE id = ?', [$id]);
    }

    public static function updateStatus(int $id, string $status, ?int $paymentId = null): void
    {
        if ($paymentId === null) {
            DB::update('transactions', ['status' => $status], 'id = ?', [$id]);
        } else {
            DB::update('transactions', ['status' => $status, 'payment_id' => $paymentId], 'id = ?', [$id]);
        }
    }

    /** Raw text for admin review (decrypted on demand, never logged). */
    public static function rawText(int $id): ?string
    {
        $row = DB::one('SELECT raw_enc FROM transactions WHERE id = ?', [$id]);
        return $row === null ? null : Crypto::decrypt((string) $row['raw_enc']);
    }

    /** @return array{rows:array, total:int} */
    public static function paginate(int $storeId, int $page = 1, int $perPage = 25, string $status = '', string $q = ''): array
    {
        $where  = ['t.store_id = ?'];
        $params = [$storeId];
        if ($status !== '') {
            $where[]  = 't.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[]  = '(t.reference LIKE ? OR t.bank LIKE ? OR t.sender LIKE ?)';
            for ($i = 0; $i < 3; $i++) {
                $params[] = "%{$q}%";
            }
        }
        $w     = implode(' AND ', $where);
        $total = (int) DB::val("SELECT COUNT(*) FROM transactions t WHERE {$w}", $params);
        $page  = max(1, $page);
        $rows  = DB::all(
            "SELECT t.*, p.order_id, p.token AS payment_token
             FROM transactions t LEFT JOIN payment_sessions p ON p.id = t.payment_id
             WHERE {$w} ORDER BY t.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public static function countByStatus(int $storeId): array
    {
        $out = ['received' => 0, 'matched' => 0, 'confirmed' => 0, 'duplicate' => 0, 'unmatched' => 0, 'rejected' => 0];
        foreach (DB::all('SELECT status, COUNT(*) c FROM transactions WHERE store_id = ? GROUP BY status', [$storeId]) as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    public static function todayCount(int $storeId): int
    {
        $local = new DateTimeImmutable('now', new DateTimeZone(Helpers::storeTimezone()));
        $from  = gmdate('Y-m-d H:i:s', $local->setTime(0, 0, 0)->getTimestamp());
        return (int) DB::val('SELECT COUNT(*) FROM transactions WHERE store_id = ? AND created_at >= ?', [$storeId, $from]);
    }

    public static function lastForStore(int $storeId): ?array
    {
        return DB::one('SELECT * FROM transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1', [$storeId]);
    }
}
