<?php
/** Devices page. Expects: $devices,$pairing (json or null) */
$pairing = $pairing !== null ? json_decode($pairing, true) : null;
?>
<?php if ($pairing !== null): ?>
<div class="card pairing-hero">
  <h3 style="margin:0">کد همراه‌سازی دستگاه جدید</h3>
  <p style="color:var(--muted)">این کد <b>۱۰ دقیقه</b> اعتبار دارد و فقط یک بار قابل استفاده است.<br>در اپ پیکو پی روی «همراه‌سازی با پنل» بزنید و این کد را وارد کنید (یا QR را اسکن کنید).</p>
  <div class="pairing-code"><?= Helpers::e($pairing['code']) ?></div>
  <div>
    <div class="qr-holder" data-qr="<?= Helpers::e($pairing['qr']) ?>"></div>
  </div>
  <p style="color:var(--muted);font-size:12px">انقضا: <?= Helpers::jdate($pairing['expires']) ?></p>
</div>
<?php endif; ?>

<div class="grid cols-2">
  <div class="card">
    <div class="card-title">📱 دستگاه‌های ثبت‌شده</div>
    <?php if ($devices === []): ?>
      <div class="empty"><div class="empty-icon">📱</div><b>هنوز دستگاهی همراه‌سازی نشده</b>کد همراه‌سازی بسازید و با اپ اندروید وارد کنید.</div>
    <?php endif; ?>
    <?php foreach ($devices as $d): [$lbl, $cls] = Helpers::statusLabel((string)$d['status']); ?>
      <div style="border:1px solid var(--line);border-radius:16px;padding:14px 16px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <b style="font-size:14.5px"><?= Helpers::e($d['name']) ?></b>
          <span class="badge <?= $cls ?>"><?= $lbl ?></span>
          <?php if ($d['app_version'] !== ''): ?><span class="chip">نسخه اپ <?= Helpers::faDigits($d['app_version']) ?></span><?php endif; ?>
          <?php if ($d['model'] !== ''): ?><span class="chip"><?= Helpers::e($d['model']) ?></span><?php endif; ?>
        </div>
        <div style="color:var(--muted);font-size:12px;margin:8px 0 10px;line-height:2">
          آخرین اتصال: <?= $d['last_seen_at'] ? Helpers::ago($d['last_seen_at']) : '—' ?>
          · آخرین پیامک: <?= $d['last_sms_at'] ? Helpers::ago($d['last_sms_at']) : '—' ?>
          · آخرین همگام‌سازی: <?= $d['last_sync_at'] ? Helpers::ago($d['last_sync_at']) : '—' ?>
          <br>همراه‌سازی: <?= Helpers::jdate($d['paired_at']) ?>
          <?= $d['approved_at'] ? ' · تأیید: ' . Helpers::jdate($d['approved_at'], false) : '' ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($d['status'] === 'pending'): ?>
            <form method="post" action="<?= Config::url('admin/devices/action') ?>">
              <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
              <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="do" value="approve">
              <button class="btn btn-primary btn-sm">✓ تأیید دستگاه</button>
            </form>
          <?php elseif ($d['status'] === 'active'): ?>
            <form method="post" action="<?= Config::url('admin/devices/action') ?>" data-confirm="دسترسی این دستگاه لغو شود؟">
              <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
              <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="do" value="revoke">
              <button class="btn btn-danger btn-sm">لغو دسترسی</button>
            </form>
          <?php elseif ($d['status'] === 'revoked'): ?>
            <form method="post" action="<?= Config::url('admin/devices/action') ?>">
              <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
              <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="do" value="approve">
              <button class="btn btn-ok btn-sm">فعال‌سازی مجدد</button>
            </form>
            <form method="post" action="<?= Config::url('admin/devices/action') ?>" data-confirm="دستگاه برای همیشه حذف شود؟">
              <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
              <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="do" value="delete">
              <button class="btn btn-danger btn-sm">حذف</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= Config::url('admin/devices/action') ?>" style="display:flex;gap:6px">
            <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
            <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="do" value="rename">
            <input type="text" name="name" value="<?= Helpers::e($d['name']) ?>" style="max-width:150px;padding:6px 10px" required>
            <button class="btn btn-ghost btn-sm">تغییر نام</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-title">مراحل اتصال اپ اندروید</div>
      <ol style="line-height:2.4;color:var(--muted);padding-inline-start:20px;margin:0">
        <li>اپ <b>پیکو پی</b> را روی گوشی فروشنده نصب کنید.</li>
        <li>روی «همراه‌سازی با پنل» بزنید.</li>
        <li>کد مقابل را در اپ وارد کنید یا QR را اسکن کنید.</li>
        <li>دستگاه با وضعیت <b>در انتظار تأیید</b> ثبت می‌شود.</li>
        <li>همین‌جا دکمه «تأیید دستگاه» را بزنید.</li>
        <li>مجوز خواندن پیامک را در اپ فعال کنید (برای تشخیص واریزی‌ها لازم است).</li>
      </ol>
      <form method="post" action="<?= Config::url('admin/devices/new-code') ?>" style="margin-top:16px">
        <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
        <button class="btn btn-primary btn-block">+ ساخت کد همراه‌سازی جدید</button>
      </form>
      <p style="color:var(--muted);font-size:12px">ساخت کد جدید، کدهای استفاده‌نشده قبلی را باطل می‌کند.</p>
    </div>
  </div>
</div>
