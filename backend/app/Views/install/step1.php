<div class="card">
  <div class="card-title">بررسی محیط اجرا</div>
  <?php foreach ($checks as $c): ?>
    <div class="req-item">
      <div class="mark <?= $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'opt') ?>"><?= $c['ok'] ? '✓' : ($c['required'] ? '✕' : '!') ?></div>
      <div>
        <b><?= Helpers::e($c['name']) ?></b>
        <small><?= Helpers::e($c['hint']) ?></small>
      </div>
      <span class="tag"><?= $c['required'] ? 'الزامی' : 'اختیاری' ?></span>
    </div>
  <?php endforeach; ?>
  <form method="post" action="?step=1" style="margin-top:18px">
    <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
    <button class="btn btn-primary btn-block" <?= Installer::requirementsPassed() ? '' : 'disabled' ?>>ادامه نصب →</button>
    <?php if (!Installer::requirementsPassed()): ?>
      <p class="hint" style="color:var(--bad);margin-top:10px">تا زمانی که موارد الزامی قرمز رفع نشوند، امکان ادامه نیست. راهنمایی: در cPanel بخش «Select PHP Version» می‌توانید افزونه‌ها را فعال کنید.</p>
    <?php endif; ?>
  </form>
</div>
