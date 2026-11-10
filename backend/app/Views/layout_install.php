<?php
/** Installer layout. Expects: $content, $step (optional) */
$step = (int)($step ?? 0);
$titles = [1 => 'بررسی پیش‌نیازها', 2 => 'دیتابیس', 3 => 'حساب مدیر', 4 => 'فروشگاه', 5 => 'کارت مقصد', 6 => 'اتصال‌ها', 7 => 'پایان'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>نصب پیکو پی<?= isset($titles[$step]) ? ' — گام ' . Helpers::faDigits($step) . ': ' . $titles[$step] : '' ?></title>
<link rel="stylesheet" href="<?= Helpers::asset('css/admin.css') ?>">
<link rel="icon" href="<?= Helpers::asset('img/favicon.png') ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-side">
    <h2>نصب پیکو پی</h2>
    <p>راه‌اندازی در چند دقیقه و کاملاً از طریق مرورگر — بدون SSH، بدون دستور، بدون اجرای SQL دستی.</p>
    <ul>
      <li>ساخت خودکار جدول‌ها</li>
      <li>رمزنگاری اطلاعات کارت</li>
      <li>ساخت خودکار کلیدهای اتصال</li>
      <li>بدون نیاز به کرون‌جاب</li>
    </ul>
  </div>
  <div class="auth-form">
    <div class="inner" style="max-width:460px">
      <div class="brand" style="margin-bottom:8px"><span class="logo">هـ</span><div>پیکو پی<small>نصب‌کننده وب</small></div></div>
      <?php if ($step >= 1 && $step <= 7): ?>
        <div class="steps">
          <?php for ($i = 1; $i <= 7; $i++): ?>
            <span class="<?= $i < $step ? 'done' : ($i === $step ? 'cur' : '') ?>"></span>
          <?php endfor; ?>
        </div>
        <div class="step-label">گام <?= Helpers::faDigits($step) ?> از ۷ — <?= $titles[$step] ?></div>
      <?php endif; ?>
      <?php foreach (['success', 'error'] as $fl): $msg = Session::flash($fl); if ($msg !== null && $msg !== ''): ?>
        <div class="flash <?= $fl ?>"><?= Helpers::e($msg) ?></div>
      <?php endif; endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
<script src="<?= Helpers::asset('js/admin.js') ?>"></script>
</body>
</html>
