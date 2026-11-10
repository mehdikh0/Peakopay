<?php
/**
 * Round 6 — تست‌های واحد «وضعیت اتصال‌ها» (صفحه عیب‌یابی تقویت‌شده):
 *  - بررسی خط لوله پیامک (فرستنده + پارسر هر کارت)
 *  - بررسی «آخرین تست اتصال افزونه» از روی لاگ حسابرسی
 *  - جزئیات ضربان هر دستگاه اندروید
 *
 * (مسیرهای HTTP احراز هویت — هدر/X-Peakopay-Key/بدنه — در e2e تست می‌شوند
 *  چون خروجی JSON کنترلر با exit همراه است.)
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../unit_db_init.php';

section('Diagnostics — وضعیت همه اتصال‌ها');

// ── لاگ حسابرسی: یک شکست قدیمی و سپس تست موفق جدیدتر (ترتیب واقعی) ──
Audit::log('api.auth_failed', 'missing-credentials path=/api/v1/test-connection ua=Peakopay-WooCommerce/1.0.2');
Audit::log('api.test_connection', 'ok store=' . $T['public_id'] . ' cards=1 devices=1', ['store_id' => (int) $T['store_id']]);

$sum = Diagnostics::summary(false);
$keys = array_column($sum['checks'], 'key');
t('diagnostics has sms_pipeline check', in_array('sms_pipeline', $keys, true));
t('diagnostics has woo_test check', in_array('woo_test', $keys, true));
t('diagnostics has devices heartbeat check', in_array('devices', $keys, true));

$smsCheck = $wooCheck = $devCheck = null;
foreach ($sum['checks'] as $c) {
    if ($c['key'] === 'sms_pipeline') { $smsCheck = $c; }
    if ($c['key'] === 'woo_test') { $wooCheck = $c; }
    if ($c['key'] === 'devices') { $devCheck = $c; }
}

// کارت seed شده فرستنده/قالب ندارد → هشدار
t('seed card without senders → sms_pipeline warning', $smsCheck !== null && $smsCheck['status'] === 'warning' && str_contains($smsCheck['message'], 'فرستنده'));

// آخرین تست موفق جدیدتر از شکست است → سبز
t('woo_test ok when last success is newer', $wooCheck !== null && $wooCheck['status'] === 'ok' && str_contains($wooCheck['message'], 'موفق'));

// دستگاه seed شده (active) با جزئیات ضربان
t('devices check lists per-device heartbeat', $devCheck !== null && $devCheck['status'] === 'ok' && str_contains($devCheck['message'], 'ضربان'));

// ── شکستِ جدیدتر از موفق → قرمز ──
Audit::log('api.auth_failed', 'bad-key store=' . $T['public_id'] . ' path=/api/v1/test-connection');
$sum = Diagnostics::summary(false);
foreach ($sum['checks'] as $c) {
    if ($c['key'] === 'woo_test') { $wooCheck = $c; }
}
t('woo_test error when last attempt failed', $wooCheck !== null && $wooCheck['status'] === 'error' && str_contains($wooCheck['message'], 'ناموفق'));

// ── کارت کامل (فرستنده + قالب) → خط لوله سبز ──
CardSms::setSenders((int) $T['card_id'], ['30001234']);
CardSms::addRule((int) $T['card_id'], 'قالب تست', 10, ['v' => 1, 'fields' => [['key' => 'amount', 'pattern' => '[0-9,]+', 'before' => 'مبلغ ']], 'required' => ['amount']], 'نمونه');
$sum = Diagnostics::summary(false);
foreach ($sum['checks'] as $c) {
    if ($c['key'] === 'sms_pipeline') { $smsCheck = $c; }
}
t('card with senders+rules → sms_pipeline ok', $smsCheck !== null && $smsCheck['status'] === 'ok' && str_contains($smsCheck['message'], '1 فرستنده'));

// ── هیچ تستی ثبت نشده → حالت خنثی ──
DB::q('DELETE FROM audit_logs');
$sum = Diagnostics::summary(false);
foreach ($sum['checks'] as $c) {
    if ($c['key'] === 'woo_test') { $wooCheck = $c; }
}
t('no test ever → woo_test hints how to run it', $wooCheck !== null && $wooCheck['status'] === 'warning' && str_contains($wooCheck['message'], 'تست اتصال'));

section('Helpers::ago — نمایش صحیح فاصله زمانی');

$justNow = Helpers::ago(gmdate('Y-m-d H:i:s'));
t('ago(now) is seconds not hours (offset bug fixed)', str_contains($justNow, 'ثانیه'));
$threeHoursAgo = Helpers::ago(gmdate('Y-m-d H:i:s', time() - 3 * 3600));
t('ago(3h ago) shows 3 hours', str_contains($threeHoursAgo, '۳ ساعت'));
$old = Helpers::ago(gmdate('Y-m-d H:i:s', time() + 30));
t('ago(future) shows «دیگر» not negative', str_contains($old, 'دیگر') || str_contains($old, 'ثانیه'));
