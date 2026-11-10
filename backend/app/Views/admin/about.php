<?php /** About page */ ?>
<div class="card" style="max-width:640px">
  <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
    <span class="logo" style="width:56px;height:56px;border-radius:18px;background:linear-gradient(135deg,var(--brand),var(--brand-2));display:grid;place-items:center;color:#fff;font-size:22px">هـ</span>
    <div>
      <h2 style="margin:0">پیکو پی</h2>
      <div style="color:var(--muted)">سامانه پرداخت کارت‌به‌کارت ووکامرس</div>
    </div>
  </div>
  <div class="kv">
    <div class="k">نسخه</div><div><?= Helpers::faDigits(HP_VERSION) ?></div>
    <div class="k">رمزنگاری</div><div><?= Crypto::hasSodium() ? 'Sodium (XSalsa20-Poly1305)' : 'OpenSSL AES-256-GCM' ?></div>
    <div class="k">PHP</div><div class="mono"><?= PHP_VERSION ?></div>
    <div class="k">مسیر مستندات</div><div>پوشه <span class="mono">docs/</span> در بسته نصب (راهنمای cPanel، ووکامرس، اندروید، عیب‌یابی و امنیت)</div>
  </div>
  <div class="alert info" style="margin-top:16px">
    جریان کار: مشتری سفارش می‌سازد → صفحه پرداخت پیکو پی → واریز به کارت شما → اپ اندروید پیامک واریز را تشخیص می‌دهد → تطبیق خودکار → سفارش در ووکامرس پرداخت‌شده می‌شود — همه بدون کرون و بدون درگاه بانکی.
  </div>
</div>
