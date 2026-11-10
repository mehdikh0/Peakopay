<?php
/** Stores page (multi-store). Expects: $stores,$isOwner,$revealKey,$revealName */
$stores   = $stores ?? [];
$isOwner  = $isOwner ?? false;
$revealKey  = $revealKey ?? null;
$revealName = $revealName ?? null;
$curStoreId = (int) ($store['id'] ?? 0);
$statusMeta = [
    'connected'    => ['متصل', 'ok'],
    'pending'      => ['در انتظار اتصال ووکامرس', 'warn'],
    'disconnected' => ['قطع‌شده', 'err'],
];
?>

<?php if ($revealKey !== null): ?>
<div class="card pairing-hero">
  <h3 style="margin:0">کلید اتصال فروشگاه «<?= Helpers::e((string)$revealName) ?>»</h3>
  <p style="color:var(--muted)">این کلید را در تنظیمات افزونه ووکامرس همان فروشگاه وارد کنید. <b>فقط همین یک بار نمایش داده می‌شود.</b></p>
  <div class="pairing-code" style="font-size:15px;word-break:break-all"><?= Helpers::e((string)$revealKey) ?></div>
  <p style="color:var(--muted);font-size:12px">مراحل: ووکامرس ← تنظیمات ← پرداخت‌ها ← پیکو پی ← «اتصال به Peakopay» ← شناسه فروشگاه و کلید را وارد و ذخیره کنید.</p>
</div>
<?php endif; ?>

<div class="grid cols-2">
  <?php foreach ($stores as $s): [$lbl, $cls] = $statusMeta[$s['connection_status']] ?? ['نامشخص', '']; ?>
  <div class="card" style="<?= (int)$s['id'] === $curStoreId ? 'border-color:var(--primary)' : '' ?>">
    <div class="card-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      🏬 <?= Helpers::e($s['name']) ?>
      <span class="badge <?= $cls ?>"><?= $lbl ?></span>
      <?php if ((int)$s['id'] === $curStoreId): ?><span class="chip">فروشگاه فعال پنل</span><?php endif; ?>
    </div>
    <div class="kv">
      <span>دامنه</span><span dir="ltr"><?= Helpers::e($s['domain'] !== '' ? $s['domain'] : '—') ?></span>
      <span>شناسه عمومی</span><span dir="ltr"><?= Helpers::e($s['public_id']) ?></span>
      <span>کلید API</span><span dir="ltr"><?= Helpers::e($s['api_key_mask'] ?? '—') ?></span>
      <span>کارت فعال</span><span><?= Helpers::faDigits((string)$s['cards_count']) ?></span>
      <span>پرداخت موفق</span><span><?= Helpers::faDigits((string)$s['paid_total']) ?></span>
      <span>اعضای فروشگاه</span><span><?= Helpers::faDigits((string)$s['members_count']) ?></span>
    </div>
    <?php if ($isOwner): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
      <?php if ((int)$s['id'] !== $curStoreId): ?>
      <form method="post" action="<?= Config::url('admin/stores/switch') ?>">
        <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>">
        <button class="btn btn-primary btn-sm">ورود به این فروشگاه</button>
      </form>
      <?php endif; ?>
      <form method="post" action="<?= Config::url('admin/stores/update') ?>">
        <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="do" value="rekey" data-confirm-none>
        <button class="btn btn-sm">🔑 کلید جدید</button>
      </form>
      <?php if ($s['connection_status'] === 'disconnected'): ?>
      <form method="post" action="<?= Config::url('admin/stores/update') ?>">
        <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="do" value="reconnect">
        <button class="btn btn-sm">↻ اتصال مجدد</button>
      </form>
      <?php else: ?>
      <form method="post" action="<?= Config::url('admin/stores/update') ?>" data-confirm="این فروشگاه از حساب قطع شود؟ کلیدها حفظ می‌شوند.">
        <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="do" value="disconnect">
        <button class="btn btn-danger btn-sm">قطع اتصال</button>
      </form>
      <?php endif; ?>
    </div>
    <details style="margin-top:10px">
      <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">ویرایش اطلاعات</summary>
      <form method="post" action="<?= Config::url('admin/stores/update') ?>" style="margin-top:8px;display:grid;gap:8px">
        <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="do" value="edit">
        <input name="name" value="<?= Helpers::e($s['name']) ?>" placeholder="نام فروشگاه">
        <input name="domain" dir="ltr" value="<?= Helpers::e($s['domain']) ?>" placeholder="shop2.example.com">
        <input name="url" dir="ltr" value="<?= Helpers::e($s['url']) ?>" placeholder="https://shop2.example.com">
        <button class="btn btn-primary btn-sm" style="justify-self:start">ذخیره</button>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($isOwner): ?>
<div class="card" style="margin-top:16px">
  <div class="card-title">➕ افزودن فروشگاه جدید به همین حساب</div>
  <p style="color:var(--muted);font-size:13px;line-height:2">
    هر فروشگاه = یک نصب مستقل ووکامرس با کلید اتصال خودش. پس از ساخت، کلید را در افزونهٔ ووکامرسِ آن فروشگاه وارد کنید:
    <br>۱) نام و دامنه را وارد کنید ۲) کلید اتصال ساخته می‌شود ۳) آن را در ووکامرسِ فروشگاه وارد کنید ۴) اتصال تأیید می‌شود ۵) همگام‌سازی اولیه انجام می‌گیرد.
  </p>
  <form method="post" action="<?= Config::url('admin/stores/save') ?>" style="display:grid;gap:8px;max-width:480px">
    <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
    <input name="name" placeholder="نام فروشگاه (مثلاً شعبه ۲)" required>
    <input name="domain" dir="ltr" placeholder="دامنه (مثلاً shop2.example.com)">
    <input name="url" dir="ltr" placeholder="آدرس کامل ووکامرس (اختیاری اگر دامنه دادید)">
    <button class="btn btn-primary">ساخت فروشگاه و دریافت کلید اتصال</button>
  </form>
</div>
<?php else: ?>
<div class="alert info">ساخت و مدیریت فروشگاه‌ها فقط توسط مالک حساب انجام می‌شود.</div>
<?php endif; ?>
