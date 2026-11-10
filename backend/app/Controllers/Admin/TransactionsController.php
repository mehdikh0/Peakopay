<?php
/**
 * Peakopay — Admin: transactions list + raw SMS review.
 */
declare(strict_types=1);

final class TransactionsController
{
    public static function index(): void
    {
        $user   = AdminGuard::require();
        $store  = Store::current();
        $sid    = (int) ($store['id'] ?? 0);
        $page   = max(1, (int) Http::get('page', 1));
        $status = (string) Http::get('status', '');
        $q      = trim((string) Http::get('q', ''));
        $res    = $sid > 0 ? Transaction::paginate($sid, $page, 20, $status, $q) : ['rows' => [], 'total' => 0];

        View::page('admin/transactions', [
            'title'     => 'تراکنش‌ها',
            'user'      => $user,
            'store'     => $store,
            'rows'      => $res['rows'],
            'total'     => $res['total'],
            'page'      => $page,
            'perPage'   => 20,
            'status'    => $status,
            'q'         => $q,
            'counts'    => $sid > 0 ? Transaction::countByStatus($sid) : [],
            'activeNav' => 'transactions',
        ]);
    }

    /** Raw SMS text (modal) — decrypted on demand, never logged. */
    public static function raw(array $args): void
    {
        AdminGuard::requireJson();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $txn   = Transaction::find((int) $args['id']);
        if ($txn === null || (int) $txn['store_id'] !== $sid) {
            Http::apiError('not_found', 'تراکنش یافت نشد.', 404);
        }
        $raw = Transaction::rawText((int) $txn['id']) ?? '';
        Http::apiSuccess([
            'id'   => (int) $txn['id'],
            'raw'  => $raw,
            'bank' => (string) $txn['bank'],
        ]);
    }
}
