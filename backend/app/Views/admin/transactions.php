<?php
/** Transactions list. Expects: $rows,$total,$page,$perPage,$status,$q,$counts */
$pages = max(1, (int)ceil($total / $perPage));
$statFilters = ['' => 'همه', 'received' => 'دریافت‌شده', 'matched' => 'تطبیق‌یافته', 'confirmed' => 'تأییدشده', 'unmatched' => 'بدون تطبیق', 'dismissed' => 'نادیده‌گرفته‌شده', 'duplicate' => 'تکراری', 'rejected' => 'ردشده'];
?>
<form method="get" class="filter-bar">
  <input type="text" name="q" class="f-q" placeholder="جستجوی مرجع / بانک / فرستنده…" value="<?= Helpers::e($q) ?>">
  <select name="status" class="f-status">
    <?php foreach ($statFilters as $k => $lbl): ?>
      <option value="<?= Helpers::e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?><?= $k !== '' && isset($counts[$k]) ? ' (' . Helpers::faDigits($counts[$k]) . ')' : '' ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-primary btn-sm">اعمال فیلتر</button>
  <a class="btn btn-ghost btn-sm" href="<?= Config::url('admin/transactions') ?>">پاک‌کردن</a>
</form>

<?php if ($rows === []): ?>
  <div class="card"><div class="empty">
    <div class="empty-icon">📲</div>
    <b>تراکنشی یافت نشد</b>
    تراکنش‌ها وقتی ثبت می‌شوند که اپ اندروید (نصب‌شده روی گوشی فروشنده) پیامک واریز بانک را تشخیص دهد و برای پیکو پی ارسال کند.
  </div></div>
<?php else: ?>
<div class="card" style="padding:6px">
  <div class="table-wrap" style="border:0">
    <table>
      <tr><th>#</th><th>مبلغ</th><th>بانک</th><th>مرجع</th><th>کارت مقصد</th><th>وضعیت</th><th>سفارش</th><th>زمان پیامک</th><th></th></tr>
      <?php foreach ($rows as $t): [$lbl, $cls] = Helpers::statusLabel((string)$t['status']); ?>
      <tr>
        <td class="mono"><?= Helpers::faDigits($t['id']) ?></td>
        <td><?= Helpers::money($t['amount_rial']) ?></td>
        <td><?= Helpers::e($t['bank'] ?: '—') ?><span class="sub"><?= Helpers::e($t['sender'] ?: '') ?></span></td>
        <td class="mono"><?= $t['reference'] !== '' ? Helpers::faDigits($t['reference']) : '—' ?></td>
        <td class="mono"><?= $t['dest_last4'] !== '' ? '•••• ' . Helpers::faDigits($t['dest_last4']) : '—' ?></td>
        <td>
          <span class="badge <?= $cls ?>"><?= $lbl ?></span>
          <?php if ($t['status'] === 'unmatched'): ?>
            <span class="sub" style="display:block;max-width:340px;white-space:normal;line-height:1.7"><?= Helpers::e(Matcher::unmatchedReason($t)) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($t['payment_id'] !== null): ?>
            <a href="<?= Config::url('admin/payments/' . $t['payment_id']) ?>"><?= Helpers::faDigits($t['order_id'] ?: $t['payment_id']) ?></a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= Helpers::jdate($t['occurred_at']) ?></td>
        <td><button class="btn btn-ghost btn-sm" data-raw="<?= (int)$t['id'] ?>" data-url="<?= Config::url('admin/transactions/' . (int)$t['id'] . '/raw') ?>">پیامک</button></td>
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
