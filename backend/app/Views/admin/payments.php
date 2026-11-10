<?php
/** Payments list. Expects: $rows,$total,$page,$perPage,$status,$q */
$pages = max(1, (int)ceil($total / $perPage));
$statFilters = ['' => 'همه', 'pending' => 'در انتظار', 'verifying' => 'در بررسی', 'paid' => 'پرداخت‌شده', 'expired' => 'منقضی', 'failed' => 'ناموفق'];
?>
<form method="get" class="filter-bar">
  <input type="text" name="q" class="f-q" placeholder="جستجوی شماره سفارش / مشتری…" value="<?= Helpers::e($q) ?>">
  <select name="status" class="f-status">
    <?php foreach ($statFilters as $k => $lbl): ?>
      <option value="<?= Helpers::e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-primary btn-sm">اعمال فیلتر</button>
  <a class="btn btn-ghost btn-sm" href="<?= Config::url('admin/payments') ?>">پاک‌کردن</a>
</form>

<?php if ($rows === []): ?>
  <div class="card"><div class="empty">
    <div class="empty-icon">🧾</div>
    <b>هیچ پرداختی یافت نشد</b>
    وقتی مشتری در ووکامرس سفارشی را با روش «پیکو پی» ثبت کند، نشست پرداخت اینجا ساخته می‌شود.
  </div></div>
<?php else: ?>
<div class="card" style="padding:6px">
  <div class="table-wrap" style="border:0">
    <table>
      <tr><th>#</th><th>سفارش</th><th>مبلغ</th><th>وضعیت</th><th>ووکامرس</th><th>ایجاد</th><th></th></tr>
      <?php foreach ($rows as $p): [$lbl, $cls] = Helpers::statusLabel((string)$p['status']); ?>
      <tr>
        <td class="mono"><?= Helpers::faDigits($p['id']) ?></td>
        <td>
          <?= Helpers::faDigits($p['order_id'] !== '' ? $p['order_id'] : '—') ?>
          <?php if ((int)$p['is_test'] === 1): ?><span class="badge badge-mute">تست</span><?php endif; ?>
          <span class="sub"><?= Helpers::e($p['customer_name'] ?: '') ?></span>
        </td>
        <td><?= Helpers::money($p['amount_rial']) ?></td>
        <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
        <td><?php
          $wn = (string)$p['woo_notify_status'];
          if ($p['status'] === 'paid' && $wn === 'sent') echo '<span class="badge badge-ok">اطلاع داده شد</span>';
          elseif ($wn === 'failed') echo '<span class="badge badge-bad">خطا در ارسال</span>';
          elseif ($wn === 'disabled') echo '<span class="badge badge-mute">غیرفعال</span>';
          elseif ($wn === 'test') echo '<span class="badge badge-mute">تست</span>';
          else echo '<span class="badge badge-mute">—</span>';
        ?></td>
        <td><?= Helpers::jdate($p['created_at']) ?><span class="sub"><?= Helpers::ago($p['created_at']) ?></span></td>
        <td><a class="btn btn-ghost btn-sm" href="<?= Config::url('admin/payments/' . $p['id']) ?>">جزئیات</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($pages > 1): ?>
<div class="pagination">
  <?php for ($i = 1; $i <= $pages; $i++): $qs = http_build_query(['page' => $i, 'status' => $status, 'q' => $q]); ?>
    <?php if ($i === $page): ?><span class="cur"><?= Helpers::faDigits($i) ?></span>
    <?php else: ?><a href="?<?= Helpers::e($qs) ?>"><?= Helpers::faDigits($i) ?></a><?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>
