<?php
/** Standalone login page (no admin layout). */
$error = Session::flash('error');
$csrf  = Session::csrfToken();
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ورود به پنل مدیریت — پیکو پی</title>
<link rel="stylesheet" href="<?= Helpers::asset('css/admin.css') ?>">
<link rel="icon" href="<?= Helpers::asset('img/favicon.png') ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-side">
    <h2>پیکو پی</h2>
    <p>سامانه پرداخت کارت‌به‌کارت ووکامرس<br>دریافت خودکار واریزی‌ها با اپلیکیشن اندروید</p>
    <ul>
      <li>تطبیق خودکار واریز با سفارش</li>
      <li>بدون نیاز به درگاه بانکی</li>
      <li>نصب آسان روی هاست اشتراکی cPanel</li>
    </ul>
  </div>
  <div class="auth-form">
    <div class="inner">
      <div class="brand"><span class="logo">هـ</span><div>پیکو پی</div></div>
      <h1>ورود به پنل مدیریت</h1>
      <?php if ($error !== null): ?><div class="alert bad"><?= Helpers::e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= Config::url('admin/login') ?>">
        <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
        <div class="field">
          <label>ایمیل</label>
          <input type="email" name="email" dir="ltr" required autofocus autocomplete="username">
        </div>
        <div class="field">
          <label>رمز عبور</label>
          <input type="password" name="password" dir="ltr" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary btn-block" type="submit">ورود</button>
      </form>
      <p style="color:var(--muted);font-size:12px;margin-top:18px">
        پس از ۵ تلاش ناموفق، ورود موقتاً قفل می‌شود (محافظت ضد حمله).
      </p>
    </div>
  </div>
</div>
</body>
</html>
