<div class="card">
  <div class="card-title">اتصال دیتابیس</div>
  <div class="alert info">این اطلاعات را از cPanel → «MySQL® Databases» بردارید. اگر هنوز دیتابیس نساخته‌اید، همین حالا در cPanel یکی بسازید (نیازی به ساخت جدول نیست — پیکو پی همه جدول‌ها را خودش می‌سازد).</div>
  <form method="post" action="?step=2">
    <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
    <?php
      // اگر فایل .env موجود باشد، مقادیر آن به‌عنوان پیش‌فرض فرم پر می‌شود.
      $envDb = Config::exists() ? [] : (array) Config::get('db', []);
    ?>
    <div class="form-row">
      <div class="field"><label>هاست دیتابیس</label>
        <input type="text" name="db_host" dir="ltr" value="<?= Helpers::e($_SESSION['db_host'] ?? (string) ($envDb['host'] ?? 'localhost')) ?>" required>
        <div class="hint">در اکثر هاست‌های cPanel: <b>localhost</b></div></div>
      <div class="field"><label>پورت</label>
        <input type="number" name="db_port" dir="ltr" value="<?= (int) ($envDb['port'] ?? 3306) ?>" required></div>
    </div>
    <div class="field"><label>نام دیتابیس</label>
      <input type="text" name="db_name" dir="ltr" value="<?= Helpers::e((string) ($envDb['name'] ?? '')) ?>" placeholder="cpaneluser_peakopay" required></div>
    <div class="form-row">
      <div class="field"><label>نام کاربری دیتابیس</label>
        <input type="text" name="db_user" dir="ltr" value="<?= Helpers::e((string) ($envDb['user'] ?? '')) ?>" placeholder="cpaneluser_hp" required></div>
      <div class="field"><label>رمز عبور دیتابیس</label>
        <input type="password" name="db_pass" dir="ltr"></div>
    </div>
    <button class="btn btn-primary btn-block">تست اتصال و ساخت جدول‌ها →</button>
    <p class="hint">در صورت موفقیت، همه جدول‌ها به‌صورت خودکار ساخته و شماره‌گذاری می‌شوند (Migration خودکار).</p>
  </form>
</div>
