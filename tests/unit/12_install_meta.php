<?php
/**
 * دور ۲۳ — تست‌های واحد «تنظیمات سطح نصب» (install_meta):
 *  - نوشتن/خواندن/بازنویسی کلیدهای install-level جدا از settings فروشگاهی
 *  - backfill توکن کرون قدیمی (سطح فروشگاه → سطح نصب) در اجرای مجدد مهاجرت‌ها
 *  - عدم وابستگی توکن نصب به فروشگاهِ جاریِ نشست
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../unit_db_init.php';

section('Install-level settings (install_meta)');

// نوشتن و خواندن
Setting::installSet('cron_token', 'deadbeef01');
t('installSet/installGet roundtrip', Setting::installGet('cron_token') === 'deadbeef01');

// بازنویسی مقدار
Setting::installSet('cron_token', 'deadbeef02');
t('installSet overwrites (upsert)', Setting::installGet('cron_token') === 'deadbeef02');

// کلید ناموجود → پیش‌فرض
t('installGet missing key → default', Setting::installGet('nope', 'fallback') === 'fallback');
t('installGet missing key → empty', Setting::installGet('nope2') === '');

// مستقل از فروشگاه جاریِ نشست: با تغییر زمینهٔ فروشگاه، مقدار نصب همان است
Helpers::withStore((int) $T['store_id'], static function () use ($T): void {
    Setting::installSet('scoped_probe', 'same-everywhere');
});
t('install-level value store-independent', Setting::installGet('scoped_probe') === 'same-everywhere');

// مقدار سطح فروشگاه جدا از سطح نصب می‌ماند
Setting::set('store_only_key', 'per-store', (int) $T['store_id']);
t('per-store setting stays per-store', Setting::get('store_only_key', '', (int) $T['store_id']) === 'per-store');
t('per-store setting not visible at install level', Setting::installGet('store_only_key') === '');

// ── backfill: نصب قدیمی توکن را در سطح فروشگاه دارد → migrate مجدد به install_meta می‌آورد ──
DB::q('DELETE FROM install_meta WHERE skey = ?', ['cron_token']);
DB::q(
    'INSERT INTO settings (store_id, skey, svalue) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
    [(int) $T['store_id'], 'cron_token', 'legacy-token-abc']
);
Installer::migrate(force: true); // مسیر تعمیر: اجرای مجدد مهاجرت‌ها → backfill
t('legacy cron token backfilled to install_meta', Setting::installGet('cron_token') === 'legacy-token-abc');

// backfill مقدار موجود را بازنویسی نمی‌کند (idempotent)
DB::q(
    'INSERT INTO settings (store_id, skey, svalue) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
    [(int) $T['store_id'], 'cron_token', 'legacy-token-CHANGED']
);
Installer::migrate(force: true);
t('backfill never overwrites existing install value', Setting::installGet('cron_token') === 'legacy-token-abc');

// schemaStatus جداول ۰۰۳ را می‌شناسد
$st = Installer::schemaStatus();
t('schemaStatus ok with 003 tables', $st['ok'] === true);
DB::q('DROP TABLE install_meta');
$st2 = Installer::schemaStatus();
t('schemaStatus flags missing install_meta', $st2['ok'] === false && in_array('جدول `install_meta`', $st2['missing'], true));
Installer::migrate(force: true); // بازسازی برای ادامهٔ تست‌های بعدی
t('install_meta recreated by repair', Installer::schemaStatus()['ok'] === true);
