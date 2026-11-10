<?php
/**
 * Peakopay — Customer-facing payment page.
 *
 *   GET  /pay/{token}          → HTML page (no session, no login)
 *   GET  /pay/{token}/status   → JSON status (polled by the page)
 *   POST /pay/{token}/claim    → "I have paid" (informational only — NEVER proof)
 */
declare(strict_types=1);

final class PayController
{
    public static function show(array $args): void
    {
        $payment = Payment::findByToken((string) $args['token']);
        if ($payment === null) {
            Http::notFound();
        }
        $payment = Payment::expireIfNeeded($payment);
        $store   = Store::get((int) $payment['store_id']);
        $card    = $payment['card_id'] !== null ? Card::find((int) $payment['card_id']) : null;

        $data = self::viewData($payment, $store, $card);
        View::page('pay/page', ['data' => $data], 'layout_pay');
    }

    public static function status(array $args): void
    {
        $token   = (string) $args['token'];
        $payment = Payment::findByToken($token);
        if ($payment === null) {
            Http::apiError('not_found', 'پرداخت یافت نشد.', 404);
        }
        if (!RateLimiter::allow('status:' . $token, 90, 60)) {
            Http::apiError('rate_limited', 'درخواست‌های زیادی ارسال شده است.', 429);
        }
        $payment = Payment::expireIfNeeded($payment);
        $store   = Store::get((int) $payment['store_id']);
        $card    = $payment['card_id'] !== null ? Card::find((int) $payment['card_id']) : null;
        Http::apiSuccess(['payment' => self::viewData($payment, $store, $card, minimal: true)]);
    }

    /** Customer pressed "I have paid" — marks VERIFYING. Never proves payment. */
    public static function claim(array $args): void
    {
        $token   = (string) $args['token'];
        $payment = Payment::findByToken($token);
        if ($payment === null) {
            Http::apiError('not_found', 'پرداخت یافت نشد.', 404);
        }
        if (!RateLimiter::allow('claim:' . $token, 10, 600)) {
            Http::apiError('rate_limited', 'درخواست‌های تکراری زیاد است؛ چند دقیقه دیگر تلاش کنید.', 429);
        }
        $payment = Payment::expireIfNeeded($payment);
        if ($payment['status'] === 'pending') {
            Payment::markVerifying((int) $payment['id']);
            $payment = Payment::find((int) $payment['id']);
            if ((int) $payment['is_test'] !== 1) {
                WooNotifier::notifyPaymentClaim($payment); // adds a Woo order note
            }
            Log::info('payment claimed by customer', ['payment' => (int) $payment['id']]);
        }
        $store = Store::get((int) $payment['store_id']);
        $card  = $payment['card_id'] !== null ? Card::find((int) $payment['card_id']) : null;
        Http::apiSuccess(['payment' => self::viewData($payment, $store, $card, minimal: true)]);
    }

    // ---------------------------------------------------------------- data

    private static function viewData(array $p, ?array $store, ?array $card, bool $minimal = false): array
    {
        $txn = $p['transaction_id'] !== null ? Transaction::find((int) $p['transaction_id']) : null;
        $data = [
            'token'            => (string) $p['token'],
            'status'           => (string) $p['status'],
            'order_id'         => (string) $p['order_id'],
            'amount_rial'      => (int) $p['amount_rial'],
            'amount_base_rial' => (int) $p['amount_base_rial'],
            'amount_toman'     => intdiv((int) $p['amount_rial'], 10),
            'currency'         => (string) $p['currency'],
            'amount_display'   => (int) $p['amount_display'],
            'adjusted'         => (int) $p['amount_rial'] !== (int) $p['amount_base_rial'],
            'expires_at'       => (string) $p['expires_at'],
            'expires_in'       => max(0, strtotime((string) $p['expires_at']) - time()),
            'paid_at'          => $p['paid_at'] ?? null,
            'test'             => (int) $p['is_test'] === 1,
            'customer_name'    => (string) $p['customer_name'],
            'description'      => (string) $p['description'],
        ];
        if ($minimal) {
            if ($txn !== null) {
                $data['transaction'] = ['reference' => (string) $txn['reference'], 'bank' => (string) $txn['bank']];
            }
            return $data;
        }
        $data['store'] = $store === null ? ['name' => 'پیکو پی'] : [
            'name'   => (string) $store['name'],
            'phone'  => (string) $store['support_phone'],
            'telegram' => (string) $store['support_telegram'],
        ];
        $data['card'] = $card === null ? null : [
            'bank'       => (string) $card['bank_name'],
            'holder'     => (string) $card['holder'],
            'number'     => (string) $card['number'],
            'number_html'=> Crypto::formatCard((string) $card['number']),
            'iban'       => (string) $card['iban'],
            'account'    => (string) $card['account'],
        ];
        $data['redirect_url'] = (string) $p['redirect_url'];
        $data['transaction']  = $txn === null ? null : ['reference' => (string) $txn['reference'], 'bank' => (string) $txn['bank']];
        return $data;
    }
}
