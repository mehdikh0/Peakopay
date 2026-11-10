<?php
/** Test tools. Expects: $testMode, $testPayments, $sampleSms */
$csrf = Session::csrfToken();
$testPayment = Session::flash('test_payment');
$testPayment = $testPayment !== null ? json_decode($testPayment, true) : null;
$testResult = Session::flash('test_result');
$testResult = $testResult !== null ? json_decode($testResult, true) : null;
?>
<?php if (!$testMode): ?>
<div class="alert warn">حالت تست <b>فعال نیست</b>. از <a href="<?= Config::url('admin/settings') ?>?section=advanced">تنظیمات → پیشرفته</a> فعالش کنید، یا مستقیم:</div>
<form method="post" action="<?= Config::url('admin/testtools/action') ?>">
  <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
  <input type="hidden" name="do" value="enable_test_mode">
  <button class="btn btn-primary">فعال‌سازی حالت تست</button>
</form>
<?php endif; ?>

<?php if ($testPayment !== null): ?>
<div class="card" style="border:1.5px solid var(--brand-2);margin-bottom:16px">
  <div class="card-title">🧪 پرداخت تستی ساخته شد — <span class="badge badge-mute">تست</span></div>
  <div class="kv">
    <div class="k">شماره سفارش تستی</div><div class="mono"><?= Helpers::e($testPayment['order']) ?></div>
    <div class="k">مبلغ قابل پرداخت</div><div><?= Helpers::money($testPayment['amount_rial']) ?></div>
    <div class="k">لینک صفحه پرداخت</div>
    <div class="copy-row" style="max-width:420px">
      <div class="code-box" style="padding:8px 12px;font-size:12px"><?= Helpers::e($testPayment['url']) ?></div>
      <button class="btn btn-ghost btn-sm" data-copy="<?= Helpers::e($testPayment['url']) ?>">کپی</button>
    </div>
  </div>
  <a class="btn btn-primary" style="margin-top:10px" href="<?= Helpers::e($testPayment['url']) ?>" target="_blank">باز کردن صفحه پرداخت</a>
</div>
<?php endif; ?>

<?php if ($testResult !== null): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-title">🔬 نتیجه شبیه‌سازی</div>
  <div class="code-box" style="direction:rtl;text-align:right"><?= Helpers::e(json_encode($testResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
</div>
<?php endif; ?>

<div class="grid cols-2">
  <div class="card">
    <div class="card-title">۱) ساخت پرداخت تستی</div>
    <form method="post" action="<?= Config::url('admin/testtools/action') ?>">
      <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
      <input type="hidden" name="do" value="test_payment">
      <div class="field">
        <label>مبلغ (تومان)</label>
        <input type="number" name="amount_toman" min="2000" value="5000" required>
        <div class="hint">حداقل مبلغ باید از «حداقل مبلغ پرداخت» تنظیمات بزرگ‌تر باشد (پیش‌فرض: ۲٬۰۰۰ تومان). پرداخت تستی با برچسب «تست» ساخته می‌شود و هیچ‌وقت با پرداخت واقعی اشتباه نمی‌شود.</div>
      </div>
      <button class="btn btn-primary btn-block" <?= $testMode ? '' : 'disabled' ?>>ساخت پرداخت تستی</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title">۲) شبیه‌سازی پیامک بانکی</div>
    <form method="post" action="<?= Config::url('admin/testtools/action') ?>">
      <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
      <input type="hidden" name="do" value="simulate_sms">
      <div class="field"><label>فرستنده (اختیاری)</label>
        <input type="text" name="sender" dir="ltr" placeholder="Mellat"></div>
      <div class="field"><label>متن پیامک</label>
        <textarea name="sms_text" required><?= Helpers::e($sampleSms) ?></textarea>
        <div class="hint">پیامک را با قالب بانک خودتان عوض کنید؛ باید شامل کلمه «واریز» و «مبلغ» باشد.</div></div>
      <button class="btn btn-primary btn-block" <?= $testMode ? '' : 'disabled' ?>>ارسال به‌عنوان تراکنش</button>
    </form>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-title">پرداخت‌های تستی اخیر</div>
  <?php if ($testPayments === []): ?>
    <div class="empty"><div class="empty-icon">🧪</div><b>پرداخت تستی‌ای نیست</b>از فرم بالا یکی بسازید.</div>
  <?php else: ?>
    <div class="table-wrap" style="border:0">
      <table>
        <tr><th>سفارش</th><th>مبلغ</th><th>وضعیت</th><th>ساخت</th><th></th></tr>
        <?php foreach ($testPayments as $p): [$lbl, $cls] = Helpers::statusLabel((string)$p['status']); ?>
        <tr>
          <td class="mono"><?= Helpers::e($p['order_id']) ?></td>
          <td><?= Helpers::money($p['amount_rial']) ?></td>
          <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
          <td><?= Helpers::ago($p['created_at']) ?></td>
          <td><a class="btn btn-ghost btn-sm" href="<?= Config::url('pay/' . $p['token']) ?>" target="_blank">صفحه پرداخت</a></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>
