<?php
/**
 * Peakopay — Outgoing webhook events (backend → WooCommerce).
 */
declare(strict_types=1);

final class WebhookEvent
{
    public static function record(int $storeId, int $paymentId, string $event): int
    {
        return DB::insert('webhook_events', [
            'store_id'   => $storeId,
            'payment_id' => $paymentId,
            'event'      => $event,
            'status'     => 'pending',
            'created_at' => DB::now(),
        ]);
    }

    public static function markDelivered(int $id): void
    {
        DB::update('webhook_events', [
            'status'      => 'delivered',
            'delivered_at'=> DB::now(),
            'attempts'    => DB::val('SELECT attempts FROM webhook_events WHERE id = ?', [$id]) + 1,
        ], 'id = ?', [$id]);
    }

    public static function markFailed(int $id, string $error): void
    {
        DB::update('webhook_events', [
            'status'     => 'failed',
            'attempts'   => (int) DB::val('SELECT attempts FROM webhook_events WHERE id = ?', [$id]) + 1,
            'last_error' => mb_substr($error, 0, 480),
        ], 'id = ?', [$id]);
    }

    /** @return array<int, array<string,mixed>> */
    public static function recent(int $storeId, int $limit = 30): array
    {
        return DB::all(
            'SELECT w.*, p.order_id FROM webhook_events w LEFT JOIN payment_sessions p ON p.id = w.payment_id
             WHERE w.store_id = ? ORDER BY w.id DESC LIMIT ' . min(max($limit, 1), 200),
            [$storeId]
        );
    }
}
