<?php
/** صفحهٔ تأیید ورود دومرحله‌ای (استاندالون — مثل صفحهٔ ورود). Expects: $title, $method */
$error = Session::flash('error');
$csrf  = Session::csrfToken();
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ورود دومرحله‌ای — پیکو پی</title>
<link rel="stylesheet" href="<?= Helpers::asset('css/admin.css') ?>">
<link rel="icon" href="<?= Helpers::asset('img/favicon.png') ?>">
<style>
.otp-input{width:100%;max-width:260px;margin:0 auto;text-align:center;font-size:30px;letter-spacing:14px;
  direction:ltr;font-family:ui-monospace,monospace;padding:12px 0 12px 14px;border-radius:14px}
.otp-tabs{display:flex;gap:8px;margin:16px 0 4px}
.otp-tabs a{flex:1;text-align:center;padding:9px 10px;border-radius:12px;border:1px solid var(--line);
  color:var(--muted);text-decoration:none;font-size:13.5px}
.otp-tabs a.active{background:var(--brand);border-color:var(--brand);color:#fff;font-weight:700}
.recovery-hint{background:rgba(255,255,255,.12);border:1px dashed rgba(255,255,255,.35);border-radius:12px;
  padding:10px 12px;font-size:12.5px;color:#e7e3ff;margin-top:12px;line-height:1.9}
</style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-side">
    <h2>پیکو پی</h2>
    <p>ورود دومرحله‌ای فعال است<br>برای تکمیل ورود، کد تأیید را وارد کنید</p>
    <ul>
      <li>کد هر ۳۰ ثانیه تغییر می‌کند</li>
      <li>حتی بدون اینترنت از برنامهٔ احرازگر کد بگیرید</li>
      <li>بدون دسترسی به احرازگر: ورود با کد بازیابی</li>
    </ul>
  </div>
  <div class="auth-form">
    <div class="inner">
      <div class="brand"><span class="logo">هـ</span><div>پیکو پی</div></div>
      <h1>ورود دومرحله‌ای</h1>
      <?php if ($error !== null): ?><div class="alert bad"><?= Helpers::e($error) ?></div><?php endif; ?>

      <div class="otp-tabs">
        <a href="<?= Config::url('admin/login/2fa') ?>" class="<?= $method === 'totp' ? 'active' : '' ?>">🔢 کد احرازگر</a>
        <a href="<?= Config::url('admin/login/2fa') ?>?m=recovery" class="<?= $method === 'recovery' ? 'active' : '' ?>">🔑 کد بازیابی</a>
      </div>

      <?php if ($method === 'recovery'): ?>
        <form method="post" action="<?= Config::url('admin/login/2fa') ?>?m=recovery">
          <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="method" value="recovery">
          <div class="field">
            <label>رمز عبور</label>
            <input type="password" name="password" dir="ltr" required autocomplete="current-password" autofocus>
          </div>
          <div class="field">
            <label>کد بازیابی (یک‌بارمصرف)</label>
            <input type="text" name="recovery_code" dir="ltr" class="mono" required autocomplete="off"
                   placeholder="XXXXX-XXXXX-XXXXX" style="text-align:center;letter-spacing:2px">
          </div>
          <div class="recovery-hint">
            یکی از کدهایی که هنگام فعال‌سازی 2FA ذخیره کردید را وارد کنید. هر کد فقط یک بار قابل استفاده است
            و پس از مصرف، برای همیشه باطل می‌شود. رمز عبور هم برای تأیید هویت لازم است.
          </div>
          <button class="btn btn-primary btn-block" type="submit" style="margin-top:14px">ورود با کد بازیابی</button>
        </form>
      <?php else: ?>
        <form method="post" action="<?= Config::url('admin/login/2fa') ?>">
          <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="method" value="totp">
          <div class="field">
            <label style="text-align:center;width:100%">کد ۶ رقمی برنامهٔ احرازگر</label>
            <input type="text" name="code" class="otp-input" dir="ltr" inputmode="numeric" pattern="[0-9 ]{6,8}"
                   maxlength="8" required autofocus autocomplete="one-time-code" placeholder="●●●●●●">
          </div>
          <button class="btn btn-primary btn-block" type="submit" style="margin-top:14px">تأیید و ورود</button>
        </form>
      <?php endif; ?>

      <p style="color:var(--muted);font-size:12px;margin-top:18px">
        کد را از Google Authenticator / Microsoft Authenticator یا هر برنامهٔ مشابه بردارید.
      </p>
      <a href="<?= Config::url('admin/logout') ?>" style="color:var(--muted);font-size:12.5px">انصراف و خروج</a>
    </div>
  </div>
</div>
</body>
</html>
