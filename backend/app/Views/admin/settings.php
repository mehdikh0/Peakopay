<?php
/** Settings page. Expects: $s (settings array), $section, $sections, $apiKeyMask, $apiKeyUsed, $reinstall, $store */
$csrf = Session::csrfToken();
$s = $s ?? [];
$newKey = Session::pending('reveal_api_key');
$newSecret = Session::pending('reveal_webhook_secret');

$sectionTitles = [
    'general' => 'عمومی', 'payment' => 'پرداخت', 'paypage' => 'ظاهر صفحه پرداخت', 'matching' => 'تطبیق',
    'woocommerce' => 'ووکامرس', 'android' => 'اندروید', 'security' => 'امنیت',
    'notifications' => 'اطلاع‌رسانی', 'advanced' => 'پیشرفته',
];
$baseUrl = Config::baseUrl();
?>
<?php if (($user['role'] ?? '') === 'owner'): ?>
<div class="card" style="margin-bottom:14px;padding:12px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <strong style="font-size:.92em">فروشگاه‌ها و اعضای تیم</strong>
  <span style="color:#777;font-size:.85em">افزودن شعبهٔ جدید یا مدیریت دسترسی همکاران</span>
  <a class="btn btn-sm" href="<?= Config::url('admin/stores') ?>">فروشگاه‌ها</a>
  <a class="btn btn-sm" href="<?= Config::url('admin/team') ?>">تیم</a>
</div>
<?php endif; ?>
<div class="tabs">
  <?php foreach ($sections as $sec): ?>
    <a href="?section=<?= $sec ?>" class="<?= $section === $sec ? 'active' : '' ?>"><?= $sectionTitles[$sec] ?? $sec ?></a>
  <?php endforeach; ?>
</div>

<?php if ($newKey !== null || $newSecret !== null): ?>
<div class="card" style="border:1.5px solid var(--brand);margin-bottom:16px">
  <div class="card-title">🔑 مقدارهای جدید — تا وقتی «پنهان کن» نزنید همین‌جا می‌مانند</div>
  <?php if ($newKey !== null): ?>
  <div class="card-title" style="font-size:13px;margin-top:4px">کلید API (hpk_…)</div>
  <div class="copy-row"><div class="code-box"><?= Helpers::e($newKey) ?></div><button class="btn btn-ghost" data-copy="<?= Helpers::e($newKey) ?>">کپی</button></div>
  <p class="hint">این مقدار را در «تنظیمات افزونه ووکامرس ← کلید API» وارد کنید. کلید قبلی بلافاصله باطل شده است.</p>
  <?php endif; ?>
  <?php if ($newSecret !== null): ?>
  <div class="card-title" style="font-size:13px;margin-top:10px">راز Webhook (whsec_…)</div>
  <div class="copy-row"><div class="code-box"><?= Helpers::e($newSecret) ?></div><button class="btn btn-ghost" data-copy="<?= Helpers::e($newSecret) ?>">کپی</button></div>
  <p class="hint">این مقدار را در «تنظیمات افزونه ووکامرس ← کلید امضای وب‌هوک» وارد کنید.</p>
  <?php endif; ?>
  <form method="post" action="<?= Config::url('admin/settings/action') ?>" style="margin-top:10px">
    <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
    <input type="hidden" name="do" value="dismiss_reveal">
    <input type="hidden" name="back" value="<?= Config::url('admin/settings') ?>?section=woocommerce">
    <button type="submit" class="btn btn-ghost">پنهان کن</button>
    <span class="hint">بعد از پنهان‌کردن، این مقدار دیگر قابل مشاهده نیست (به‌صورت هش‌شده ذخیره شده است).</span>
  </form>
</div>
<?php endif; ?>

<?php if ($newSecret !== null): ?>
<div class="card" style="border:1.5px solid var(--brand);margin-bottom:16px">
  <div class="card-title">🔒 راز Webhook جدید — فقط همین یک بار نمایش داده می‌شود</div>
  <div class="copy-row"><div class="code-box"><?= Helpers::e($newSecret) ?></div><button class="btn btn-ghost" data-copy="<?= Helpers::e($newSecret) ?>">کپی</button></div>
  <p class="hint" style="color:var(--bad)">این مقدار را در تنظیمات افزونه ووکامرس (فیلد «راز Webhook») وارد کنید.</p>
</div>
<?php endif; ?>

<form method="post" action="<?= Config::url('admin/settings/save') ?>">
<input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
<input type="hidden" name="section" value="<?= Helpers::e($section) ?>">

<?php if ($section === 'general'): ?>
  <div class="card" style="max-width:720px">
    <div class="card-title">🏪 اطلاعات فروشگاه</div>
    <div class="field"><label>نام فروشگاه</label>
      <input type="text" name="store_name" required value="<?= Helpers::e($store['name'] ?? '') ?>"></div>
    <div class="field"><label>آدرس سایت فروشگاه</label>
      <input type="url" name="store_url" dir="ltr" value="<?= Helpers::e($store['url'] ?? '') ?>" placeholder="https://shop.example.com">
      <div class="hint">آدرس فروشگاه ووکامرس — برای ارسال رویداد پرداخت استفاده می‌شود.</div></div>
    <div class="form-row">
      <div class="field"><label>تلفن پشتیبانی (اختیاری)</label>
        <input type="text" name="support_phone" dir="ltr" value="<?= Helpers::e($store['support_phone'] ?? '') ?>" placeholder="0912..."></div>
      <div class="field"><label>تلگرام پشتیبانی (اختیاری)</label>
        <input type="text" name="support_telegram" dir="ltr" value="<?= Helpers::e($store['support_telegram'] ?? '') ?>" placeholder="@username"></div>
    </div>
    <div class="form-row">
      <div class="field"><label>واحد نمایش مبالغ در پنل</label>
        <select name="currency">
          <option value="IRT" <?= ($store['currency'] ?? '') === 'IRT' ? 'selected' : '' ?>>تومان</option>
          <option value="IRR" <?= ($store['currency'] ?? '') === 'IRR' ? 'selected' : '' ?>>ریال</option>
        </select>
        <div class="hint">سیستم همیشه با «ریال» کار می‌کند؛ این گزینه فقط نحوه نمایش است.</div></div>
      <div class="field"><label>منطقه زمانی</label>
        <select name="timezone">
          <?php foreach (['Asia/Tehran' => 'تهران (ایران)'] as $tz => $lbl): ?>
            <option value="<?= $tz ?>" <?= ($store['timezone'] ?? '') === $tz ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <button class="btn btn-primary">ذخیره</button>
  </div>

<?php elseif ($section === 'payment'): ?>
  <div class="card" style="max-width:720px">
    <div class="card-title">💳 تنظیمات پرداخت</div>
    <div class="form-row">
      <div class="field"><label>مدت اعتبار صفحه پرداخت (دقیقه)</label>
        <input type="number" name="payment_expiry_minutes" min="5" max="10080" required value="<?= Helpers::e($s['payment_expiry_minutes'] ?? '60') ?>">
        <div class="hint">پس از این مدت، پرداخت منقضی و سفارش طبق تنظیمات تطبیق، لغو/ناموفق می‌شود.</div></div>
      <div class="field"><label>تلورانس مبلغ تطبیق (ریال)</label>
        <input type="number" name="payment_tolerance_rial" min="0" required value="<?= Helpers::e($s['payment_tolerance_rial'] ?? '2000') ?>">
        <div class="hint">اگر مبلغ واریزی حداکثر به این اندازه با مبلغ سفارش اختلاف داشته باشد، باز هم تطبیق داده می‌شود (۰ = فقط تطبیق دقیق وقتی حالت تطبیق «دقیق» باشد).</div></div>
    </div>
    <div class="form-row">
      <div class="field"><label>حداقل مبلغ پرداخت (ریال)</label>
        <input type="number" name="min_amount_rial" min="0" required value="<?= Helpers::e($s['min_amount_rial'] ?? '20000') ?>"></div>
      <div class="field"><label>حداکثر مبلغ پرداخت (ریال)</label>
        <input type="number" name="max_amount_rial" min="1000" required value="<?= Helpers::e($s['max_amount_rial'] ?? '500000000') ?>"></div>
    </div>
    <div class="field">
      <label class="switch"><input type="checkbox" name="unique_amount" value="1" <?= ($s['unique_amount'] ?? '1') === '1' ? 'checked' : '' ?>><span class="track"></span></label>
      <div><b>مبلغ یکتا برای هر سفارش</b><div class="hint">برای جلوگیری از تداخل مشتریان هم‌زمان، ۳ رقم آخر مبلغ به‌صورت تصادفی بین ۱۰۰ تا ۹۹۹ ریال تغییر می‌کند (روی صفحه پرداخت به مشتری نشان داده می‌شود و شفاف است).</div></div>
    </div>
    <button class="btn btn-primary">ذخیره</button>
  </div>

<?php elseif ($section === 'paypage'): ?>
<div class="card">
  <h3>ظاهر صفحه پرداخت — CSS سفارشی</h3>
  <p class="hint">کد زیر بعد از استایل اصلی صفحه پرداخت اعمال می‌شود؛ هر قاعده‌ای بنویسید بر آن اولویت دارد. برای شروع می‌توانید کد پیش‌فرض را بارگذاری و همان‌طور که می‌خواهید تغییر دهید. با «ریست به پیش‌فرض» همه‌چیز به حالت اولیه برمی‌گردد. (فقط CSS — کد اجرایی پذیرفته نمی‌شود.)</p>
  <form method="post" action="<?= Config::url('admin/settings/save') ?>">
    <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf ?? '') ?>">
    <input type="hidden" name="section" value="paypage">
    <textarea name="custom_css" dir="ltr" class="inp mono" rows="16" style="font-size:12px;line-height:1.6;white-space:pre-wrap" spellcheck="false"><?php
      $curCss = (string) ($s['pay_custom_css'] ?? '');
      echo Helpers::e($loadDefault ? ($defaultCss ?? '') : $curCss);
    ?></textarea>
    <div class="row" style="margin-top:10px;gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" type="submit">ذخیره استایل سفارشی</button>
      <button class="btn" type="submit" name="do_reset" value="1">ریست به پیش‌فرض</button>
      <a class="btn btn-ghost" href="<?= Config::url('admin/settings') ?>?section=paypage&amp;load=default">بارگذاری کد پیش‌فرض (برای مشاهده/ویرایش)</a>
    </div>
  </form>
  <?php if ((string) ($s['pay_custom_css'] ?? '') !== ''): ?>
    <p class="hint" style="color:var(--ok)">استایل سفارشی فعال است.</p>
  <?php endif; ?>
</div>
<?php elseif ($section === 'matching'): ?>
  <div class="card" style="max-width:720px">
    <div class="card-title">🧩 تطبیق تراکنش با سفارش</div>
    <div class="form-row">
      <div class="field"><label>روش تطبیق مبلغ</label>
        <select name="match_mode">
          <option value="tolerance" <?= ($s['match_mode'] ?? '') === 'tolerance' ? 'selected' : '' ?>>با تلورانس (پیشنهادی)</option>
          <option value="exact" <?= ($s['match_mode'] ?? '') === 'exact' ? 'selected' : '' ?>>فقط مبلغ دقیق</option>
        </select></div>
      <div class="field"><label>بازه زمانی تطبیق (دقیقه)</label>
        <input type="number" name="match_window_minutes" min="30" required value="<?= Helpers::e($s['match_window_minutes'] ?? '1440') ?>">
        <div class="hint">فقط پرداخت‌هایی که در این بازه زمانی اخیر ساخته شده‌اند در تطبیق بررسی می‌شوند.</div></div>
    </div>
    <div class="field">
      <label class="switch"><input type="checkbox" name="match_by_card" value="1" <?= ($s['match_by_card'] ?? '1') === '1' ? 'checked' : '' ?>><span class="track"></span></label>
      <div><b>تطبیق بر اساس کارت مقصد</b><div class="hint">اگر پیامک بانک شماره کارت مقصد را داشته باشد، فقط پرداخت‌های همان کارت تطبیق می‌شوند.</div></div>
    </div>
    <div class="field">
      <label class="switch"><input type="checkbox" name="match_by_reference" value="1" <?= ($s['match_by_reference'] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span></label>
      <div><b>تطبیق بر اساس کد مرجع</b><div class="hint">فقط وقتی فعال کنید که از مشتری بخواهید کد پیگیری خاصی را در توضیحات انتقال درج کند.</div></div>
    </div>
    <div class="field">
      <label class="switch"><input type="checkbox" name="auto_confirm" value="1" <?= ($s['auto_confirm'] ?? '1') === '1' ? 'checked' : '' ?>><span class="track"></span></label>
      <div><b>تأیید خودکار پرداخت</b><div class="hint">در صورت فعال بودن، با تطبیق تراکنش، سفارش فوراً پرداخت‌شده می‌شود؛ در صورت غیرفعال بودن، پرداخت به حالت «در بررسی» می‌رود تا شما دستی تأیید کنید.</div></div>
    </div>
    <div class="form-row">
      <div class="field"><label>وضعیت سفارش پس از پرداخت موفق</label>
        <select name="paid_order_status">
          <option value="processing" <?= ($s['paid_order_status'] ?? '') === 'processing' ? 'selected' : '' ?>>در حال انجام (processing)</option>
          <option value="completed" <?= ($s['paid_order_status'] ?? '') === 'completed' ? 'selected' : '' ?>>تکمیل‌شده (completed)</option>
        </select></div>
      <div class="field"><label>وضعیت سفارش پس از انقضای پرداخت</label>
        <select name="expired_order_status">
          <option value="cancelled" <?= ($s['expired_order_status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>لغوشده (cancelled)</option>
          <option value="failed" <?= ($s['expired_order_status'] ?? '') === 'failed' ? 'selected' : '' ?>>ناموفق (failed)</option>
          <option value="pending" <?= ($s['expired_order_status'] ?? '') === 'pending' ? 'selected' : '' ?>>بدون تغییر</option>
        </select></div>
    </div>
    <button class="btn btn-primary">ذخیره</button>
  </div>

<?php elseif ($section === 'woocommerce'): ?>
  <div class="card" style="max-width:720px">
    <div class="card-title">🔌 اتصال ووکامرس</div>
    <div class="alert info">این مقادیر را در ووکامرس → تنظیمات → پرداخت → پیکو پی وارد کنید.</div>
    <div class="field"><label>آدرس بک‌اند (Backend URL)</label>
      <div class="copy-row"><div class="code-box"><?= Helpers::e($baseUrl) ?></div><button type="button" class="btn btn-ghost" data-copy="<?= Helpers::e($baseUrl) ?>">کپی</button></div></div>
    <div class="field"><label>شناسه فروشگاه (Store ID)</label>
      <div class="copy-row"><div class="code-box"><?= Helpers::e($store['public_id'] ?? '') ?></div><button type="button" class="btn btn-ghost" data-copy="<?= Helpers::e($store['public_id'] ?? '') ?>">کپی</button></div></div>
    <div class="field"><label>آدرس فروشگاه ووکامرس</label>
      <input type="url" name="woo_site_url" dir="ltr" value="<?= Helpers::e($s['woo_site_url'] ?? '') ?>" placeholder="https://shop.example.com">
      <div class="hint">رویداد پرداخت به آدرس <span class="mono">/wc-api/peakopay</span> همین سایت ارسال می‌شود.</div></div>
    <div class="field">
      <label class="switch"><input type="checkbox" name="woo_enabled" value="1" <?= ($s['woo_enabled'] ?? '1') === '1' ? 'checked' : '' ?>><span class="track"></span></label>
      <div><b>ارسال رویداد به ووکامرس</b><div class="hint">در صورت خاموش بودن، پرداخت‌ها در پنل تأیید می‌شوند اما سفارش ووکامرس تغییر نمی‌کند.</div></div>
    </div>
    <div class="field"><label>کلید API فعال</label>
      <div class="copy-row"><div class="code-box"><?= Helpers::e($apiKeyMask ?? '—') ?></div></div>
      <div class="hint">آخرین استفاده: <?= $apiKeyUsed !== null ? Helpers::ago($apiKeyUsed) : 'هنوز استفاده نشده' ?> — مقدار کامل کلید فقط هنگام ساخت نمایش داده می‌شود.</div></div>
    <div class="field"><label>راز Webhook</label>
      <div class="hint">مقدار کامل نمایش داده نمی‌شود (امنیت!). برای ساخت مقدار جدید از دکمه زیر استفاده کنید و در افزونه وارد کنید.</div></div>
    <button class="btn btn-primary">ذخیره</button>
  </div>
  <div class="card" style="max-width:720px;margin-top:16px">
    <div class="card-title">بازتولید اعتبارنامه‌ها</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button type="button" class="btn btn-ghost js-regen" data-do="regen_api_key" data-back="?section=woocommerce" data-csrf="<?= Helpers::e($csrf) ?>">🔑 کلید API جدید</button>
      <button type="button" class="btn btn-ghost js-regen" data-do="regen_webhook_secret" data-back="?section=woocommerce" data-csrf="<?= Helpers::e($csrf) ?>">🔒 راز Webhook جدید</button>
    </div>
    <p class="hint">هشدار: مقدار قبلی بلافاصله باطل می‌شود و باید افزونه ووکامرس را به‌روز کنید.</p>
  </div>

<?php elseif ($section === 'android'): ?>
  <div class="card" style="max-width:720px">
    <div class="card-title">📱 دستگاه‌های اندروید</div>
    <div class="kv">
      <div class="k">مدیریت دستگاه‌ها</div><div><a class="btn btn-ghost btn-sm" href="<?= Config::url('admin/devices') ?>">رفتن به صفحه دستگاه‌ها</a></div>
      <div class="k">ساخت کد همراه‌سازی</div><div>از صفحه دستگاه‌ها، کد ۸ رقمی با اعتبار ۱۰ دقیقه بسازید.</div>
      <div class="k">روند تأیید</div><div>دستگاه جدید ابتدا «در انتظار تأیید» است و تا تأیید شما نمی‌تواند تراکنش ارسال کند.</div>
      <div class="k">لغو دسترسی</div><div>با «لغو دسترسی»، گوشی فوراً از ارسال تراکنش محروم می‌شود (حتی با داشتن اعتبارنامه).</div>
    </div>
  </div>

<?php elseif ($section === 'security'): ?>
  <div class="card" style="max-width:720px">
    <div class="card-title">🛡️ امنیت</div>
    <div class="alert info" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <span><b>ورود دومرحله‌ای (2FA)</b> — کد ۶ رقمی از برنامهٔ احرازگر گوشی، کاملاً آفلاین.</span>
      <a class="btn btn-primary btn-sm" href="<?= Config::url('admin/2fa') ?>">پیکربندی 2FA</a>
    </div>
    <div class="form-row">
      <div class="field"><label>مهلت نشست مدیریت (دقیقه)</label>
        <input type="number" name="session_timeout_minutes" min="10" max="1440" required value="<?= Helpers::e($s['session_timeout_minutes'] ?? '240') ?>"></div>
      <div class="field"><label>حداکثر تلاش ورود (در ۱۵ دقیقه)</label>
        <input type="number" name="login_max_attempts" min="3" max="20" required value="<?= Helpers::e($s['login_max_attempts'] ?? '5') ?>"></div>
    </div>
    <?php if (($user['role'] ?? '') === 'owner'): ?>
    <div class="field">
      <label class="switch"><input type="checkbox" name="twofactor_required_admins" value="1" <?= ($s['twofactor_required_admins'] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span></label>
      <div><b>الزام ورود دومرحله‌ای برای مدیران</b>
        <div class="hint warn">وقتی روشن باشد، کاربران با نقش «مالک» و «مدیر» تا وقتی 2FA فعال نکرده باشند فقط صفحهٔ پیکربندی را می‌بینند (قفل نمی‌شوند — هر بار که وارد شوند می‌توانند پیکربندی کنند). اعضای دیگر تغییری نمی‌کنند. پیش‌فرض خاموش است.</div></div>
    </div>
    <?php endif; ?>
    <button class="btn btn-primary">ذخیره</button>
  </div>
  <div class="card" style="max-width:720px;margin-top:16px">
    <div class="card-title">تغییر رمز عبور</div>
    <form method="post" action="<?= Config::url('admin/password') ?>" style="margin:0">
      <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
      <div class="field"><label>رمز عبور فعلی</label><input type="password" name="current_password" dir="ltr" required></div>
      <div class="form-row">
        <div class="field"><label>رمز عبور جدید</label><input type="password" name="new_password" dir="ltr" required minlength="8"></div>
        <div class="field"><label>تکرار رمز جدید</label><input type="password" name="new_password2" dir="ltr" required minlength="8"></div>
      </div>
      <button class="btn btn-ghost">تغییر رمز عبور</button>
    </form>
  </div>
  <div class="card" style="max-width:720px;margin-top:16px">
    <div class="card-title">حالت‌های حساس</div>
    <p class="hint">نصب‌کننده وب در حالت عادی قفل است. اگر لازم است نصب را دوباره اجرا کنید (مثلاً برای تغییر دیتابیس)، می‌توانید به مدت ۳۰ دقیقه قفل را باز کنید. همه داده‌ها سر جایشان می‌مانند اما مراحل نصب دوباره قابل اجرا خواهند بود.</p>
    <form method="post" action="<?= Config::url('admin/settings/action') ?>" data-confirm="قفل نصب‌کننده برای ۳۰ دقیقه باز شود؟">
      <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
      <input type="hidden" name="do" value="open_reinstall">
      <input type="hidden" name="back" value="<?= Config::url('admin/settings') ?>?section=security">
      <button class="btn btn-danger">باز کردن قفل نصب‌کننده (۳۰ دقیقه)</button>
    </form>
  </div>

<?php elseif ($section === 'notifications'): ?>
  <div class="card" style="max-width:720px">
    <div class="card-title">🔔 اطلاع‌رسانی</div>
    <div class="field">
      <label class="switch"><input type="checkbox" name="notify_email_enabled" value="1" <?= ($s['notify_email_enabled'] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span></label>
      <div><b>اطلاع‌رسانی ایمیلی</b></div>
    </div>
    <div class="field"><label>ایمیل دریافت‌کننده</label>
      <input type="email" name="notify_email" dir="ltr" value="<?= Helpers::e($s['notify_email'] ?? '') ?>"></div>
    <div class="field">
      <label class="switch"><input type="checkbox" name="notify_telegram_enabled" value="1" <?= ($s['notify_telegram_enabled'] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span></label>
      <div><b>اطلاع‌رسانی تلگرام</b></div>
    </div>
    <div class="form-row">
      <div class="field"><label>توکن ربات تلگرام</label>
        <input type="text" name="telegram_bot_token" dir="ltr" placeholder="123456:ABC-..." value="">
        <div class="hint"><?= ($s['telegram_bot_token'] ?? '') !== '' ? 'ذخیره شده (برای تغییر، مقدار جدید وارد کنید)' : 'از @BotFather بگیرید' ?></div></div>
      <div class="field"><label>شناسه چت</label>
        <input type="text" name="telegram_chat_id" dir="ltr" value="<?= Helpers::e($s['telegram_chat_id'] ?? '') ?>" placeholder="123456789"></div>
    </div>
    <div class="field"><b>رویدادها</b>
      <div style="display:grid;gap:2px">
        <label class="switch"><input type="checkbox" name="notify_event_paid" value="1" <?= ($s['notify_event_paid'] ?? '1') === '1' ? 'checked' : '' ?>><span class="track"></span>پرداخت موفق</label>
        <label class="switch"><input type="checkbox" name="notify_event_unmatched" value="1" <?= ($s['notify_event_unmatched'] ?? '1') === '1' ? 'checked' : '' ?>><span class="track"></span>تراکنش بدون تطبیق</label>
        <label class="switch"><input type="checkbox" name="notify_event_device" value="1" <?= ($s['notify_event_device'] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span>دستگاه جدید</label>
      </div>
    </div>
    <button class="btn btn-primary">ذخیره</button>
  </div>

<?php elseif ($section === 'advanced'): ?>
  <div class="card" style="max-width:720px">
    <div class="card-title">⚙️ پیشرفته</div>
    <div class="field">
      <label class="switch"><input type="checkbox" name="test_mode" value="1" <?= ($s['test_mode'] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span></label>
      <div><b>حالت تست</b><div class="hint warn">در این حالت می‌توانید از صفحه «ابزار تست» پرداخت آزمایشی بسازید و پیامک بانکی را شبیه‌سازی کنید. تراکنش‌های تست با برچسب «تست» جدا می‌شوند و با پرداخت واقعی اشتباه نمی‌شوند.</div></div>
    </div>
    <div class="field"><label>قواعد سفارشی پیامک (JSON — اختیاری)</label>
      <textarea name="sms_rules" placeholder='{"rules":[ … ]}'><?= Helpers::e($s['sms_rules'] ?? '') ?></textarea>
      <div class="hint">قالب قواعد مثل فایل <span class="mono">config/sms-rules.json</span> است و فقط برای شبیه‌ساز وب استفاده می‌شود؛ اپ اندروید قواعد خود را داخل خود دارد.</div></div>
    <button class="btn btn-primary">ذخیره</button>
  </div>

  <div class="card" style="max-width:720px">
    <div class="card-title">⏰ کران نگهداری (cPanel — اختیاری)</div>
    <div class="alert info">تطبیق و تأیید پرداخت‌ها <b>هرگز</b> به کران وابسته نیست و همان لحظه انجام می‌شود. این کران فقط کارهای نگهداری است: انقضای پرداخت‌های سرآمده، پاک‌سازی داده‌های قدیمی و لاگ‌ها. توصیه: هر ۱۵ دقیقه.</div>
    <div class="field"><label>دستور برای cPanel ← Cron Jobs</label>
      <input type="text" dir="ltr" readonly onclick="this.select()" class="mono" value="php -q <?= Helpers::e(HP_BASE) ?>/cron.php">
      <div class="hint">در cPanel ← Cron Jobs ← «Add New Cron Job» ← Common Settings: <b>Every 15 minutes</b> ← دستور بالا را کپی کنید.</div></div>
    <?php $cronTokenShown = $cronToken ?? ($s['cron_token'] ?? ''); ?>
    <?php if (!empty($cronTokenShown)): ?>
    <div class="field"><label>آدرس جایگزین HTTP (اگر cron سیستومی ندارید)</label>
      <input type="text" dir="ltr" readonly onclick="this.select()" class="mono" value="<?= Helpers::e(Config::url('cron.php') . '?token=' . $cronTokenShown) ?>">
      <div class="hint">می‌توانید این آدرس را در هر سرویس کران آنلاین (مثل cron-job.org) با فاصله ۱۵ دقیقه بگذارید.</div></div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</form>

<script nonce="<?= Helpers::e(Http::cspNonce()) ?>">
document.querySelectorAll('.js-regen').forEach(function (b) {
  b.addEventListener('click', function () {
    if (!window.confirm('مقدار جدید ساخته شود؟ مقدار قبلی فوراً باطل می‌شود.')) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.action = '<?= Config::url('admin/settings/action') ?>';
    f.innerHTML = '<input name="_csrf" value="' + b.dataset.csrf + '">' +
      '<input name="do" value="' + b.dataset.do + '">' +
      '<input name="back" value="<?= Config::url('admin/settings') ?>' + b.dataset.back + '">';
    document.body.appendChild(f);
    f.submit();
  });
});
</script>
