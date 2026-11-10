<?php
/** Logs page. Expects: $rows (audit), $appLog, $q */
?>
<div class="grid cols-2" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <div class="card-title">📜 گزارش رخدادهای امنیتی
      <form method="get" class="more" style="display:flex;gap:6px">
        <input type="text" name="q" class="f-log" placeholder="فیلتر رخداد…" value="<?= Helpers::e($q) ?>">
        <button class="btn btn-ghost btn-sm">فیلتر</button>
      </form>
    </div>
    <?php if ($rows === []): ?>
      <div class="empty"><div class="empty-icon">📜</div><b>رخدادی ثبت نشده</b>ورودها، تغییر تنظیمات و عملیات‌های حساس اینجا ثبت می‌شوند.</div>
    <?php else: ?>
      <div class="table-wrap" style="border:0;max-height:520px;overflow-y:auto">
        <table style="min-width:420px">
          <tr><th>رخداد</th><th>کاربر</th><th>جزئیات</th><th>IP</th><th>زمان</th></tr>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><span class="mono" style="font-size:11.5px"><?= Helpers::e($r['action']) ?></span></td>
            <td><?= Helpers::e($r['user_name'] ?? 'سیستم') ?></td>
            <td style="white-space:normal;font-size:12px;color:var(--muted)"><?= Helpers::trunc($r['detail'], 60) ?></td>
            <td class="mono" style="font-size:11px"><?= Helpers::e($r['ip']) ?></td>
            <td style="font-size:12px"><?= Helpers::jdate($r['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">🖥️ لاگ فنی امروز (مقادیر حساس پاک‌سازی شده‌اند)</div>
    <?php if ($appLog === []): ?>
      <div class="empty"><div class="empty-icon">🖥️</div><b>موردی ثبت نشده</b>رخدادهای فنی امروز، اینجا ثبت می‌شوند.</div>
    <?php else: ?>
      <div class="code-box" style="max-height:520px;overflow:auto;white-space:pre-wrap"><?= Helpers::e(implode("\n", array_reverse($appLog))) ?></div>
    <?php endif; ?>
  </div>
</div>
