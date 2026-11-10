<div class="card" style="text-align:center">
  <div style="font-size:52px">🎉</div>
  <h2 style="margin:8px 0 4px">نصب پیکو پی با موفقیت کامل شد</h2>
  <p style="color:var(--muted)">نصب‌کننده برای امنیت قفل شد و قابل اجرای مجدد نیست (مگر اینکه از پنل، صریحاً باز شود).</p>

  <div class="kv" style="text-align:right;margin:18px 0">
    <div class="k">پنل مدیریت</div><div class="mono"><?= Helpers::e(Config::url('admin/login')) ?></div>
    <div class="k">صفحه سلامت سامانه</div><div class="mono"><?= Helpers::e(Config::url('health')) ?></div>
    <div class="k">اندپوینت API</div><div class="mono"><?= Helpers::e(Config::url('api/v1')) ?></div>
  </div>

  <a class="btn btn-primary btn-block" href="<?= Config::url('admin/login') ?>">ورود به پنل مدیریت</a>
  <p class="hint" style="margin-top:14px">گام بعدی: نصب افزونه ووکامرس و اتصال اپ اندروید — راهنمای گام‌به‌گام در فایل <b>INSTALL.md</b> و پوشه <b>docs/</b>.</p>
</div>
