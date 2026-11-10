<div class="card">
  <div style="font-size:44px;text-align:center">🔒</div>
  <h2 style="text-align:center">نصب‌کننده قفل است</h2>
  <p style="color:var(--muted);text-align:center;line-height:2.2">
    پیکو پی قبلاً روی این سرور نصب شده است.<br>
    اگر واقعاً نیاز به اجرای مجدد نصب دارید، از پنل مدیریت → تنظیمات → امنیت، دکمه
    «باز کردن قفل نصب‌کننده» را بزنید (۳۰ دقیقه اعتبار دارد).
  </p>
  <?php if (Config::exists()): ?>
    <a class="btn btn-primary btn-block" href="<?= Config::url('admin/login') ?>">رفتن به پنل مدیریت</a>
  <?php endif; ?>
</div>
