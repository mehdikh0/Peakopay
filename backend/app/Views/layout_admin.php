<?php
/** Admin layout. Expects: $content, $title, $activeNav, $user, $store */
$user = $user ?? null;
$store = $store ?? Store::current();
$nav = [
    'dashboard'    => ['داشبورد', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12h7V3H3v9Zm0 9h7v-6H3v6Zm11 0h7V12h-7v9Zm0-15v6h7V3h-7Z" stroke-linejoin="round"/></svg>'],
    'payments'     => ['پرداخت‌ها', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 10h19" stroke-linecap="round"/><path d="M6 15h4" stroke-linecap="round"/></svg>'],
    'transactions' => ['تراکنش‌ها', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 7h13m0 0-3.5-3.5M20 7l-3.5 3.5M17 17H4m0 0 3.5 3.5M4 17l3.5-3.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'],
    'devices'      => ['دستگاه‌ها', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2" stroke-linecap="round"/></svg>'],
    'stores'       => ['فروشگاه‌ها', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 9.5 5.5 4h13L20 9.5M4 9.5a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0M4 9.5V20h16V9.5M9.5 20v-5h5v5" stroke-linejoin="round"/></svg>'],
    'team'         => ['تیم', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8.5" r="3"/><path d="M3.5 19.5c.6-3 2.8-4.5 5.5-4.5s4.9 1.5 5.5 4.5M16 6a3 3 0 0 1 0 5.5M17.5 15.2c1.8.6 2.9 1.9 3.3 4" stroke-linecap="round"/></svg>'],
    'cards'        => ['کارت‌ها', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M6 10h2m3 0h2m3 0h2M6 14h12" stroke-linecap="round"/></svg>'],
    'settings'     => ['تنظیمات', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3.2"/><path d="M12 2.8v3M12 18.2v3M21.2 12h-3M5.8 12h-3M18.5 5.5l-2.1 2.1M7.6 16.4l-2.1 2.1M18.5 18.5l-2.1-2.1M7.6 7.6 5.5 5.5" stroke-linecap="round"/></svg>'],
    'testtools'    => ['ابزار تست', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 3.5h6M10 3.5v5.2L4.8 17a3 3 0 0 0 2.6 4.5h9.2a3 3 0 0 0 2.6-4.5L14 8.7V3.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.5 14.5h9" stroke-linecap="round"/></svg>'],
    'diagnostics'  => ['وضعیت اتصال‌ها', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 13.5 9 8l4 4 8-7.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 4.5h-4.5M21 4.5V9" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 21h18" stroke-linecap="round"/></svg>'],
    'logs'         => ['گزارش‌ها', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 3.5h12v17H6z" stroke-linejoin="round"/><path d="M9 8h6M9 12h6M9 16h3" stroke-linecap="round"/></svg>'],
    'about'        => ['درباره', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9.2"/><path d="M12 10.5v6" stroke-linecap="round"/><circle cx="12" cy="7.4" r=".4" fill="currentColor"/></svg>'],
];
$activeNav = $activeNav ?? '';
// آیتم‌های فقط از منو مخفی‌شده (دور ۲۲): مدیریت کارت‌ها به اپ اندروید منتقل شد،
// بنابراین آیتم «کارت‌ها» از منو حذف شد — صرفاً نمایشی. صفحه و همه مسیرهای
// /admin/cards سر جایشان هستند (لینک مستقیم همچنان کار می‌کند) تا هیچ قابلیتی
// نشکند و تست‌ها/ابزارها سالم بمانند. برای بازگرداندن منو، 'cards' را از این
// فهرست حذف کنید.
$navHidden = ['cards'];
// دور ۲۳ — چندفروشگاهی: «فروشگاه‌ها» و «تیم» فقط برای مالکِ بیش از یک فروشگاه
// (تک‌فروشگاهی‌ها رابط ساده می‌بینند؛ مسیر رشد از تنظیمات ← فروشگاه‌هاست).
$userStores = [];
try {
    if ($user !== null && !empty($user['id'])) {
        $userStores = StoreMember::storesOfUser((int) $user['id']);
    }
} catch (Throwable) {
}
$navVisibleIf = [
    'stores' => static fn(array $u): bool => ($u['role'] ?? '') === 'owner' && count($userStores) > 1,
    'team'   => static fn(array $u): bool => ($u['role'] ?? '') === 'owner' && count($userStores) > 1,
];
$wooOk = false;
try { $wooOk = Setting::get('woo_site_url') !== '' && ApiKey::maskedActive((int)($store['id'] ?? 0)) !== null; } catch (Throwable) {}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= Helpers::e($title ?? 'پنل مدیریت') ?> — پیکو پی</title>
<link rel="stylesheet" href="<?= Helpers::asset('css/admin.css') ?>">
<link rel="icon" href="<?= Helpers::asset('img/favicon.png') ?>">
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="logo"><img src="<?= Helpers::asset('img/logo.png') ?>" alt="پیکو پی" style="height:26px;width:26px;vertical-align:middle"></span>
      <div>پیکو پی<small>سامانه پرداخت کارت‌به‌کارت</small></div>
    </div>
    <nav class="nav">
      <?php foreach ($nav as $key => [$label, $icon]): ?>
        <?php if (in_array($key, $navHidden, true)) continue; ?>
        <?php if (isset($navVisibleIf[$key]) && $user !== null && !$navVisibleIf[$key]($user)) continue; ?>
        <a href="<?= Config::url('admin' . ($key === 'dashboard' ? '' : '/' . $key)) ?>" class="<?= $activeNav === $key ? 'active' : '' ?>">
          <?= $icon ?><span><?= $label ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      نسخه <?= Helpers::faDigits(HP_VERSION) ?>
      <?php if (!empty($store['name'])): ?><br>فروشگاه: <?= Helpers::e($store['name']) ?><?php endif; ?>
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="main">
    <header class="topbar">
      <button class="menu-btn" id="menuBtn" aria-label="منو">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round"/></svg>
      </button>
      <h1><?= Helpers::e($title ?? '') ?></h1>
      <div class="spacer"></div>
      <div class="topbar-actions">
        <?php
        // دور ۲۳ — سوییچر فروشگاه (فقط وقتی بیش از یک فروشگاه در دسترس است)
        $switchStores = [];
        foreach (array_keys($userStores) as $sid) {
            $srow = Store::get((int) $sid);
            if ($srow !== null) {
                $switchStores[] = $srow;
            }
        }
        ?>
        <?php if (count($switchStores) > 1): ?>
        <form method="post" action="<?= Config::url('admin/stores/switch') ?>" class="store-switch">
          <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
          <select name="store_id" onchange="this.form.submit()" aria-label="فروشگاه فعال">
            <?php $curId = (int) ($store['id'] ?? 0); foreach ($switchStores as $sw): ?>
              <option value="<?= (int) $sw['id'] ?>" <?= (int) $sw['id'] === $curId ? 'selected' : '' ?>>
                🏬 <?= Helpers::e($sw['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
        <span class="chip woo-chip"><span class="dot <?= $wooOk ? '' : 'warn' ?>"></span>ووکامرس <?= $wooOk ? 'متصل' : 'غیرفعال' ?></span>
        <?php
        // دور ۲۹ — وضعیت ورود دومرحله‌ای در نوار بالا (لینک به پیکربندی)
        $tfOk = false;
        try {
            $tfOk = $user !== null && !empty($user['id']) && TwoFactor::isEnabled((int) $user['id']);
        } catch (Throwable) {
        }
        ?>
        <a href="<?= Config::url('admin/2fa') ?>" class="chip woo-chip" style="text-decoration:none" title="امنیت دو مرحله‌ای">
          <span class="dot <?= $tfOk ? '' : 'warn' ?>"></span>2FA <?= $tfOk ? 'فعال' : 'خاموش' ?>
        </a>
        <span class="chip user-chip"><?= Helpers::e($user['name'] ?? '') ?></span>
        <a class="btn btn-ghost btn-sm" href="<?= Config::url('admin/logout') ?>">خروج</a>
      </div>
    </header>

    <main class="content">
      <?php foreach (['success', 'error'] as $fl): $msg = Session::flash($fl); if ($msg !== null && $msg !== ''): ?>
        <div class="flash <?= $fl ?>"><?= Helpers::e($msg) ?></div>
      <?php endif; endforeach; ?>
<?php
// دور ۲۹ — اعلان درون‌پنل «2FA شما توسط مدیر بازنشانی شد» (به‌جای ایمیل):
// تا کاربر آن را ببیند یا تأیید کند، در همهٔ صفحات پنل می‌ماند.
$resetBanner = null;
try {
    $resetBanner = $user !== null && !empty($user['id']) ? TwoFactor::unseenReset((int) $user['id']) : null;
} catch (Throwable) {
}
?>
<?php if ($resetBanner !== null): ?>
      <div class="flash error" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <span>🔐 <b>ورود دومرحله‌ای شما توسط مدیر (<?= Helpers::e($resetBanner['admin_name'] ?? 'مدیر') ?>) بازنشانی شد</b> — راز و کدهای بازیابی قبلی باطل شده‌اند؛ هنگام ورود بعدی احرازگر جدید پیکربندی کنید. <a href="<?= Config::url('admin/2fa') ?>">جزئیات و پیکربندی</a></span>
        <form method="post" action="<?= Config::url('admin/2fa/ack-reset') ?>" style="margin:0;flex:none">
          <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
          <button class="btn btn-ghost btn-sm" type="submit">متوجه شدم</button>
        </form>
      </div>
<?php endif; ?>

      <?= $content ?>
    </main>
  </div>
</div>
<script src="<?= Helpers::asset('js/admin.js') ?>"></script>
</body>
</html>
