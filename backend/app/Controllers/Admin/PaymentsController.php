<?php
/**
 * Peakopay — Admin: payments list, detail, manual confirm / webhook resend.
 */
declare(strict_types=1);

final class PaymentsController
{
    public static function index(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $page  = max(1, (int) Http::get('page', 1));
        $status = (string) Http::get('status', '');
        $q     = trim((string) Http::get('q', ''));
        $res   = $sid > 0 ? Payment::paginate($sid, $page, 20, $status, $q) : ['rows' => [], 'total' => 0];

        View::page('admin/payments', [
            'title'     => 'پرداخت‌ها',
            'user'      => $user,
            'store'     => $store,
            'rows'      => $res['rows'],
            'total'     => $res['total'],
            'page'      => $page,
            'perPage'   => 20,
            'status'    => $status,
            'q'         => $q,
            'activeNav' => 'payments',
        ]);
    }

    public static function detail(array $args): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $payment = Payment::findForStore($sid, (int) $args['id']);
        if ($payment === null) {
            Http::notFound();
        }
        $payment = Payment::expireIfNeeded($payment);
        $card    = $payment['card_id'] !== null ? Card::find((int) $payment['card_id']) : null;
        $txn     = $payment['transaction_id'] !== null ? Transaction::find((int) $payment['transaction_id']) : null;
        $hooks   = WebhookEvent::recent($sid, 200);
        $hooks   = array_values(array_filter($hooks, static fn ($h) => (int) $h['payment_id'] === (int) $payment['id']));

        // candidates for manual attach (unmatched transactions around the same amount)
        $candidates = [];
        if (in_array($payment['status'], ['pending', 'verifying'], true)) {
            $lo = (int) $payment['amount_rial'] - max(Setting::int('payment_tolerance_rial', 2000), 0);
            $hi = (int) $payment['amount_rial'] + max(Setting::int('payment_tolerance_rial', 2000), 0);
            $candidates = DB::all(
                "SELECT * FROM transactions WHERE store_id = ? AND status IN ('received','unmatched') AND payment_id IS NULL AND amount_rial BETWEEN ? AND ? ORDER BY id DESC LIMIT 20",
                [$sid, $lo, $hi]
            );
        }

        View::page('admin/payment_detail', [
            'title'     => 'جزئیات پرداخت #' . $payment['id'],
            'user'      => $user,
            'store'     => $store,
            'payment'   => $payment,
            'card'      => $card,
            'txn'       => $txn,
            'hooks'     => $hooks,
            'candidates'=> $candidates,
            'activeNav' => 'payments',
        ]);
    }

    /** POST actions: confirm / resend webhook / attach transaction / expire */
    public static function action(): void
    {
        $user  = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'payments_confirm', 'تغییر وضعیت پرداخت');
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $do    = (string) Http::post('do', '');
        $id    = (int) Http::post('payment_id', 0);
        $payment = Payment::findForStore($sid, $id);
        if ($payment === null) {
            Session::flash('error', 'پرداخت یافت نشد.');
            Http::redirect($_SERVER['HTTP_REFERER'] ?? Config::url('admin/payments'));
        }

        switch ($do) {
            case 'confirm':
                if (Matcher::confirmManually($id, $sid)) {
                    Session::flash('success', 'پرداخت تأیید و به ووکامرس اطلاع داده شد.');
                } else {
                    Session::flash('error', 'تأیید ممکن نیست؛ وضعیت پرداخت یا تراکنش مناسب نیست.');
                }
                break;
            case 'resend':
                $ok = WooNotifier::resend($id);
                Session::flash($ok ? 'success' : 'error', $ok ? 'رویداد با موفقیت به ووکامرس ارسال شد.' : 'ارسال ناموفق بود؛ آدرس ووکامرس و تنظیمات را بررسی کنید.');
                break;
            case 'attach':
                $txnId = (int) Http::post('transaction_id', 0);
                if (Matcher::attachManually($txnId, $id, $sid)) {
                    Session::flash('success', 'تراکنش به پرداخت متصل و سفارش تأیید شد.');
                } else {
                    Session::flash('error', 'اتصال تراکنش ممکن نیست (تراکنش استفاده شده یا وضعیت پرداخت مناسب نیست).');
                }
                break;
            case 'expire':
                if (in_array($payment['status'], ['pending', 'verifying'], true)) {
                    Payment::expireIfNeeded(array_merge($payment, ['expires_at' => DB::now()]));
                    DB::q("UPDATE payment_sessions SET expires_at = ? WHERE id = ?", [DB::now(), $id]);
                    Session::flash('success', 'پرداخت منقضی و به ووکامرس اطلاع داده شد.');
                } else {
                    Session::flash('error', 'این پرداخت در وضعیت قابل انقضا نیست.');
                }
                break;
            default:
                Session::flash('error', 'عملیات نامعتبر.');
        }
        Http::redirect($_SERVER['HTTP_REFERER'] ?? Config::url('admin/payments'));
    }
}
