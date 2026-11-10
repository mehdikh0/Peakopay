<?php
/** Peakopay — per-card SMS senders & parser rules (persistence). */
declare(strict_types=1);

final class CardSms
{
    // ------------------------------------------------------------ senders

    /** @return array<int,array{id:int,card_id:int,sender_raw:string,sender_norm:string}> */
    public static function senders(int $cardId): array
    {
        return DB::all('SELECT id, card_id, sender_raw, sender_norm FROM card_senders WHERE card_id = ? ORDER BY id', [$cardId]);
    }

    /** Replace the sender list of a card (normalized, de-duplicated, exact-match ready). */
    public static function setSenders(int $cardId, array $rawList): void
    {
        DB::q('DELETE FROM card_senders WHERE card_id = ?', [$cardId]);
        $seen = [];
        foreach ($rawList as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $norm = CardSmsEngine::normalizeSender($raw);
            if ($norm === '' || isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            DB::insert('card_senders', [
                'card_id' => $cardId, 'sender_raw' => mb_substr($raw, 0, 40), 'sender_norm' => $norm, 'created_at' => DB::now(),
            ]);
        }
    }

    /** Find the enabled card owning this normalized sender (exact match). */
    public static function cardBySender(string $sender, int $storeId): ?array
    {
        $norm = CardSmsEngine::normalizeSender($sender);
        if ($norm === '') {
            return null;
        }
        $row = DB::one(
            'SELECT c.* FROM cards c JOIN card_senders s ON s.card_id = c.id
             WHERE s.sender_norm = ? AND c.store_id = ? AND c.is_active = 1 LIMIT 1',
            [$norm, $storeId],
        );
        return $row;
    }

    // ------------------------------------------------------------ rules

    /**
     * قواعد یک کارت؛ «rule» همیشه آرایه‌ی decode شده است (runRules همین را می‌خواهد)
     * و «rule_json» رشته خام برای ویرایش/نمایش باقی می‌ماند.
     *
     * @return array<int,array{id:int,name:string,priority:int,enabled:int,rule:array,rule_json:string,sample_text:?string,tested:int,last_matched_at:?string}>
     */
    public static function rules(int $cardId): array
    {
        $rows = DB::all('SELECT * FROM card_parser_rules WHERE card_id = ? ORDER BY priority, id', [$cardId]);
        foreach ($rows as &$r) {
            $r['rule'] = is_array($r['rule'] ?? null) ? $r['rule'] : (json_decode((string) ($r['rule_json'] ?? ''), true) ?: []);
        }
        return $rows;
    }

    public static function rule(int $id, int $storeId): ?array
    {
        $r = DB::one(
            'SELECT r.* FROM card_parser_rules r JOIN cards c ON c.id = r.card_id WHERE r.id = ? AND c.store_id = ?',
            [$id, $storeId],
        );
        return $r ?: null;
    }

    public static function addRule(int $cardId, string $name, int $priority, array $rule, string $sample): int
    {
        $id = DB::insert('card_parser_rules', [
            'card_id' => $cardId, 'name' => mb_substr($name, 0, 120), 'priority' => max(1, $priority),
            'enabled' => 1, 'rule_json' => json_encode($rule, JSON_UNESCAPED_UNICODE), 'sample_text' => $sample,
            'tested' => 0, 'created_at' => DB::now(), 'updated_at' => DB::now(),
        ]);
        self::bumpVersion(); // نکته: بعد از این، LAST_INSERT_ID معتبر نیست — id همان‌جا گرفته شد
        return $id;
    }

    public static function updateRule(int $id, string $name, int $priority, array $rule, string $sample): void
    {
        DB::q('UPDATE card_parser_rules SET name = ?, priority = ?, rule_json = ?, sample_text = ?, tested = 0, updated_at = ? WHERE id = ?', [
            mb_substr($name, 0, 120), max(1, $priority), json_encode($rule, JSON_UNESCAPED_UNICODE), $sample, DB::now(), $id,
        ]);
        self::bumpVersion();
    }

    public static function deleteRule(int $id): void
    {
        DB::q('DELETE FROM card_parser_rules WHERE id = ?', [$id]);
        self::bumpVersion();
    }

    public static function toggleRule(int $id): void
    {
        DB::q('UPDATE card_parser_rules SET enabled = 1 - enabled, updated_at = ? WHERE id = ?', [DB::now(), $id]);
        self::bumpVersion();
    }

    public static function markTested(int $id): void
    {
        DB::q('UPDATE card_parser_rules SET tested = 1 WHERE id = ?', [$id]);
    }

    public static function markMatched(int $id): void
    {
        DB::q('UPDATE card_parser_rules SET last_matched_at = ? WHERE id = ?', [DB::now(), $id]);
    }

    // ------------------------------------------------------------ device sync

    /** Monotonic config version — bumped on every cards/senders/rules change. */
    public static function version(?int $storeId = null): int
    {
        return (int) (Setting::get('sms_config_version', '1', $storeId));
    }

    /**
     * نسخهٔ پیکربندی برای مجموعه‌ای از فروشگاه‌ها (دور ۲۳ — دستگاه حساب‌محور):
     * بیشینهٔ نسخهٔ تک‌تک فروشگاه‌ها؛ هر تغییری در هرکدام، نسخه را بالا می‌برد.
     */
    public static function versionForStores(array $storeIds): int
    {
        $max = 1;
        foreach ($storeIds as $sid) {
            $max = max($max, self::version((int) $sid));
        }
        return $max;
    }

    public static function bumpVersion(?int $storeId = null): void
    {
        // دور ۲۳ — نسخهٔ «همان فروشگاه» بالا برود (قبلاً همیشه فروشگاه اول!)
        Setting::set('sms_config_version', (string) (self::version($storeId) + 1), $storeId);
    }

    /** Device-facing config (no card numbers, no secrets — label/bank/last4/senders/rules only). */
    public static function deviceConfig(int $storeId): array
    {
        return self::deviceConfigForStores([$storeId]);
    }

    /**
     * پیکربندی دستگاه برای چند فروشگاه (دور ۲۳): کارت‌های همهٔ فروشگاه‌های حساب؛
     * هر کارت store_id و store_name خودش را دارد تا اپ بداند پیامکِ هر کارت به
     * کدام فروشگاه تعلق دارد. تراکنش در سرور از روی کارت به فروشگاه وصل می‌شود.
     */
    public static function deviceConfigForStores(array $storeIds): array
    {
        $storeIds = array_values(array_map('intval', array_filter($storeIds, static fn ($v) => (int) $v > 0)));
        if ($storeIds === []) {
            return ['config_version' => 1, 'cards' => []];
        }
        $names = [];
        foreach (DB::all('SELECT id, name FROM stores WHERE id IN (' . implode(',', array_fill(0, count($storeIds), '?')) . ')', $storeIds) as $s) {
            $names[(int) $s['id']] = (string) $s['name'];
        }
        $cards = [];
        $ph = implode(',', array_fill(0, count($storeIds), '?'));
        foreach (DB::all("SELECT id, store_id, label, bank_name, number_last4, is_active FROM cards WHERE store_id IN ($ph) ORDER BY store_id, is_default DESC, id", $storeIds) as $c) {
            $cardId = (int) $c['id'];
            $rules = [];
            foreach (self::rules($cardId) as $r) {
                if ((int) $r['enabled'] !== 1) {
                    continue;
                }
                $rules[] = [
                    'id'       => (int) $r['id'],
                    'name'     => (string) $r['name'],
                    'priority' => (int) $r['priority'],
                    'rule'     => json_decode((string) $r['rule_json'], true) ?: ['v' => 1, 'fields' => [], 'required' => ['amount']],
                    'version'  => (int) (strtotime((string) $r['updated_at']) ?: 0),
                ];
            }
            $cards[] = [
                'id'         => $cardId,
                'store_id'   => (int) $c['store_id'],
                'store_name' => (string) ($names[(int) $c['store_id']] ?? ('#' . $c['store_id'])),
                'label'      => (string) ($c['label'] ?? ''),
                'bank'       => (string) $c['bank_name'],
                'last4'      => (string) $c['number_last4'],
                'enabled'    => (int) $c['is_active'] === 1,
                'senders'    => array_column(self::senders($cardId), 'sender_norm'),
                'rules'      => $rules,
                'updated_at' => time(),
            ];
        }
        return ['config_version' => self::versionForStores($storeIds), 'cards' => $cards];
    }
}
