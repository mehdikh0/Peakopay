<?php
/** Step 6: show WooCommerce + Android credentials ONCE. Expects: $creds, $backend_url */
$c = $creds;
?>
<div class="card">
  <div class="card-title">🔑 اطلاعات اتصال — فقط همین یک بار نمایش داده می‌شود!</div>
  <div class="alert warn">همه مقادیر زیر را در جای امنی (مثلاً نرم‌افزار مدیریت رمز) ذخیره کنید. پس از پایان نصب دیگر نمایش داده نمی‌شوند (قابل ساخت مجدد از تنظیمات پنل هستند).</div>

  <div class="field"><label>آدرس بک‌اند (Backend URL)</label>
    <div class="copy-row"><div class="code-box"><?= Helpers::e($backend_url) ?></div><button class="btn btn-ghost" data-copy="<?= Helpers::e($backend_url) ?>">کپی</button></div></div>
  <div class="field"><label>شناسه فروشگاه (Store ID)</label>
    <div class="copy-row"><div class="code-box"><?= Helpers::e($c['public_id']) ?></div><button class="btn btn-ghost" data-copy="<?= Helpers::e($c['public_id']) ?>">کپی</button></div></div>
  <div class="field"><label>کلید API (API Key)</label>
    <div class="copy-row"><div class="code-box"><?= Helpers::e($c['api_key']) ?></div><button class="btn btn-ghost" data-copy="<?= Helpers::e($c['api_key']) ?>">کپی</button></div></div>
  <div class="field"><label>راز Webhook (Webhook Secret)</label>
    <div class="copy-row"><div class="code-box"><?= Helpers::e($c['webhook_secret']) ?></div><button class="btn btn-ghost" data-copy="<?= Helpers::e($c['webhook_secret']) ?>">کپی</button></div></div>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-title">تنظیم ووکامرس (خلاصه — راهنمای کامل در فایل docs/WOOCOMMERCE.md)</div>
  <ol style="line-height:2.3;color:var(--muted);padding-inline-start:20px;margin:0">
    <li>افزونه <b>Peakopay Payment Gateway</b> را در وردپرس نصب و فعال کنید.</li>
    <li>ووکامرس → تنظیمات → پرداخت‌ها → <b>پیکو پی (کارت‌به‌کارت)</b>.</li>
    <li>چهار مقدار بالا را در فیلدهای «آدرس بک‌اند»، «شناسه فروشگاه»، «کلید API» و «راز Webhook» وارد کنید.</li>
    <li>دکمه «آزمون اتصال» را بزنید تا از صحت اتصال مطمئن شوید.</li>
  </ol>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-title">اتصال اپ اندروید (خلاصه — راهنمای کامل در docs/ANDROID.md)</div>
  <ol style="line-height:2.3;color:var(--muted);padding-inline-start:20px;margin:0">
    <li>اپ پیکو پی را روی گوشیِ دریافت پیامک‌های بانکی نصب کنید.</li>
    <li>در پنل مدیریت → دستگاه‌ها، «کد همراه‌سازی» بسازید.</li>
    <li>کد ۸ رقمی را در اپ وارد کنید (یا QR را اسکن کنید).</li>
    <li>از پنل، دستگاه را «تأیید» کنید — تمام!</li>
  </ol>
</div>

<form method="post" action="?step=6" style="margin-top:16px">
  <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
  <button class="btn btn-primary btn-block">اطلاعات را ذخیره کردم؛ پایان نصب →</button>
</form>
