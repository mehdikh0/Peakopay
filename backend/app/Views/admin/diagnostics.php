<?php
/** Diagnostics page. Expects: $data (summary: checks, counts) */
$counts = $data['counts'] ?? ['ok' => 0, 'warning' => 0, 'error' => 0];
$icons = ['ok' => '✓', 'warning' => '!', 'error' => '✕'];
?>
<div class="grid cols-3" style="margin-bottom:16px">
  <div class="card stat"><div class="icon i2">✓</div><div class="value"><?= Helpers::faDigits($counts['ok']) ?></div><div class="label">بررسی سالم</div></div>
  <div class="card stat"><div class="icon i3">!</div><div class="value"><?= Helpers::faDigits($counts['warning']) ?></div><div class="label">هشدار</div></div>
  <div class="card stat"><div class="icon i1" style="background:var(--bad-soft);color:var(--bad)">✕</div><div class="value"><?= Helpers::faDigits($counts['error']) ?></div><div class="label">خطا</div></div>
</div>

  <div class="card">
  <div class="card-title">نتیجه بررسی سیستم
    <span class="more" style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
      <form method="post" action="<?= Config::url('admin/diagnostics/repair-db') ?>" style="display:inline" data-confirm="مهاجرت‌های دیتابیس همین الان اجرا شوند؟ (روی دیتابیس سالم بی‌ضرر است)">
        <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
        <button class="btn btn-ok btn-sm">🛠 تعمیر پایگاه داده</button>
      </form>
      <form method="post" action="<?= Config::url('admin/diagnostics/run') ?>" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
        <button class="btn btn-primary btn-sm">↻ اجرای بررسی کامل</button>
      </form>
    </span>
  </div>
  <?php foreach ($data['checks'] ?? [] as $c): ?>
    <div class="diag">
      <div class="st <?= Helpers::e($c['status']) ?>"><?= $icons[$c['status']] ?? '?' ?></div>
      <div>
        <b><?= Helpers::e($c['title']) ?></b>
        <p style="white-space:pre-line;line-height:1.9"><?= Helpers::e($c['message']) ?></p>
      </div>
    </div>
  <?php endforeach; ?>
  <p class="hint" style="color:var(--muted);margin-top:14px">«اجرای بررسی کامل» شامل آزمون واقعی اتصال به ووکامرس هم است. هر مورد خطا باید قبل از شروع فروش رفع شود؛ هشدارها معمولاً اختیاری‌اند اما توضیحشان را بخوانید.</p>
</div>
