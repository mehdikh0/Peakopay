<?php
/**
 * Peakopay — Payment sessions (WooCommerce order ⇄ card-to-card payment).
 *
 * States: pending → verifying → paid | expired | failed
 * All transitions are guarded, atomic UPDATEs so concurrent requests can never
 * double-complete a payment.
 */
declare(strict_types=1);

final class Payment
{
    // -------------------------------------------------------------- create

    /**
     * Create a payment session.
     *
     * @param array $card  destination card row (from Card::find)
     * @param int   $amountRial order amount in Rial (already converted by caller)
     */
    public static function create(
        int $storeId,
        array $card,
        string $orderId,
        string $orderKey,
        int $amountRial,
        string $currency,
        int $amountDisplay,
        array $opts = []
    ): array {
        // دور ۲۳ — همهٔ تنظیمات مبلغ/انقضا از «همان فروشگاه» خوانده شود
        return Helpers::withStore((int) $storeId, static function () use ($storeId, $card, $orderId, $orderKey, $amountRial, $currency, $amountDisplay, $opts) {
            return self::createScoped($storeId, $card, $orderId, $orderKey, $amountRial, $currency, $amountDisplay, $opts);
        });
    }

    private static function createScoped(
        int $storeId,
        ?array $card,
        string $orderId,
        string $orderKey,
        int $amountRial,
        string $currency,
        int $amountDisplay,
        array $opts = []
    ): array {
        $min = Setting::int('min_amount_rial', 20000);
        $max = Setting::int('max_amount_rial', 500000000);
        if ($amountRial < $min) {
            throw new DomainException("مبلغ پرداخت کمتر از حد مجاز است (" . Helpers::money($min) . ").");
        }
        if ($max > 0 && $amountRial > $max) {
            throw new DomainException("مبلغ پرداخت بیشتر از حد مجاز است (" . Helpers::money($max) . ").");
        }

        // Reuse an identical, still-open payment for the same order (idempotency).
        $existing = self::findReusable($storeId, $orderId, $amountRial, (int) ($opts['is_test'] ?? 0) === 1);
        if ($existing !== null) {
            return $existing;
        }

        // Unique-amount strategy: randomise the last 3 digits (100–999 Rial)
        // so simultaneous customers never transfer the exact same amount.
        $payAmount = $amountRial;
        if (Setting::bool('unique_amount', true) && $amountRial >= 10000) {
            for ($try = 0; $try < 20; $try++) {
                $candidate = $amountRial + random_int(100, 999);
                if ($max > 0 && $candidate > $max) {
                    continue;
                }
                $busy = DB::val(
                    "SELECT COUNT(*) FROM payment_sessions WHERE store_id = ? AND amount_rial = ? AND status IN ('pending','verifying')",
                    [$storeId, $candidate]
                );
                if ((int) $busy === 0) {
                    $payAmount = $candidate;
                    break;
                }
            }
        }

        $now     = DB::now();
        $expiry  = max(Setting::int('payment_expiry_minutes', 60), 5);
        $id = DB::insert('payment_sessions', [
            'store_id'      => $storeId,
            'card_id'       => $card['id'] ?? null,
            'token'         => Crypto::randomToken(40),
            'order_id'      => mb_substr($orderId, 0, 64),
            'order_key'     => mb_substr($orderKey, 0, 80),
            'amount_base_rial' => $amountRial,
            'amount_rial'   => $payAmount,
            'currency'      => mb_substr($currency, 0, 8),
            'amount_display'=> $amountDisplay,
            'status'        => 'pending',
            'customer_name' => mb_substr((string) ($opts['customer_name'] ?? ''), 0, 160),
            'customer_phone'=> mb_substr((string) ($opts['customer_phone'] ?? ''), 0, 40),
            'description'   => mb_substr((string) ($opts['description'] ?? ''), 0, 255),
            'redirect_url'  => mb_substr((string) ($opts['redirect_url'] ?? ''), 0, 500),
            'expected_reference' => mb_substr((string) ($opts['expected_reference'] ?? ''), 0, 80),
            'is_test'       => !empty($opts['is_test']) ? 1 : 0,
            'expires_at'    => gmdate('Y-m-d H:i:s', time() + $expiry * 60),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        return self::find($id);
    }

    /** An open payment for the same order+amount (within validity) can be reused. */
    public static function findReusable(int $storeId, string $orderId, int $amountRial, bool $isTest): ?array
    {
        $row = DB::one(
            "SELECT * FROM payment_sessions
             WHERE store_id = ? AND order_id = ? AND is_test = ? AND amount_base_rial = ?
               AND status IN ('pending','verifying') AND expires_at > ?
             ORDER BY id DESC LIMIT 1",
            [$storeId, $orderId, $isTest ? 1 : 0, $amountRial, DB::now()]
        );
        return $row;
    }

    // -------------------------------------------------------------- lookup

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM payment_sessions WHERE id = ?', [$id]);
    }

    public static function findByToken(string $token): ?array
    {
        return DB::one('SELECT * FROM payment_sessions WHERE token = ?', [substr(trim($token), 0, 64)]);
    }

    public static function findOpenByOrder(int $storeId, string $orderId): ?array
    {
        return DB::one(
            "SELECT * FROM payment_sessions
             WHERE store_id = ? AND order_id = ? AND status IN ('pending','verifying')
             ORDER BY id DESC LIMIT 1",
            [$storeId, $orderId]
        );
    }

    // ---------------------------------------------------------- transitions

    public static function markVerifying(int $id): bool
    {
        return DB::update(
            'payment_sessions',
            ['status' => 'verifying', 'updated_at' => DB::now()],
            "id = ? AND status = 'pending'",
            [$id]
        ) > 0;
    }

    /**
     * Atomically attach a transaction and mark the payment PAID.
     * Throws DomainException if the payment cannot be paid or the transaction
     * was already used — callers never need to double check.
     */
    public static function payWithTransaction(int $paymentId, int $transactionId, string $fromStatus = 'verifying'): void
    {
        DB::tx(static function () use ($paymentId, $transactionId, $fromStatus): void {
            $p = DB::one('SELECT * FROM payment_sessions WHERE id = ? FOR UPDATE', [$paymentId]);
            if ($p === null) {
                throw new DomainException('پرداخت یافت نشد.');
            }
            if (in_array($p['status'], ['paid'], true)) {
                throw new DomainException('این پرداخت قبلاً تکمیل شده است.');
            }
            if (!in_array($p['status'], [$fromStatus, 'pending', 'verifying'], true)) {
                throw new DomainException('وضعیت پرداخت اجازه تأیید نمی‌دهد (' . $p['status'] . ').');
            }
            // Claim the transaction for THIS payment only (never reuse for two orders).
            $claimed = DB::q(
                "UPDATE transactions SET payment_id = ?, status = 'confirmed' WHERE id = ? AND payment_id IS NULL AND status IN ('received','matched','unmatched')",
                [$paymentId, $transactionId]
            )->rowCount();
            if ($claimed === 0) {
                throw new DomainException('این تراکنش قبلاً برای پرداخت دیگری استفاده شده است.');
            }
            DB::update('payment_sessions', [
                'status'      => 'paid',
                'paid_at'     => DB::now(),
                'transaction_id' => $transactionId,
                'updated_at'  => DB::now(),
            ], 'id = ?', [$paymentId]);
        });
    }

    /** Lazy expiration: called whenever a payment row is read. Returns the fresh row. */
    public static function expireIfNeeded(array $row): array
    {
        if (in_array($row['status'], ['pending', 'verifying'], true) && strtotime((string) $row['expires_at']) <= time()) {
            DB::update('payment_sessions', [
                'status'     => 'expired',
                'updated_at' => DB::now(),
            ], "id = ? AND status IN ('pending','verifying')", [$row['id']]);
            $row['status'] = 'expired';
            if ((int) $row['is_test'] !== 1) {
                WooNotifier::notifyPaymentExpired($row); // best-effort, non-fatal
            }
            Audit::log('payment.expired', 'payment=' . $row['id'] . ' order=' . $row['order_id']);
        }
        return $row;
    }

    /**
     * Bulk lazy expiry for the maintenance cron: expire every stale pending
     * payment (same semantics as expireIfNeeded, including webhooks).
     * @return int number of payments expired
     */
    public static function expireStale(): int
    {
        $rows = DB::all("SELECT * FROM payment_sessions WHERE status IN ('pending','verifying') AND expires_at <= ?", [DB::now()]);
        $n = 0;
        foreach ($rows as $row) {
            $after = self::expireIfNeeded($row);
            if ($after['status'] === 'expired') {
                $n++;
            }
        }
        return $n;
    }

    public static function markFailed(int $id, string $reason = ''): void
    {
        DB::update('payment_sessions', [
            'status'     => 'failed',
            'updated_at' => DB::now(),
        ], "id = ? AND status IN ('pending','verifying')", [$id]);
        Audit::log('payment.failed', 'payment=' . $id . ' ' . $reason);
    }

    // -------------------------------------------------------------- queries

    /** @return array{rows:array, total:int} */
    public static function paginate(int $storeId, int $page = 1, int $perPage = 25, string $status = '', string $q = ''): array
    {
        $where  = ['p.store_id = ?'];
        $params = [$storeId];
        if ($status !== '') {
            $where[]  = 'p.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[]  = '(p.order_id LIKE ? OR p.token LIKE ? OR p.customer_name LIKE ?)';
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
        }
        $w     = implode(' AND ', $where);
        $total = (int) DB::val("SELECT COUNT(*) FROM payment_sessions p WHERE {$w}", $params);
        $page  = max(1, $page);
        $rows  = DB::all(
            "SELECT p.*, c.bank_name, c.number_last4, c.holder AS card_holder
             FROM payment_sessions p LEFT JOIN cards c ON c.id = p.card_id
             WHERE {$w} ORDER BY p.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public static function findForStore(int $storeId, int $id): ?array
    {
        return DB::one('SELECT * FROM payment_sessions WHERE store_id = ? AND id = ?', [$storeId, $id]);
    }

    /** Dashboard statistics for "today" in the store timezone. */
    public static function statsToday(int $storeId): array
    {
        $local = new DateTimeImmutable('now', new DateTimeZone(Helpers::storeTimezone()));
        $from  = gmdate('Y-m-d H:i:s', $local->setTime(0, 0, 0)->getTimestamp());
        $to    = gmdate('Y-m-d H:i:s', $local->getTimestamp());
        return [
            'paid_count'  => (int) DB::val("SELECT COUNT(*) FROM payment_sessions WHERE store_id = ? AND status = 'paid' AND is_test = 0 AND paid_at >= ? AND paid_at <= ?", [$storeId, $from, $to]),
            'paid_income' => (int) DB::val("SELECT COALESCE(SUM(amount_rial),0) FROM payment_sessions WHERE store_id = ? AND status = 'paid' AND is_test = 0 AND paid_at >= ? AND paid_at <= ?", [$storeId, $from, $to]),
            'pending'     => (int) DB::val("SELECT COUNT(*) FROM payment_sessions WHERE store_id = ? AND status IN ('pending','verifying')", [$storeId]),
            'expired'     => (int) DB::val("SELECT COUNT(*) FROM payment_sessions WHERE store_id = ? AND status = 'expired'", [$storeId]),
            'failed'      => (int) DB::val("SELECT COUNT(*) FROM payment_sessions WHERE store_id = ? AND status = 'failed'", [$storeId]),
        ];
    }

    public static function totalIncome(int $storeId): int
    {
        return (int) DB::val("SELECT COALESCE(SUM(amount_rial),0) FROM payment_sessions WHERE store_id = ? AND status = 'paid' AND is_test = 0", [$storeId]);
    }

    /** Daily income for the last N days (store timezone), keyed by Y-m-d. */
    public static function dailyIncome(int $storeId, int $days = 14): array
    {
        $rows = DB::all(
            "SELECT paid_at, amount_rial FROM payment_sessions
             WHERE store_id = ? AND status = 'paid' AND is_test = 0 AND paid_at IS NOT NULL AND paid_at >= ?",
            [$storeId, gmdate('Y-m-d 00:00:00', time() - $days * 86400)]
        );
        $tz  = new DateTimeZone(Helpers::storeTimezone());
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[gmdate('Y-m-d', time() - $i * 86400)] = 0;
        }
        foreach ($rows as $r) {
            $key = (new DateTimeImmutable($r['paid_at'] . ' UTC'))->setTimezone($tz)->format('Y-m-d');
            if (array_key_exists($key, $out)) {
                $out[$key] += (int) $r['amount_rial'];
            }
        }
        return $out;
    }
}
