<?php
/** Payment page. Expects: $data (from PayController::viewData) */
$d = $data;
$isPaid = $d['status'] === 'paid';
$isExpired = $d['status'] === 'expired';
$isFailed = $d['status'] === 'failed';
$isVerifying = $d['status'] === 'verifying';
$isOpen = !$isPaid && !$isExpired && !$isFailed;
$card = $d['card'] ?? null;
?>
<div class="hp-page" data-token="<?= Helpers::e($d['token']) ?>" data-status="<?= Helpers::e($d['status']) ?>" data-expires-in="<?= (int)$d['expires_in'] ?>">

  <header class="hp-head">
    <div class="hp-brand">
      <span class="hp-logo"><img src="<?= Helpers::asset('img/logo.png') ?>" alt="پیکو پی"></span>
      <div>
        <b>فروشگاه <?= Helpers::e($d['store']['name'] ?? '') ?></b>
        <small>درگاه پرداخت کارت‌به‌کارت پیکو پی</small>
      </div>
    </div>
    <div class="hp-head-side">
      <?php if ($d['test']): ?><div class="hp-test">حالت تست</div><?php endif; ?>
      <?php if ($isOpen): ?>
      <div class="hp-timer-pill" id="timer" title="زمان باقی‌مانده">
        <span class="hp-tp-ico">⏱</span>
        <b class="mono" dir="ltr"><span id="mm">--</span>:<span id="ss">--</span></b>
      </div>
      <?php endif; ?>
    </div>
  </header>
  <?php if ($isOpen): ?><div class="hp-count-bar"><i id="countBar"></i></div><?php endif; ?>

  <main class="hp-main">

    <!-- status -->
    <section class="hp-status st-<?= Helpers::e($d['status']) ?>" id="statusBanner">
      <div class="hp-st-icon" id="statusIcon">
        <?php if ($isPaid): ?>✓
        <?php elseif ($isExpired): ?>⏱
        <?php elseif ($isFailed): ?>✕
        <?php elseif ($isVerifying): ?><span class="spinner"></span>
        <?php else: ?><span class="pulse"></span><?php endif; ?>
      </div>
      <div class="hp-st-txt">
        <b id="statusTitle">
          <?php
          if ($isPaid) echo 'پرداخت شما تأیید شد';
          elseif ($isExpired) echo 'زمان پرداخت به پایان رسید';
          elseif ($isFailed) echo 'پرداخت ناموفق بود';
          elseif ($isVerifying) echo 'در حال بررسی پرداخت…';
          else echo 'در انتظار پرداخت';
          ?></b>
        <span id="statusText">
          <?php
          if ($isPaid) echo 'سفارش شما با موفقیت ثبت شد. از خرید شما سپاسگزاریم.';
          elseif ($isExpired) echo 'مهلت واریز تمام شد. در صورت واریز، مبلغ تا سقف ۷۲ ساعت بازگشت داده می‌شود؛ برای سفارش مجدد دوباره اقدام کنید.';
          elseif ($isFailed) echo 'این نشست پرداخت ناموفق است. با پشتیبانی فروشگاه تماس بگیرید.';
          elseif ($isVerifying) echo 'اعلام پرداخت ثبت شد و در حال بررسی خودکار واریز هستیم. این صفحه را نبندید.';
          else echo 'مبلغ دقیق را به کارت زیر واریز کنید؛ تأیید خودکار است.';
          ?></span>
      </div>
    </section>

    <?php if ($isPaid && $d['transaction'] !== null): ?>
    <section class="hp-card hp-paid-meta">
      <?php if ($d['transaction']['reference'] !== ''): ?>
      <div class="hp-kv"><span>کد پیگیری واریز</span><b class="mono"><?= Helpers::faDigits($d['transaction']['reference']) ?></b></div>
      <?php endif; ?>
      <?php if ($d['transaction']['bank'] !== ''): ?>
      <div class="hp-kv"><span>بانک واریزکننده</span><b><?= Helpers::e($d['transaction']['bank']) ?></b></div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($isOpen): ?>
    <!-- amount -->
    <section class="hp-card hp-amount">
      <div class="hp-card-head">
        <span>مبلغ قابل پرداخت</span>
        <span class="hp-head-tools">
          <span class="hp-chip">ریال</span>
          <button class="hp-btn hp-btn-ghost hp-btn-xs" data-copy="<?= (string)$d['amount_rial'] ?>" data-copy-ok="کپی شد ✓">⧉ کپی مبلغ</button>
        </span>
      </div>
      <div class="hp-amount-row">
        <div class="hp-amount-num mono" id="amountNum"><?= Helpers::faDigits(number_format($d['amount_rial'])) ?></div>
        <div class="hp-amount-alt">≈ <b class="mono"><?= Helpers::faDigits(number_format($d['amount_toman'])) ?></b> تومان</div>
      </div>
      <?php if ($d['adjusted']): ?>
      <div class="hp-note">برای یکتاسازی مبلغ، ۳ رقم آخر اندکی متفاوت است — دقیقاً همین مبلغ را واریز کنید.</div>
      <?php endif; ?>
    </section>

    <!-- destination card -->
    <section class="hp-card hp-card-dest">
      <div class="hp-card-head">
        <span>واریز به این کارت</span>
        <span class="hp-chip chip-bank"><?= Helpers::e($card['bank'] ?? '') ?></span>
      </div>
      <div class="hp-dest-top">
        <span class="hp-dest-holder"><?= Helpers::e($card['holder'] ?? '') ?></span>
        <span class="hp-dest-order">سفارش <b class="mono"><?= Helpers::faDigits($d['order_id'] ?: '—') ?></b></span>
      </div>
      <div class="hp-card-num mono" dir="ltr" id="cardNum"><?= Helpers::e($card['number_html'] ?? '') ?></div>
      <button class="hp-btn hp-btn-primary" data-copy="<?= preg_replace('#\D#', '', (string)($card['number'] ?? '')) ?>" data-copy-ok="شماره کارت کپی شد ✓">⧉ کپی شماره کارت</button>
      <?php if (($card['iban'] ?? '') !== ''): ?>
      <div class="hp-iban">
        <div class="hp-iban-row">
          <span>شبا (ساعات بانکی)</span>
          <span class="hp-iban-val"><b class="mono" dir="ltr"><?= Helpers::e($card['iban']) ?></b>
          <button class="hp-btn hp-btn-ghost hp-btn-xs" data-copy="<?= Helpers::e($card['iban']) ?>" data-copy-ok="کپی شد ✓">کپی</button></span>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <!-- guide (one compact line) -->
    <section class="hp-guide">
      <div class="hp-card-head"><span>راهنمای پرداخت</span></div>
      <p>مبلغ <b>دقیق</b> را واریز کنید ← دکمه «پرداخت کردم» را بزنید ← تأیید خودکار انجام می‌شود.</p>
    </section>

    <button class="hp-btn hp-btn-claim" id="claimBtn">✓ پرداخت کردم</button>
    <?php endif; ?>

    <?php if ($isPaid && $d['redirect_url'] !== ''): ?>
    <a class="hp-btn hp-btn-primary hp-btn-big" id="backBtn" href="<?= Helpers::e($d['redirect_url']) ?>">بازگشت به فروشگاه ←</a>
    <?php endif; ?>

    <?php if ($isExpired || $isFailed): ?>
    <?php if ($d['redirect_url'] !== ''): ?>
    <a class="hp-btn hp-btn-ghost hp-btn-big" href="<?= Helpers::e($d['redirect_url']) ?>">بازگشت به فروشگاه ←</a>
    <?php endif; ?>
    <?php endif; ?>
  </main>

  <footer class="hp-foot">
    <?php if (($d['store']['phone'] ?? '') !== ''): ?><span>پشتیبانی: <b class="mono"><?= Helpers::e($d['store']['phone']) ?></b></span><?php endif; ?>
    <?php if (($d['store']['telegram'] ?? '') !== ''): ?><span>تلگرام: <b><?= Helpers::e($d['store']['telegram']) ?></b></span><?php endif; ?>
    <span>قدرت‌گرفته از <b>پیکو پی</b></span>
  </footer>
</div>
