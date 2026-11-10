<?php
/** Payment detail. Expects: $payment,$card,$txn,$hooks,$candidates */
$p = $payment;
[$lbl, $cls] = Helpers::statusLabel((string)$p['status']);
$payUrl = Config::url('pay/' . $p['token']);
?>
<div class="detail-grid">
  <div>
    <div class="card">
      <div class="card-title">اطلاعات پرداخت <?= (int)$p['is_test'] === 1 ? '<span class="badge badge-mute">پرداخت تستی</span>' : '' ?></div>
      <div class="kv">
        <div class="k">شناسه</div><div class="mono">#<?= Helpers::faDigits($p['id']) ?></div>
        <div class="k">وضعیت</div><div><span class="badge <?= $cls ?>"><?= $lbl ?></span></div>
        <div class="k">سفارش ووکامرس</div><div><?= Helpers::faDigits($p['order_id'] ?: '—') ?> <?= $p['order_key'] ? '<span class="sub mono">' . Helpers::e($p['order_key']) . '</span>' : '' ?></div>
        <div class="k">مبلغ قابل پرداخت</div><div><b><?= Helpers::money($p['amount_rial']) ?></b><?= (int)$p['amount_rial'] !== (int)$p['amount_base_rial'] ? ' <span class="sub">(مبلغ سفارش: ' . Helpers::money($p['amount_base_rial']) . ' — برای یکتاسازی مبلغ، ۳ رقم آخر متفاوت است)</span>' : '' ?></div>
        <div class="k">مشتری</div><div><?= Helpers::e($p['customer_name'] ?: '—') ?> <?= $p['customer_phone'] ? '<span class="sub mono">' . Helpers::e($p['customer_phone']) . '</span>' : '' ?></div>
        <div class="k">ایجاد</div><div><?= Helpers::jdate($p['created_at']) ?></div>
        <div class="k">انقضا</div><div><?= Helpers::jdate($p['expires_at']) ?> <span class="sub"><?= Helpers::ago($p['expires_at']) ?></span></div>
        <div class="k">پرداخت‌شده</div><div><?= $p['paid_at'] ? Helpers::jdate($p['paid_at']) : '—' ?></div>
        <div class="k">لینک صفحه پرداخت</div>
        <div class="copy-row" style="max-width:420px">
          <div class="code-box" style="padding:8px 12px;font-size:12px"><?= Helpers::e($payUrl) ?></div>
          <button class="btn btn-ghost btn-sm" data-copy="<?= Helpers::e($payUrl) ?>">کپی</button>
        </div>
      </div>
    </div>

    <?php if ($txn !== null): ?>
    <div class="card" style="margin-top:16px">
      <div class="card-title">تراکنش متصل‌شده</div>
      <div class="kv">
        <div class="k">شناسه تراکنش</div><div class="mono">#<?= Helpers::faDigits($txn['id']) ?></div>
        <div class="k">مبلغ</div><div><?= Helpers::money($txn['amount_rial']) ?></div>
        <div class="k">بانک / فرستنده</div><div><?= Helpers::e($txn['bank'] ?: '—') ?> / <?= Helpers::e($txn['sender'] ?: '—') ?></div>
        <div class="k">مرجع</div><div class="mono"><?= Helpers::e($txn['reference'] ?: '—') ?></div>
        <div class="k">۴ رقم آخر کارت مقصد</div><div class="mono"><?= $txn['dest_last4'] !== '' ? Helpers::faDigits($txn['dest_last4']) : '—' ?></div>
        <div class="k">زمان تراکنش</div><div><?= Helpers::jdate($txn['occurred_at']) ?></div>
        <div class="k">متن پیامک</div>
        <div><button class="btn btn-ghost btn-sm" data-raw="<?= (int)$txn['id'] ?>" data-url="<?= Config::url('admin/transactions/' . (int)$txn['id'] . '/raw') ?>">مشاهده متن پیامک</button></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-title">عملیات</div>
      <?php if ($p['status'] === 'verifying'): ?>
        <form method="post" action="<?= Config::url('admin/payments/action') ?>" style="margin-bottom:8px">
          <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
          <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="do" value="confirm">
          <button class="btn btn-primary btn-block">✓ تأیید دستی پرداخت</button>
        </form>
        <p class="hint" style="color:var(--muted);font-size:12px">پرداخت در حالت «بررسی» است؛ پس از اطمینان از واریز، تأیید کنید تا سفارش در ووکامرس کامل شود.</p>
      <?php endif; ?>

      <?php if (in_array($p['status'], ['paid', 'expired'], true)): ?>
        <form method="post" action="<?= Config::url('admin/payments/action') ?>" style="margin-bottom:8px" data-confirm="رویداد دوباره به ووکامرس ارسال شود؟">
          <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
          <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="do" value="resend">
          <button class="btn btn-ghost btn-block">↻ ارسال مجدد رویداد به ووکامرس</button>
        </form>
      <?php endif; ?>

      <?php if (in_array($p['status'], ['pending', 'verifying'], true)): ?>
        <form method="post" action="<?= Config::url('admin/payments/action') ?>" data-confirm="این پرداخت منقضی شود و به ووکامرس اطلاع داده شود؟">
          <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
          <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="do" value="expire">
          <button class="btn btn-danger btn-block">منقضی کردن پرداخت</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($card !== null): ?>
    <div class="card" style="margin-top:16px">
      <div class="card-title">کارت مقصد</div>
      <div class="kv">
        <div class="k">بانک</div><div><?= Helpers::e($card['bank_name']) ?></div>
        <div class="k">صاحب کارت</div><div><?= Helpers::e($card['holder']) ?></div>
        <div class="k">شماره کارت</div><div class="mono"><?= Helpers::e($card['mask']) ?></div>
        <div class="k">شبا</div><div class="mono"><?= Helpers::e($card['iban'] !== '' ? Crypto::maskIban($card['iban']) : '—') ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($hooks !== []): ?>
    <div class="card" style="margin-top:16px">
      <div class="card-title">رویدادهای Webhook</div>
      <?php foreach ($hooks as $h): ?>
        <div class="stat-sm">
          <?= Helpers::e($h['event']) ?>
          <b>
            <?php if ($h['status'] === 'delivered'): ?><span class="badge badge-ok">تحویل شد</span>
            <?php elseif ($h['status'] === 'failed'): ?><span class="badge badge-bad" title="<?= Helpers::e($h['last_error']) ?>">ناموفق</span>
            <?php else: ?><span class="badge badge-mute">در انتظار</span><?php endif; ?>
          </b>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($candidates !== []): ?>
    <div class="card" style="margin-top:16px">
      <div class="card-title">اتصال دستی تراکنش</div>
      <p class="hint" style="color:var(--muted);font-size:12px;margin:0 0 10px">این تراکنش‌های واریزی با مبلغ نزدیک به این پرداخت، تطبیق خودکار نشدند. اگر مطمئن هستید یکی از آن‌ها مربوط به همین سفارش است، متصل کنید:</p>
      <?php foreach ($candidates as $c): ?>
        <form method="post" action="<?= Config::url('admin/payments/action') ?>" style="margin-bottom:8px" data-confirm="این تراکنش به این پرداخت متصل شود و سفارش تأیید گردد؟">
          <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
          <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="transaction_id" value="<?= (int)$c['id'] ?>">
          <input type="hidden" name="do" value="attach">
          <button class="btn btn-ghost btn-sm">
            <?= Helpers::money($c['amount_rial']) ?> — <?= Helpers::e($c['bank'] ?: 'بانک ناشناس') ?>
            <?= $c['dest_last4'] !== '' ? '(کارت •' . Helpers::faDigits($c['dest_last4']) . ')' : '' ?>
            — <?= Helpers::ago($c['occurred_at']) ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
