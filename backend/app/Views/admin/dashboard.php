<?php
/** Dashboard view. Expects: $stats,$total,$daily,$txnStats,$devices,$cards,$recentPay,$recentTxn,$wooOk */

$unmatched = (int)($txnStats['unmatched'] ?? 0);
$duplicate = (int)($txnStats['duplicate'] ?? 0);
$activeDevices = array_filter($devices ?? [], static fn ($d) => $d['status'] === 'active');
$pendingDevices = array_filter($devices ?? [], static fn ($d) => $d['status'] === 'pending');

// --- 14-day income chart (server-side SVG) ---
$daily = $daily ?? [];
$max = max(1, max($daily ?: [1]));
$barW = 100 / max(1, count($daily));
$chartBars = '';
$chartLabels = '';
$i = 0;
foreach ($daily as $day => $amount) {
    $h = round(($amount / $max) * 130, 1);
    $x = round($i * $barW + $barW * 0.18, 2);
    $w = round($barW * 0.64, 2);
    $chartBars .= '<rect x="' . $x . '" y="' . (150 - $h) . '" width="' . $w . '" height="' . max($h, 1.5) . '" rx="3" fill="url(#g1)" opacity="' . ($amount > 0 ? '1' : '0.25') . '"><title>' . Helpers::faDigits(number_format($amount)) . ' ریال — ' . Helpers::jdate($day . ' 12:00:00', false) . '</title></rect>';
    if ($i % 2 === 0) {
        $chartLabels .= '<text x="' . ($x + $w / 2) . '" y="168" font-size="8.5" fill="#69708f" text-anchor="middle">' . Helpers::faDigits(explode('-', $day)[2]) . '</text>';
    }
    $i++;
}
?>
<div class="grid cols-4">
  <div class="card stat">
    <div class="icon i1">💰</div>
    <div class="value"><?= Helpers::money($stats['paid_income']) ?></div>
    <div class="label">درآمد امروز (ریال)</div>
  </div>
  <div class="card stat">
    <div class="icon i2">🧾</div>
    <div class="value"><?= Helpers::faDigits($stats['paid_count']) ?></div>
    <div class="label">پرداخت موفق امروز</div>
  </div>
  <div class="card stat">
    <div class="icon i3">⏳</div>
    <div class="value"><?= Helpers::faDigits($stats['pending']) ?></div>
    <div class="label">در انتظار پرداخت</div>
  </div>
  <div class="card stat">
    <div class="icon i4">🧩</div>
    <div class="value"><?= Helpers::faDigits($unmatched) ?></div>
    <div class="label">تراکنش بدون تطبیق</div>
  </div>
</div>

<div class="grid cols-2" style="margin-top:16px">
  <div class="card">
    <div class="card-title">درآمد ۱۴ روز اخیر <span class="more">جمع کل: <?= Helpers::money($total) ?></span></div>
    <?php if (max($daily ?: [0]) === 0): ?>
      <div class="empty"><div class="empty-icon">📊</div><b>هنوز داده‌ای نیست</b>با اولین پرداخت موفق، نمودار درآمد همین‌جا نمایش داده می‌شود.</div>
    <?php else: ?>
      <div class="chart">
        <svg viewBox="0 0 100 175" preserveAspectRatio="none">
          <defs><linearGradient id="g1" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8b5cf6"/><stop offset="1" stop-color="#5b5ce8"/></linearGradient></defs>
          <line x1="0" y1="150" x2="100" y2="150" stroke="#e7e9f5" stroke-width="0.6"/>
          <?= $chartBars . $chartLabels ?>
        </svg>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">وضعیت سامانه</div>
    <div class="stat-sm">📱 دستگاه متصل <b><?= Helpers::faDigits(count($activeDevices)) ?></b></div>
    <div class="stat-sm">⌛ دستگاه در انتظار تأیید <b><?= Helpers::faDigits(count($pendingDevices)) ?></b></div>
    <div class="stat-sm">💳 کارت مقصد فعال <b><?= Helpers::faDigits(count(array_filter($cards ?? [], static fn ($c) => (int)$c['is_active'] === 1))) ?></b></div>
    <div class="stat-sm">🔌 اتصال ووکامرس <b><?= $wooOk ? '<span class="badge badge-ok">آماده</span>' : '<span class="badge badge-warn">تنظیم نشده</span>' ?></b></div>
    <div class="stat-sm">♻️ تراکنش تکراری <b><?= Helpers::faDigits($duplicate) ?></b></div>
    <div class="stat-sm">❌ پرداخت ناموفق/منقضی <b><?= Helpers::faDigits($stats['failed'] + $stats['expired']) ?></b></div>
    <div style="margin-top:14px"><a class="btn btn-ghost btn-sm" href="<?= Config::url('admin/devices') ?>">مدیریت دستگاه‌ها</a></div>
  </div>
</div>

<div class="grid cols-2" style="margin-top:16px">
  <div class="card">
    <div class="card-title">آخرین پرداخت‌ها <span class="more"><a href="<?= Config::url('admin/payments') ?>">همه →</a></span></div>
    <?php if ($recentPay === []): ?>
      <div class="empty"><div class="empty-icon">🧾</div><b>پرداختی ثبت نشده</b>وقتی مشتری در فروشگاه سفارش ثبت کند، اینجا دیده می‌شود.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>سفارش</th><th>مبلغ</th><th>وضعیت</th><th>زمان</th></tr>
        <?php foreach ($recentPay as $p): [$lbl, $cls] = Helpers::statusLabel((string)$p['status']); ?>
        <tr>
          <td><a href="<?= Config::url('admin/payments/' . $p['id']) ?>"><?= Helpers::faDigits($p['order_id'] !== '' ? $p['order_id'] : '—') ?></a>
            <?php if ((int)$p['is_test'] === 1): ?><span class="badge badge-mute">تست</span><?php endif; ?></td>
          <td><?= Helpers::money($p['amount_rial']) ?></td>
          <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
          <td><?= Helpers::ago($p['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">آخرین تراکنش‌ها <span class="more"><a href="<?= Config::url('admin/transactions') ?>">همه →</a></span></div>
    <?php if ($recentTxn === []): ?>
      <div class="empty"><div class="empty-icon">📲</div><b>تراکنشی دریافت نشده</b>پس از نصب اپ اندروید و تأیید دستگاه، واریزی‌ها اینجا نمایش داده می‌شوند.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>مبلغ</th><th>بانک</th><th>وضعیت</th><th>زمان</th></tr>
        <?php foreach ($recentTxn as $t): [$lbl, $cls] = Helpers::statusLabel((string)$t['status']); ?>
        <tr>
          <td><?= Helpers::money($t['amount_rial']) ?></td>
          <td><?= Helpers::e($t['bank'] ?: '—') ?></td>
          <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
          <td><?= Helpers::ago($t['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>
</div>
