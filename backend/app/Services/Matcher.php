<?php
/**
 * Peakopay — Transaction→Payment matching engine.
 *
 * The entire pipeline (insert → match → confirm → WooCommerce webhook) runs
 * synchronously inside the device request. NO cron, NO background workers.
 *
 * Matching criteria (all configurable in admin → Settings → Matching):
 *   - same store, test/real alignment
 *   - destination card (last 4 digits, when the SMS includes them)
 *   - amount: exact mode or tolerance (± N Rial)
 *   - time window: payment open & not expired, transaction occurred within window
 *   - optional expected reference
 *   - a transaction can never pay two payments (unique constraints + row locks)
 */
declare(strict_types=1);

final class Matcher
{
    private const TIME_SKEW_SECONDS = 900; // bank SMS clock tolerance ±15 min

    /**
     * @param array $tx normalized transaction (device input, already validated)
     * @param array $device device row
     * @param bool $isTest admin test-mode simulation
     *
     * @return array{result:'matched'|'matched_pending'|'unmatched'|'duplicate', transaction:array, ?payment:array}
     */
    public static function process(array $tx, array $device, bool $isTest = false, ?int $cardStoreId = null): array
    {
        // دور ۲۳ — چندفروشگاهی: فروشگاهِ تراکنش از کارتِ تطبیق‌یافته می‌آید
        // (اگر دستگاه حساب‌محور باشد ممکن است چند فروشگاه داشته باشد)؛ legacy:
        // دستگاهِ همراه‌سازی‌شده با یک فروشگاه. همهٔ تنظیمات تطبیق (auto_confirm،
        // match_by_card و…) با withStore از «همان فروشگاه» خوانده می‌شوند.
        $tx['store_id'] = $cardStoreId ?? (int) $device['store_id'];
        $tx['device_id'] = (int) $device['id'];
        $tx['is_test'] = $isTest;
        return Helpers::withStore((int) $tx['store_id'], static function () use ($tx, $device) {
            return self::processScoped($tx, $device);
        });
    }

    private static function processScoped(array $tx, array $device): array
    {

        $ins = Transaction::insert($tx);
        $txn = $ins['row'];

        if ($ins['duplicate']) {
            // Same SMS uploaded twice — never re-match, just inform the device.
            return ['result' => 'duplicate', 'transaction' => $txn, 'payment' => null];
        }

        $candidates = self::candidates($tx);
        $lastError  = '';

        foreach ($candidates as $payment) {
            if (!self::filtersPass($tx, $payment)) {
                continue;
            }
            if (Setting::bool('auto_confirm', true)) {
                try {
                    Payment::payWithTransaction((int) $payment['id'], (int) $txn['id']);
                } catch (DomainException $e) {
                    $lastError = $e->getMessage();
                    continue; // raced or state changed — try the next candidate
                }
                $fresh = Payment::find((int) $payment['id']);
                WooNotifier::notifyPaymentPaid($fresh);      // synchronous, best-effort
                Notifier::paymentPaid($fresh);               // optional email/telegram
                Audit::log('payment.matched', 'payment=' . $payment['id'] . ' txn=' . $txn['id'] . ' amount=' . $tx['amount_rial']);
                return ['result' => 'matched', 'transaction' => Transaction::find((int) $txn['id']), 'payment' => $fresh];
            }

            // Manual confirmation mode: park both sides for the admin.
            Payment::markVerifying((int) $payment['id']);
            Transaction::updateStatus((int) $txn['id'], 'matched', (int) $payment['id']);
            DB::update('payment_sessions', ['transaction_id' => $txn['id']], 'id = ?', [$payment['id']]);
            Audit::log('payment.awaiting_confirm', 'payment=' . $payment['id'] . ' txn=' . $txn['id']);
            return ['result' => 'matched_pending', 'transaction' => Transaction::find((int) $txn['id']), 'payment' => Payment::find((int) $payment['id'])];
        }

        Transaction::updateStatus((int) $txn['id'], 'unmatched');
        Notifier::transactionUnmatched($txn);
        // چرا تطبیق نخورد؟ — برای ادمین و اپ، با زبان انسانی (نه فقط لاگ)
        $reason = self::unmatchedReason($tx);
        Log::info('transaction unmatched', ['txn' => $txn['id'], 'amount' => $tx['amount_rial'], 'reason' => $reason]);
        return ['result' => 'unmatched', 'transaction' => Transaction::find((int) $txn['id']), 'payment' => null, 'reason' => $reason];
    }

    /**
     * تأیید دستی پرداخت — «در هر حالتی» (درخواست صریح فروشنده):
     *  - verifying با تراکنش: جریان کامل (پرداخت + تراکنش تأیید می‌شوند)؛
     *  - pending بدون تراکنش: تأیید مستقیم — برای وقتی که واریز انجام شده ولی
     *    پیامک نرسیده/پارس نشده و فروشنده خودش رسیدگی می‌کند. حتی وقتی تأیید
     *    خودکار روشن است، فروشنده می‌تواند دستی تأیید کند.
     */
    public static function confirmManually(int $paymentId, int $storeId): bool
    {
        $payment = Payment::findForStore($storeId, $paymentId);
        if ($payment === null || !in_array($payment['status'], ['pending', 'verifying'], true)) {
            return false;
        }
        $hasTxn = $payment['transaction_id'] !== null;
        try {
            if ($hasTxn) {
                DB::tx(static function () use ($payment): void {
                    DB::q("UPDATE payment_sessions SET status = 'paid', paid_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending','verifying')", [DB::now(), DB::now(), $payment['id']]);
                    DB::q("UPDATE transactions SET status = 'confirmed' WHERE id = ?", [$payment['transaction_id']]);
                });
            } else {
                DB::q("UPDATE payment_sessions SET status = 'paid', paid_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending','verifying')", [DB::now(), DB::now(), $payment['id']]);
            }
        } catch (Throwable $e) {
            Log::error('manual confirm failed: ' . $e->getMessage());
            return false;
        }
        $fresh = Payment::find($paymentId);
        WooNotifier::notifyPaymentPaid($fresh);
        Notifier::paymentPaid($fresh);
        Audit::log('payment.confirmed_manually', 'payment=' . $paymentId . ($hasTxn ? '' : ' no-txn'));
        return true;
    }

    /** Admin attaches an unmatched transaction to an open payment. */
    public static function attachManually(int $transactionId, int $paymentId, int $storeId): bool    {
        $txn     = Transaction::find($transactionId);
        $payment = Payment::findForStore($storeId, $paymentId);
        if ($txn === null || $payment === null || (int) $txn['store_id'] !== $storeId) {
            return false;
        }
        if (!in_array($txn['status'], ['received', 'unmatched', 'matched'], true) || $txn['payment_id'] !== null) {
            return false;
        }
        if (!in_array($payment['status'], ['pending', 'verifying'], true)) {
            return false;
        }
        try {
            Payment::payWithTransaction($paymentId, $transactionId, 'pending');
        } catch (DomainException $e) {
            Session::flash('error', $e->getMessage());
            return false;
        }
        $fresh = Payment::find($paymentId);
        WooNotifier::notifyPaymentPaid($fresh);
        Notifier::paymentPaid($fresh);
        Audit::log('payment.attached_manually', 'payment=' . $paymentId . ' txn=' . $transactionId);
        return true;
    }

    /**
     * دور ۲۵ — حذف تراکنش بدون تطبیق از فهرست بررسی‌ها (× کنار کارت در اپ):
     * وضعیت به «dismissed» می‌رود؛ از شمارنده‌ها/فهرست‌های «نیاز به بررسی»
     * بیرون می‌آید ولی ردیف برای حسابرسی حذف فیزیکی نمی‌شود.
     */
    public static function dismissTransaction(int $transactionId, int $storeId): bool
    {
        $txn = Transaction::find($transactionId);
        if ($txn === null || (int) $txn['store_id'] !== $storeId || $txn['payment_id'] !== null) {
            return false;
        }
        if (!in_array($txn['status'], ['unmatched', 'received'], true)) {
            return false;
        }
        Transaction::updateStatus($transactionId, 'dismissed');
        Audit::log('transaction.dismissed_by_device', 'txn=' . $transactionId . ' store=' . $storeId);
        return true;
    }

    /**
     * دور ۲۵ — بستن/غیرفعال‌کردن پرداخت باز توسط فروشنده از اپ:
     * پرداخت «failed» می‌شود، رویداد cancelled به ووکامرس می‌رود (سفارش لغو
     * می‌شود) و اگر تراکنشی در حال بررسی به آن چسبیده باشد آزاد می‌گردد.
     * @return array{ok:bool,woo:bool}
     */
    public static function closePayment(int $paymentId, int $storeId): array
    {
        $payment = Payment::findForStore($storeId, $paymentId);
        if ($payment === null || !in_array($payment['status'], ['pending', 'verifying'], true)) {
            return ['ok' => false, 'woo' => false];
        }
        try {
            DB::tx(static function () use ($payment): void {
                DB::q(
                    "UPDATE payment_sessions SET status = 'failed', updated_at = ? WHERE id = ? AND status IN ('pending','verifying')",
                    [DB::now(), $payment['id']]
                );
                // تراکنشِ وصل‌شده در حال بررسی آزاد می‌شود تا دوباره قابل اتصال باشد
                if ($payment['transaction_id'] !== null) {
                    DB::q(
                        "UPDATE transactions SET status = 'unmatched', payment_id = NULL WHERE id = ? AND status IN ('matched','received')",
                        [$payment['transaction_id']]
                    );
                }
            });
        } catch (Throwable $e) {
            Log::error('close payment failed: ' . $e->getMessage());
            return ['ok' => false, 'woo' => false];
        }
        $fresh = Payment::find($paymentId);
        $woo = false;
        if ((int) $fresh['is_test'] !== 1) {
            $woo = WooNotifier::notifyPaymentCancelled($fresh); // سفارش در ووکامرس لغو می‌شود
        }
        Audit::log('payment.closed_by_device', 'payment=' . $paymentId . ' store=' . $storeId . ($payment['transaction_id'] !== null ? ' txn_released=' . $payment['transaction_id'] : ''));
        return ['ok' => true, 'woo' => $woo];
    }

    // ------------------------------------------------------------ internals

    /**
     * چرا این تراکنش با هیچ پرداختی تطبیق نخورد؟ — دلیل فارسیِ قابل نمایش.
     * فقط برای ردیف‌های unmatched (ادمین/اپ) صدا زده می‌شود؛ best-effort.
     */
    public static function unmatchedReason(array $tx): string
    {
        $storeId = (int) ($tx['store_id'] ?? 0);
        $open = DB::all(
            "SELECT p.*, c.number_last4 AS card_last4
             FROM payment_sessions p JOIN cards c ON c.id = p.card_id
             WHERE p.store_id = ? AND p.status IN ('pending','verifying')
             ORDER BY p.created_at DESC LIMIT 20",
            [$storeId]
        );
        if ($open === []) {
            return 'هیچ پرداختی در انتظار نیست — تراکنش رسید ولی پرداخت بازی برای تطبیق وجود ندارد.';
        }
        $amount = (int) ($tx['amount_rial'] ?? 0);
        $sameAmount = array_values(array_filter($open, static fn (array $p): bool => (int) $p['amount_rial'] === $amount));
        if ($sameAmount === []) {
            $closest = $open[0];
            foreach ($open as $p) {
                if (abs((int) $p['amount_rial'] - $amount) < abs((int) $closest['amount_rial'] - $amount)) {
                    $closest = $p;
                }
            }
            return 'مبلغ پیامک با هیچ پرداخت بازی برابر نیست (نزدیک‌ترین پرداخت باز: ' . Helpers::money((int) $closest['amount_rial']) . ' ریال).';
        }
        $byCard = Setting::bool('match_by_card', true);
        $last4  = (string) ($tx['dest_last4'] ?? '');
        $txTest = (int) ($tx['is_test'] ?? 0) === 1;
        foreach ($sameAmount as $p) {
            if (strtotime((string) $p['expires_at']) <= time()) {
                return 'پرداخت هم‌مبلغ پیدا شد ولی مهلت آن تمام شده است (منقضی).';
            }
            $pTest = (int) $p['is_test'] === 1;
            if ($txTest && !$pTest) {
                return 'تراکنش تستی، پرداخت واقعی را نمی‌پردازد (ایمنی حالت تست).';
            }
            if (!$txTest && $pTest && !Setting::bool('test_mode', false)) {
                return 'پرداخت هم‌مبلغ «تستی» است اما حالت تست فروشگاه خاموش است — تراکنش واقعی، پرداخت تستی را نمی‌پردازد.';
            }
            if ($byCard && $last4 !== '' && (string) $p['card_last4'] !== $last4) {
                return 'پرداخت هم‌مبلغ روی کارت دیگری است (کارت پیامک: ' . $last4 . '، کارت پرداخت: ' . $p['card_last4'] . '). فرستنده این پیامک را در پنل روی همان کارتی ثبت کنید که پرداخت‌ها برایش ساخته می‌شود.';
            }
            $occurred = strtotime((string) ($tx['occurred_at'] ?? ''));
            if ($occurred !== false && $occurred < strtotime((string) $p['created_at']) - self::TIME_SKEW_SECONDS) {
                return 'پرداخت هم‌مبلغ بعد از زمان پیامک ساخته شده (اختلاف ساعت یا پنجره تطبیق).';
            }
            if (Setting::bool('match_by_reference', false)) {
                $expected = trim((string) $p['expected_reference']);
                if ($expected !== '' && strcasecmp($expected, (string) ($tx['reference'] ?? '')) !== 0) {
                    return 'پرداخت هم‌مبلغ «مرجع انتظاری» متفاوتی دارد (تطبیق مرجع فعال است).';
                }
            }
        }
        return 'پرداخت هم‌مبلغ موجود است ولی فیلترهای تطبیق اجازه ندادند (پنجره زمانی/پیکربندی).';
    }

    /** Ordered candidate payments (best first). */
    private static function candidates(array $tx): array
    {
        $storeId   = (int) $tx['store_id'];
        $tolerance = Setting::get('match_mode') === 'exact' ? 0 : max(Setting::int('payment_tolerance_rial', 2000), 0);
        $windowMin = max(Setting::int('match_window_minutes', 1440), 30);
        $params    = [$storeId];
        // در «حالت تست» کل خط لوله سندباکس است: تراکنش دستگاه باید بتواند
        // پرداخت‌های تستی را هم بپردازد (آزمون سرتاسری با پیامک واقعی ممکن شود).
        // در حالت واقعی، تطبیق تست/واقعی دقیق و سخت‌گیرانه می‌ماند.
        $txTest = (int) $tx['is_test'] === 1;
        if (!$txTest && Setting::bool('test_mode', false)) {
            // تراکنش واقعیِ دستگاه در حالت تست می‌تواند پرداخت تستی را هم بپردازد
            // (آزمون سرتاسری). برعکسش ممنوع: تراکنش تستی هرگز پرداخت واقعی را
            // نمی‌خورد — حتی در حالت تست.
            $testSql = 'p.is_test IN (0, 1)';
        } else {
            $testSql = 'p.is_test = ?';
            $params[] = $txTest ? 1 : 0;
        }
        $params[] = DB::now();
        $params[] = gmdate('Y-m-d H:i:s', time() - $windowMin * 60);
        $params[] = (int) $tx['amount_rial'];
        $amountSql = $tolerance > 0
            ? 'AND (p.amount_rial = ? OR ABS(p.amount_rial - ?) <= ' . $tolerance . ')'
            : 'AND p.amount_rial = ?';
        if ($tolerance > 0) {
            $params[] = (int) $tx['amount_rial'];
        }

        $byCard = Setting::bool('match_by_card', true) && (string) ($tx['dest_last4'] ?? '') !== '';
        if ($byCard) {
            $amountSql .= ' AND c.number_last4 = ?';
            $params[]   = (string) $tx['dest_last4'];
        }

        return DB::all(
            "SELECT p.*, c.number_last4 AS card_last4
             FROM payment_sessions p
             JOIN cards c ON c.id = p.card_id
             WHERE p.store_id = ?
               AND {$testSql}
               AND p.status IN ('pending','verifying')
               AND p.expires_at > ?
               AND p.created_at >= ?
               {$amountSql}
             ORDER BY (p.amount_rial = ?) DESC, ABS(p.amount_rial - ?) ASC, p.created_at ASC
             LIMIT 10",
            array_merge($params, [(int) $tx['amount_rial'], (int) $tx['amount_rial']])
        );
    }

    /** Extra checks that are easier in PHP than SQL. */
    private static function filtersPass(array $tx, array $payment): bool
    {
        $occurred = strtotime((string) $tx['occurred_at']);
        $created  = strtotime((string) $payment['created_at']);
        if ($occurred === false || $created === false) {
            return false;
        }
        // Transaction must not pre-date the payment (allow clock skew).
        if ($occurred < $created - self::TIME_SKEW_SECONDS) {
            return false;
        }
        // Optional reference matching.
        if (Setting::bool('match_by_reference', false)) {
            $expected = trim((string) $payment['expected_reference']);
            if ($expected !== '' && strcasecmp($expected, (string) ($tx['reference'] ?? '')) !== 0) {
                return false;
            }
        }
        return true;
    }
}
