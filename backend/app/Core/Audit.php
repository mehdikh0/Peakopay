<?php
/**
 * Peakopay — Audit trail.
 *
 * Records security-relevant admin actions. Callers must never pass secrets —
 * the sanitizer below also masks anything that looks like a card number or a
 * long token, just in case.
 */
declare(strict_types=1);

final class Audit
{
    public static function log(string $action, string $detail = '', array $ctx = []): void
    {
        try {
            $detail = mb_substr(Log::redact($detail), 0, 480);
            DB::insert('audit_logs', [
                'user_id'    => Session::isLoggedIn() ? Session::userId() : null,
                'store_id'   => $ctx['store_id'] ?? Helpers::currentStoreId(),
                'action'     => mb_substr($action, 0, 60),
                'detail'     => $detail,
                'ip'         => Http::ip(),
                'request_id' => HP_REQUEST_ID,
                'created_at' => DB::now(),
            ]);
        } catch (Throwable $e) {
            Log::warn('audit write failed: ' . $e->getMessage());
        }
    }

    /** @return array<int, array<string,mixed>> */
    public static function recent(int $limit = 100, ?string $action = null): array
    {
        $sql    = 'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id';
        $params = [];
        if ($action !== null && $action !== '') {
            $sql      .= ' WHERE a.action LIKE ?';
            $params[] = '%' . $action . '%';
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . min(max($limit, 1), 500);
        return DB::all($sql, $params);
    }
}
