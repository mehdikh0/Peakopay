<?php
/**
 * Peakopay — Admin: destination cards + per-card SMS senders + parser rules.
 * Card numbers encrypted at rest; always masked in lists.
 */
declare(strict_types=1);

final class CardsController
{
    public static function index(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $edit  = null;
        if ((string) Http::get('edit', '') !== '') {
            $edit = Card::find((int) Http::get('edit', 0));
            if ($edit === null || (int) $edit['store_id'] !== $sid) {
                $edit = null;
            }
        }
        $cards = $sid > 0 ? Card::all($sid) : [];
        foreach ($cards as &$c) {
            // سخت‌گیری مقاوم (دور ۱۸): یک ردیف مشکل‌دار (مثلاً schema ناقص روی
            // نصب قدیمی) نباید کل صفحه کارت‌ها را با خطای ۵۰۰ ببندد.
            try {
                $c['senders'] = CardSms::senders((int) $c['id']);
                $c['rules']   = CardSms::rules((int) $c['id']);
            } catch (Throwable $e) {
                $c['senders'] = [];
                $c['rules']   = [];
                error_log(sprintf('[cards] enrich failed for card #%d: %s (ref %s)', (int) $c['id'], $e->getMessage(), HP_REQUEST_ID));
            }
            try {
                $c['last_tx'] = DB::one(
                    'SELECT amount_rial, currency, occurred_at, status FROM transactions WHERE card_id = ? ORDER BY id DESC LIMIT 1',
                    [(int) $c['id']],
                ) ?: null;
            } catch (Throwable $e) {
                $c['last_tx'] = null;
            }
        }
        unset($c);
        View::page('admin/cards', [
            'title'      => 'کارت‌های مقصد و دریافت پیامک',
            'user'       => $user,
            'store'      => $store,
            'cards'      => $cards,
            'edit'       => $edit,
            'fieldKeys'  => CardSmsEngine::FIELD_KEYS,
            'activeNav'  => 'cards',
        ]);
    }

    public static function save(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'cards_edit', 'مدیریت کارت‌ها');
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $id    = (int) Http::post('card_id', 0);

        $numberRule = $id > 0 ? 'nullable|card' : 'required|card';
        $v = Validator::make($_POST)
            ->rule('label', 'nullable|maxlen:120', 'نام کارت')
            ->rule('bank_name', 'required|maxlen:80', 'نام بانک')
            ->rule('holder', 'required|minlen:3|maxlen:120', 'نام صاحب کارت')
            ->rule('number', $numberRule, 'شماره کارت')
            ->rule('iban', 'nullable|maxlen:30|iban', 'شماره شبا')
            ->rule('account_number', 'nullable|maxlen:30', 'شماره حساب');
        if ($v->fails()) {
            Session::flash('error', $v->firstError());
            Http::redirect(Config::url('admin/cards') . ($id > 0 ? '?edit=' . $id : ''));
        }
        $in = $v->values();
        $data = [
            'label'     => (string) ($in['label'] ?? ''),
            'bank_name' => (string) $in['bank_name'],
            'holder'    => (string) $in['holder'],
            'number'    => (string) ($in['number'] ?? ''),
            'iban'      => (string) ($in['iban'] ?? ''),
            'account'   => (string) ($in['account_number'] ?? ''),
        ];
        if ($id > 0) {
            Card::update($id, $sid, $data);
            Audit::log('card.updated', 'card=' . $id);
            Session::flash('success', 'کارت به‌روزرسانی شد.');
        } else {
            $id = Card::add($sid, $data);
            Audit::log('card.added', 'card=' . $id);
            Session::flash('success', 'کارت جدید اضافه شد و به‌صورت رمزنگاری‌شده ذخیره شد. حالا شماره‌های ارسال‌کننده پیامک و قالب‌های پیامک را برایش تنظیم کنید.');
        }
        // شماره‌های ارسال‌کننده (هر خط یک شماره)
        $senders = preg_split('#[\r\n]+#u', (string) Http::post('senders', '')) ?: [];
        CardSms::setSenders($id, $senders);
        CardSms::bumpVersion();
        Http::redirect(Config::url('admin/cards') . '?edit=' . $id);
    }

    public static function action(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'cards_edit', 'مدیریت کارت‌ها');
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $do    = (string) Http::post('do', '');
        $id    = (int) Http::post('card_id', 0);
        switch ($do) {
            case 'default':
                Card::makeDefault($id, $sid);
                Session::flash('success', 'کارت پیش‌فرض تغییر کرد.');
                break;
            case 'toggle':
                $card = Card::find($id);
                if ($card !== null && (int) $card['store_id'] === $sid) {
                    Card::setActive($id, $sid, (int) $card['is_active'] !== 1);
                    CardSms::bumpVersion();
                    Session::flash('success', 'وضعیت کارت تغییر کرد.');
                }
                break;
            case 'delete':
                if (Card::remove($id, $sid)) {
                    Audit::log('card.deleted', 'card=' . $id);
                    CardSms::bumpVersion();
                    Session::flash('success', 'کارت حذف شد.');
                }
                break;
            case 'rule_delete':
                $rule = CardSms::rule((int) Http::post('rule_id', 0), $sid);
                if ($rule !== null) {
                    CardSms::deleteRule((int) $rule['id']);
                    Session::flash('success', 'قالب پیامک حذف شد.');
                }
                Http::redirect(Config::url('admin/cards') . '?edit=' . (int) $rule['card_id']);
                exit;
            case 'rule_toggle':
                $rule = CardSms::rule((int) Http::post('rule_id', 0), $sid);
                if ($rule !== null) {
                    CardSms::toggleRule((int) $rule['id']);
                }
                Http::redirect(Config::url('admin/cards') . '?edit=' . (int) $rule['card_id']);
                exit;
        }
        Http::redirect(Config::url('admin/cards'));
    }

    // ------------------------------------------------- AJAX: آموزش فرمت پیامک

    /** POST sample → suggested segments (heuristic, deterministic) */
    public static function analyze(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'cards_edit', 'مدیریت کارت‌ها');
        self::json(self::try(fn (): array => ['ok' => true] + CardSmsEngine::analyzeSample((string) Http::post('sample', ''))));
    }

    /** POST sample + spans + name + priority → rule preview (parsed back from the same sample) */
    public static function buildRule(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'cards_edit', 'مدیریت کارت‌ها');
        self::json(self::try(function (): array {
            $sample  = (string) Http::post('sample', '');
            $spans   = json_decode((string) Http::post('spans', '[]'), true) ?: [];
            $name    = trim((string) Http::post('name', ''));
            $prio    = (int) Http::post('priority', 10);
            $cardId  = (int) Http::post('card_id', 0);
            $rule    = CardSmsEngine::buildRule($sample, $spans, $name, $prio);
            $preview = CardSmsEngine::runRule($rule, CardSmsEngine::normalizeText($sample));
            if (!$preview['ok']) {
                throw new DomainException('قالب ساخته شد اما روی خود نمونه جواب نداد: ' . implode('، ', array_map(
                    static fn (string $k): string => CardSmsEngine::FIELD_KEYS[$k] ?? $k,
                    $preview['missing'] ?: ['amount'],
                )) . ' پیدا نشد. متن ثابت اطراف فیلدها را در نمونه ببینید.');
            }
            $ruleId = 0;
            if ($cardId > 0 && Card::find($cardId) !== null && (int) Card::find($cardId)['store_id'] === (int) Store::current()['id']) {
                $ruleId = CardSms::addRule($cardId, $rule['name'], $prio, $rule, $sample);
                CardSms::markTested($ruleId);
                Audit::log('card.rule_added', 'card=' . $cardId . ' rule=' . $ruleId);
            }
            return ['ok' => true, 'rule' => $rule, 'preview' => $preview, 'rule_id' => $ruleId];
        }));
    }

    /** POST card_id + sender + text → full pipeline result (sender match + rules + fields) */
    public static function test(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'cards_edit', 'مدیریت کارت‌ها');
        self::json(self::try(function (): array {
            $sid    = (int) Store::current()['id'];
            $sender = (string) Http::post('sender', '');
            $text   = (string) Http::post('text', '');
            $cardId = (int) Http::post('card_id', 0);

            // مثل خط لوله اپ: اول فرستنده → کارت (فرستنده ثبت‌نشده = نادیده).
            // اگر فرستنده خالی داده شود، card_id مستقیم استفاده می‌شود (تست قالب بدون فرستنده).
            $card = trim($sender) !== ''
                ? CardSms::cardBySender($sender, $sid)
                : (Card::find($cardId) !== null && (int) Card::find($cardId)['store_id'] === $sid ? Card::find($cardId) : null);
            if ($card === null || (int) $card['is_active'] !== 1) {
                return ['ok' => false, 'stage' => 'sender', 'valid' => false,
                        'message' => 'این شماره ارسال‌کننده به هیچ کارت فعلی ثبت نشده است — چنین پیامکی توسط اپ نادیده گرفته می‌شود. (نرمال‌شده: ' . CardSmsEngine::normalizeSender($sender) . ')'];
            }
            $rules = array_values(array_filter(CardSms::rules((int) $card['id']), static fn (array $r): bool => (int) $r['enabled'] === 1));
            $res = CardSmsEngine::runRules($rules, $text);
            if (!$res['ok']) {
                return ['ok' => false, 'stage' => 'parse', 'valid' => false, 'card' => self::cardLabel($card),
                        'message' => ($res['reject_reason'] ?? 'پارس ناموفق بود.') . ' فیلدهای پیدا‌نشده: ' . implode('، ', array_map(
                            static fn (string $k): string => CardSmsEngine::FIELD_KEYS[$k] ?? $k, $res['missing'] ?: [])) . '.'];
            }
            CardSms::markMatched((int) $res['rule_id']);
            return ['ok' => true, 'stage' => 'parsed', 'valid' => true, 'card' => self::cardLabel($card),
                    'matched_rule' => $res['rule_name'], 'confidence' => $res['confidence'],
                    'fields' => $res['fields'], 'currency' => $res['currency'], 'amount_rial' => $res['amount_rial'],
                    'message' => 'VALID — تراکنش معتبر'];
        }));
    }

    private static function cardLabel(array $card): string
    {
        $l = trim((string) ($card['label'] ?? ''));
        return ($l !== '' ? $l . ' — ' : '') . $card['bank_name'] . ' ****' . $card['number_last4'];
    }

    private static function try(callable $fn): array
    {
        try {
            return $fn();
        } catch (DomainException $e) {
            return ['ok' => false, 'valid' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            Log::error('cards ajax: ' . $e->getMessage());
            return ['ok' => false, 'valid' => false, 'message' => 'خطای داخلی — در لاگ ثبت شد.'];
        }
    }

    private static function json(array $payload): void
    {
        Http::json($payload);
    }
}
