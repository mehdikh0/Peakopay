<?php
/** Team page. Expects: $members,$stores,$roles */
$members = $members ?? [];
$stores  = $stores ?? [];
$roles   = $roles ?? Account::ROLE_LABELS;
$meId    = (int) ($user['id'] ?? 0);
?>
<div class="card">
  <div class="card-title">👥 اعضای حساب و دسترسی فروشگاه‌ها</div>
  <?php if ($members === []): ?>
    <div class="empty"><div class="empty-icon">👥</div><b>عضوی جز شما نیست</b>از فرم پایین همکار اضافه کنید.</div>
  <?php endif; ?>
  <?php foreach ($members as $m): ?>
    <?php
      $roleLbl = $roles[$m['role']] ?? $m['role'];
      $storeChips = '';
      foreach (($m['stores'] ?? []) as $sid => $srole) {
          $sname = '';
          foreach ($stores as $s) { if ((int)$s['id'] === (int)$sid) { $sname = (string)$s['name']; break; } }
          $storeChips .= '<span class="chip">' . Helpers::e($sname !== '' ? $sname : ('#' . $sid)) . '</span> ';
      }
    ?>
    <div style="border:1px solid var(--line);border-radius:16px;padding:14px 16px;margin-bottom:12px">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <b><?= Helpers::e($m['name']) ?></b>
        <span class="badge <?= $m['role'] === 'owner' ? 'ok' : '' ?>"><?= Helpers::e($roleLbl) ?></span>
        <?php if ((int)$m['is_active'] !== 1): ?><span class="badge err">غیرفعال</span><?php endif; ?>
        <?php if ((int)$m['id'] === $meId): ?><span class="chip">شما</span><?php endif; ?>
        <span style="color:var(--muted);font-size:12px" dir="ltr"><?= Helpers::e($m['email']) ?></span>
      </div>
      <div style="margin:8px 0;color:var(--muted);font-size:12px">
        آخرین ورود: <?= $m['last_login_at'] ? Helpers::ago($m['last_login_at']) : 'هرگز' ?>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <span style="color:var(--muted);font-size:12px">دسترسی به:</span>
        <?= $storeChips !== '' ? $storeChips : '<span style="color:var(--muted);font-size:12px">—</span>' ?>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <?php if (!empty($m['twofa'])): ?>
        <span class="badge ok" title="ورود دومرحله‌ای فعال">2FA ✅</span>
        <?php endif; ?>
        <?php if ((int)$m['id'] !== $meId && !empty($m['twofa'])): ?>
        <details style="margin-top:8px">
          <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">🔑 بازنشانی ورود دومرحله‌ای (در صورت از دست رفتن احرازگر)</summary>
          <form method="post" action="<?= Config::url('admin/team/update') ?>" style="margin-top:8px;display:grid;gap:8px;max-width:480px"
                data-confirm="ورود دومرحله‌ای این عضو بازنشانی شود؟ راز فعلی و همهٔ کدهای بازیابی‌اش باطل می‌شوند و نشست‌های فعالش منقضی می‌شوند.">
            <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
            <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="do" value="reset_2fa">
            <div class="field"><label>رمز عبور شما (تأیید هویت مدیر)</label>
              <input type="password" name="admin_password" dir="ltr" required autocomplete="current-password"></div>
            <div class="field"><label>دلیل بازنشانی (در سوابق امنیتی ثبت می‌شود)</label>
              <input type="text" name="reason" required minlength="10" placeholder="مثلاً: گوشی عضو گم شده و کد بازیابی ندارد"></div>
            <button class="btn btn-danger btn-sm">بازنشانی 2FA این عضو</button>
          </form>
        </details>
        <?php endif; ?>
        <?php if ((int)$m['id'] !== $meId && $m['role'] !== 'owner'): ?>
        <form method="post" action="<?= Config::url('admin/team/update') ?>">
          <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
          <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="do" value="toggle_active">
          <button class="btn btn-sm"><?= (int)$m['is_active'] === 1 ? 'غیرفعال‌سازی دسترسی' : 'فعال‌سازی دسترسی' ?></button>
        </form>
        <form method="post" action="<?= Config::url('admin/team/update') ?>" data-confirm="این عضو حذف شود؟">
          <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
          <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="do" value="remove">
          <button class="btn btn-danger btn-sm">حذف</button>
        </form>
        <?php endif; ?>
        <details>
          <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">ویرایش نقش و فروشگاه‌ها</summary>
          <form method="post" action="<?= Config::url('admin/team/update') ?>" style="margin-top:8px;display:grid;gap:8px;max-width:420px">
            <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
            <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="do" value="stores">
            <label style="font-size:12.5px;color:var(--muted)">نقش</label>
            <select name="role">
              <?php foreach ($roles as $rk => $rl): ?>
                <option value="<?= Helpers::e($rk) ?>" <?= $rk === $m['role'] ? 'selected' : '' ?>><?= Helpers::e($rl) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($stores !== []): ?>
            <label style="font-size:12.5px;color:var(--muted)">فروشگاه‌های قابل دسترسی (مالک همیشه همه)</label>
            <?php foreach ($stores as $s): ?>
              <label style="display:flex;gap:8px;align-items:center;font-size:13px">
                <input type="checkbox" name="stores[]" value="<?= (int)$s['id'] ?>" <?= isset($m['stores'][(int)$s['id']]) ? 'checked' : '' ?>>
                <?= Helpers::e($s['name']) ?>
              </label>
            <?php endforeach; ?>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm" style="justify-self:start">ذخیره دسترسی‌ها</button>
          </form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-title">➕ افزودن همکار</div>
  <p style="color:var(--muted);font-size:13px;line-height:2">
    نقش‌ها: <b>مالک حساب</b> (مدیریت همه فروشگاه‌ها و تیم) · <b>مدیر</b> (مدیریت کامل فروشگاه‌های انتخابی) ·
    <b>حسابدار</b> (دیدن گزارش‌ها و تراکنش‌ها بدون تغییر) · <b>اپراتور</b> (عملیات محدود تراکنش‌ها).
    همکار فقط به فروشگاه‌های تیک‌خورده دسترسی خواهد داشت.
  </p>
  <form method="post" action="<?= Config::url('admin/team/add') ?>" style="display:grid;gap:8px;max-width:480px">
    <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
    <input name="name" placeholder="نام همکار" required>
    <input name="email" dir="ltr" type="email" placeholder="email@example.com" required>
    <input name="password" type="password" placeholder="رمز عبور (حداقل ۸ نویسه)" required minlength="8">
    <select name="role">
      <?php foreach ($roles as $rk => $rl): ?>
        <option value="<?= Helpers::e($rk) ?>" <?= $rk === 'operator' ? 'selected' : '' ?>><?= Helpers::e($rl) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($stores !== []): ?>
      <?php foreach ($stores as $s): ?>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px">
          <input type="checkbox" name="stores[]" value="<?= (int)$s['id'] ?>" checked>
          <?= Helpers::e($s['name']) ?>
        </label>
      <?php endforeach; ?>
    <?php endif; ?>
    <button class="btn btn-primary">افزودن عضو</button>
  </form>
</div>
