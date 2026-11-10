<?php
/** صفحهٔ پیکربندی 2FA. Expects: $enrolled, $pendingSecret, $pendingUri, $pendingQr,
 *  $recoveryRemaining, $newCodes, $resetNote, $enforced, $enabledAt */
$csrf = Session::csrfToken();
$u = $user['email'] ?? '';
?>
<?php if ($resetNote !== null): ?>
  <div class="alert bad" style="max-width:720px">
    <b>ورود دومرحله‌ای شما توسط مدیر بازنشانی شد.</b><br>
    توسط: <?= Helpers::e($resetNote['admin_name'] ?? 'مدیر') ?> — <?= Helpers::ago($resetNote['created_at'] ?? null) ?><br>
    دلیل: <?= Helpers::e($resetNote['reason'] ?? '') ?><br>
    برای امنیت بیشتر، هنگام ورود بعدی یک احرازگر جدید پیکربندی کنید و کدهای بازیابی تازه ذخیره کنید.
  </div>
<?php endif; ?>

<?php if ($enforced && !$enrolled): ?>
  <div class="alert warn" style="max-width:720px">
    <b>سیاست حساب:</b> ورود دومرحله‌ای برای نقش‌های مدیر و مالک الزامی است. تا وقتی فعال نشود، فقط همین صفحه در دسترس است.
  </div>
<?php endif; ?>

<?php if (!$enrolled && $pendingSecret === null): ?>
  <!-- ─────────── غیرفعال — شروع ─────────── -->
  <div class="card" style="max-width:720px">
    <div class="card-title">🛡️ ورود دومرحله‌ای (Two-Factor)</div>
    <div class="alert info">
      با ورود دومرحله‌ای، بعد از رمز عبور یک کد ۶ رقمی از برنامهٔ احرازگر گوشی شما خواسته می‌شود.
      کدها <b>کاملاً آفلاین</b> و روی گوشی خودتان ساخته می‌شوند — نه گوگل و نه هیچ سرویس خارجی دیگری در ورود شما نقشی ندارد.
    </div>
    <ul style="color:var(--muted);font-size:13.5px;line-height:2.2;padding-right:18px;margin:10px 0">
      <li>سازگار با Google Authenticator، Microsoft Authenticator و هر برنامهٔ استاندارد دیگر</li>
      <li>در صورت گم شدن گوشی: ۱۰ کد بازیابی اضطراری یک‌بارمصرف</li>
      <li>راز شما رمزنگاری‌شده روی سرور ذخیره می‌شود و هرگز به بیرون ارسال نمی‌شود</li>
    </ul>
    <form method="post" action="<?= Config::url('admin/2fa/enroll') ?>">
      <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
      <button class="btn btn-primary">شروع پیکربندی 2FA</button>
    </form>
  </div>

<?php elseif (!$enrolled): ?>
  <!-- ─────────── در انتظار تأیید اولین کد ─────────── -->
  <div class="card" style="max-width:720px">
    <div class="card-title">① برنامهٔ احرازگر را با این کد QR تنظیم کنید</div>
    <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start">
      <div style="background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px;line-height:0">
        <?= $pendingQr /* SVG درون‌خطی — تولید محلی، بدون سرویس خارجی */ ?>
      </div>
      <div style="flex:1;min-width:250px">
        <ol style="color:var(--muted);font-size:13.5px;line-height:2.3;padding-right:18px">
          <li>برنامهٔ <b>Google Authenticator</b> (یا Microsoft Authenticator / Aegis) را روی گوشی باز کنید</li>
          <li><b>+</b> ← «اسکن کد QR» را بزنید و این کد را اسکن کنید</li>
          <li>کد ۶ رقمی که برنامه نشان می‌دهد را در پایین همین صفحه وارد کنید</li>
        </ol>
        <div class="field">
          <label>ورود دستی (اگر نمی‌توانید اسکن کنید)</label>
          <input type="text" readonly dir="ltr" class="mono" onclick="this.select()"
                 value="<?= Helpers::e(trim(chunk_split($pendingSecret, 4, ' '))) ?>"
                 style="text-align:center;letter-spacing:1px">
          <div class="hint">در برنامه: <b>+</b> ← «کلید ورود راه‌اندازی» ← نام حساب را تایپ کنید و این کلید را وارد کنید.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card" style="max-width:720px;margin-top:16px">
    <div class="card-title">② اولین کد را تأیید کنید</div>
    <div class="alert info" style="padding:9px 12px">تا وقتی کد درست را تأیید نکنید، 2FA فعال <b>نمی‌شود</b> و ورود شما مثل قبل می‌ماند.</div>
    <form method="post" action="<?= Config::url('admin/2fa/activate') ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
      <div class="field" style="max-width:220px;flex:1">
        <label>کد ۶ رقمی برنامه</label>
        <input type="text" name="code" dir="ltr" inputmode="numeric" pattern="[0-9 ]{6,8}" maxlength="8"
               required autocomplete="one-time-code" autofocus placeholder="●●●●●●"
               style="text-align:center;letter-spacing:6px;font-size:20px">
      </div>
      <button class="btn btn-primary">تأیید و فعال‌سازی</button>
    </form>
  </div>

<?php else: ?>
  <!-- ─────────── فعال ─────────── -->
  <div class="card" style="max-width:720px">
    <div class="card-title">🛡️ ورود دومرحله‌ای — وضعیت</div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span class="badge ok">فعال است ✅</span>
      <span style="color:var(--muted);font-size:13px">از <?= Helpers::e($enabledAt) ?></span>
      <?php if ($enforced): ?><span class="chip">الزامی طبق سیاست حساب</span><?php endif; ?>
    </div>
    <p class="hint" style="margin-top:10px">
      هنگام ورود، بعد از رمز عبور کد ۶ رقمی برنامهٔ احرازگر از شما خواسته می‌شود.
      کدها آفلاین روی گوشی شما ساخته می‌شوند و به اینترنت یا سرویس بیرونی وابسته نیستند.
    </p>
  </div>

  <?php if ($newCodes !== null && trim($newCodes) !== ''): ?>
  <div class="card" style="max-width:720px;margin-top:16px;border:1.5px solid var(--brand)">
    <div class="card-title">🔑 کدهای بازیابی شما — فقط همین یک بار نمایش داده می‌شوند</div>
    <div class="alert warn" style="padding:9px 12px">
      این کدها را <b>الان</b> در جای امنی ذخیره کنید (دانلود/چاپ). هر کد فقط یک بار قابل استفاده است
      و پس از بازتولید، همهٔ کدهای قبلی باطل می‌شوند. در سرور فقط هش آنها ذخیره می‌شود — دیگر هرگز نشان داده نمی‌شوند.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px;margin:14px 0">
      <?php foreach (explode("\n", $newCodes) as $c): if (trim($c) === '') continue; ?>
        <div class="mono" dir="ltr" style="background:#f6f6fb;border:1px dashed var(--line);border-radius:10px;
             padding:9px 10px;text-align:center;font-size:14px;letter-spacing:1px"><?= Helpers::e(trim($c)) ?></div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-ghost" href="<?= Config::url('admin/2fa/codes.txt') ?>?t=<?= Helpers::e($csrf) ?>">⬇️ دانلود فایل متنی</a>
      <button class="btn btn-ghost" type="button" onclick="window.print()">🖨️ چاپ</button>
      <form method="post" action="<?= Config::url('admin/2fa/hide') ?>" style="margin:0">
        <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
        <button class="btn btn-primary">ذخیره کردم — پنهان کن</button>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="card" style="max-width:720px;margin-top:16px">
    <div class="card-title">🔑 کدهای بازیابی اضطراری</div>
    <p class="hint">
      اگر گوشی یا برنامهٔ احرازگر را از دست دادید، با یکی از این کدها (به‌همراه رمز عبور) می‌توانید وارد شوید.
      کدهای مصرف‌نشده: <b><?= (int) $recoveryRemaining ?></b> از <?= TwoFactor::RECOVERY_COUNT ?>
    </p>
    <form method="post" action="<?= Config::url('admin/2fa/recovery') ?>" data-confirm="کدهای بازیابی تازه ساخته شود؟ همهٔ کدهای قبلی فوراً باطل می‌شوند.">
      <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
      <div class="field" style="max-width:320px">
        <label>رمز عبور فعلی (تأیید هویت)</label>
        <input type="password" name="password" dir="ltr" required autocomplete="current-password">
      </div>
      <button class="btn btn-ghost">بازتولید کدهای بازیابی</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card" style="max-width:720px;margin-top:16px">
    <div class="card-title">📱 تعویض احرازگر (گوشی جدید)</div>
    <p class="hint">با تأیید رمز عبور و یک کد فعلی، راز تازه‌ای ساخته می‌شود؛ احرازگر جدید را با کد QR صفحهٔ بعد تنظیم و تأیید کنید.</p>
    <form method="post" action="<?= Config::url('admin/2fa/enroll') ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
      <div class="field" style="max-width:240px;flex:1">
        <label>رمز عبور فعلی</label>
        <input type="password" name="password" dir="ltr" required autocomplete="current-password">
      </div>
      <div class="field" style="max-width:180px;flex:1">
        <label>کد فعلی احرازگر</label>
        <input type="text" name="code" dir="ltr" inputmode="numeric" maxlength="8" required autocomplete="one-time-code"
               style="text-align:center;letter-spacing:4px" placeholder="●●●●●●">
      </div>
      <button class="btn btn-ghost">تعویض احرازگر</button>
    </form>
  </div>

  <div class="card" style="max-width:720px;margin-top:16px">
    <div class="card-title">خاموش کردن ورود دومرحله‌ای</div>
    <form method="post" action="<?= Config::url('admin/2fa/disable') ?>"
          data-confirm="واقعاً خاموش شود؟ از این پس فقط رمز عبور کافی خواهد بود.">
      <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div class="field" style="max-width:240px;flex:1">
          <label>رمز عبور فعلی</label>
          <input type="password" name="password" dir="ltr" required autocomplete="current-password">
        </div>
        <div class="field" style="max-width:180px;flex:1">
          <label>کد فعلی احرازگر</label>
          <input type="text" name="code" dir="ltr" inputmode="numeric" maxlength="8" required autocomplete="one-time-code"
                 style="text-align:center;letter-spacing:4px" placeholder="●●●●●●">
        </div>
        <button class="btn btn-danger">خاموش کردن 2FA</button>
      </div>
    </form>
  </div>
<?php endif; ?>
