<?php
/**
 * Peakopay — Store API keys (used by the WooCommerce plugin).
 *
 * Plaintext keys are shown exactly once (installer / regeneration); only a
 * SHA-256 hash is stored server-side.
 */
declare(strict_types=1);

final class ApiKey
{
    /** Issue a new key for a store. @return string plaintext key */
    public static function issue(int $storeId, string $label = 'WooCommerce'): string
    {
        $key = Crypto::newApiKey();
        DB::q('UPDATE api_keys SET revoked_at = ? WHERE store_id = ? AND revoked_at IS NULL', [DB::now(), $storeId]);
        DB::insert('api_keys', [
            'store_id'   => $storeId,
            'label'      => $label,
            'prefix'     => substr($key, 0, 12),
            'key_hash'   => Crypto::sha256($key),
            'created_at' => DB::now(),
        ]);
        return $key;
    }

    /**
     * Verify store credentials. @return array{store:array,key:array}|null
     */
    public static function verify(string $storeId, string $apiKey): ?array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '' || !str_starts_with($apiKey, 'hpk_')) {
            return null;
        }
        $store = DB::one('SELECT * FROM stores WHERE public_id = ?', [trim($storeId)]);
        if ($store === null) {
            return null;
        }
        $key = DB::one(
            'SELECT * FROM api_keys WHERE store_id = ? AND key_hash = ? AND revoked_at IS NULL',
            [(int) $store['id'], Crypto::sha256($apiKey)]
        );
        if ($key === null) {
            return null;
        }
        DB::update('api_keys', ['last_used_at' => DB::now()], 'id = ?', [(int) $key['id']]);
        return ['store' => $store, 'key' => $key];
    }

    /** Masked view of the active key (prefix + ••••) or null. */
    public static function maskedActive(int $storeId): ?string
    {
        $row = DB::one('SELECT prefix FROM api_keys WHERE store_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', [$storeId]);
        return $row === null ? null : $row['prefix'] . '••••••••••••••••';
    }

    public static function lastUsed(int $storeId): ?string
    {
        return DB::val('SELECT last_used_at FROM api_keys WHERE store_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', [$storeId]);
    }
}
