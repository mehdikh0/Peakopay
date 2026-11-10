<?php
/** @var array $cards @var array|null $edit @var array $fieldKeys @var array $user @var array $store */
$csrf = Session::csrfToken();
$base = Config::url('admin/cards');
?>
<div class="page-head">
  <h1>کارت‌های مقصد و دریافت پیامک</h1>
  <p class="hint">هر کارت: شماره‌های ارسال‌کننده مجاز + قالب‌های پیامک مخصوص خودش. پیامکی که فرستنده‌اش ثبت نشده باشد، <b>هرگز</b> پردازش نمی‌شود. شماره با هر فرمتی (30001234، +982112345678، 021…) یکسان شمرده می‌شود؛ نام فرستنده حرفی (مثل BlooIr) هم پشتیبانی می‌شود.</p>
</div>

<?php if ($edit !== null): ?>
<!-- ═══════════════ فرم ویرایش کارت + آموزش فرمت ═══════════════ -->
<a class="btn btn-ghost btn-sm" href="<?= $base ?>">→ بازگشت به لیست کارت‌ها</a>
<div class="card" style="max-width:900px;margin-top:10px">
  <div class="card-title">ویرایش کارت — <?= Helpers::e($edit['bank_name']) ?> ****<?= Helpers::e($edit['number_last4']) ?></div>
  <form method="post" action="<?= $base ?>/save">
    <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
    <input type="hidden" name="card_id" value="<?= (int) $edit['id'] ?>">
    <div class="kv">
      <div class="k">نام کارت (برچسب)</div><div><input class="inp" name="label" value="<?= Helpers::e((string) ($edit['label'] ?? '')) ?>" placeholder="مثلاً: کارت اصلی فروشگاه"></div>
      <div class="k">بانک *</div><div><input class="inp" name="bank_name" required value="<?= Helpers::e($edit['bank_name']) ?>"></div>
      <div class="k">صاحب کارت *</div><div><input class="inp" name="holder" required value="<?= Helpers::e($edit['holder']) ?>"></div>
      <div class="k">شماره کارت <?= $edit['id'] > 0 ? '(خالی = بدون تغییر)' : '*' ?></div><div><input class="inp ltr" name="number" dir="ltr" <?= $edit['id'] > 0 ? '' : 'required' ?> placeholder="6219-8612-3456-7856"></div>
      <div class="k">شماره شبا</div><div><input class="inp ltr" name="iban" dir="ltr" value=""></div>
      <div class="k">شماره حساب</div><div><input class="inp ltr" name="account_number" dir="ltr" value=""></div>
      <div class="k">شماره‌های ارسال‌کننده پیامک <span class="hint">هر خط یک شماره — با +98 یا بدون آن؛ خودکار نرمال می‌شود</span></div>
      <div><textarea class="inp" name="senders" rows="4" dir="ltr" placeholder="30001234&#10;+982112345678&#10;50004001"><?= Helpers::e(implode("\n", array_column(\CardSms::senders((int) $edit['id']), 'sender_raw'))) ?></textarea></div>
    </div>
    <button class="btn btn-primary" type="submit">ذخیره کارت</button>
  </form>
</div>

<!-- ─── قالب‌های پیامک این کارت ─── -->
<div class="card" style="max-width:900px;margin-top:14px">
  <div class="card-title">📚 قالب‌های پیامک (Parser) — به‌ترتیب اولویت امتحان می‌شوند</div>
  <?php $rules = \CardSms::rules((int) $edit['id']); ?>
  <?php if ($rules === []): ?>
    <p class="hint">هنوز قالبی ثبت نشده. با «آموزش فرمت پیامک» پایین، اولین قالب را از روی یک نمونه واقعی بسازید.</p>
  <?php else: ?>
    <table class="table">
      <tr><th>نام قالب</th><th>اولویت</th><th>وضعیت</th><th>تست‌شده</th><th>آخرین تطبیق</th><th></th></tr>
      <?php foreach ($rules as $r): ?>
        <tr>
          <td><?= Helpers::e($r['name']) ?></td>
          <td><?= (int) $r['priority'] ?></td>
          <td><?= (int) $r['enabled'] === 1 ? '✓ فعال' : '✕ غیرفعال' ?></td>
          <td><?= (int) $r['tested'] === 1 ? '✓' : '—' ?></td>
          <td class="hint"><?= $r['last_matched_at'] ? Helpers::e($r['last_matched_at']) : '—' ?></td>
          <td>
            <form method="post" action="<?= $base ?>/action" style="display:inline">
              <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>"><input type="hidden" name="card_id" value="<?= (int) $edit['id'] ?>">
              <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-ghost btn-sm" name="do" value="rule_toggle"><?= (int) $r['enabled'] === 1 ? 'غیرفعال' : 'فعال' ?></button>
              <button class="btn btn-danger btn-sm" name="do" value="rule_delete" onclick="return confirm('قالب حذف شود؟')">حذف</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<!-- ─── آموزش فرمت پیامک (sample-based — بدون هوش مصنوعی، قانون قطعی و قابل‌ممیزی) ─── -->
<div class="card" style="max-width:900px;margin-top:14px;border:1.5px solid var(--brand)">
  <div class="card-title">🎓 آموزش فرمت پیامک</div>
  <p class="hint">یک نمونه پیامک واقعیِ واریز را بچسبانید و «تحلیل نمونه» را بزنید. سیستم بخش‌های متغیر (مبلغ/تاریخ/…) را پیشنهاد می‌دهد؛ شما نوع هر بخش را تأیید یا اصلاح می‌کنید و از آن یک قالب قطعی ساخته می‌شود. برای هر فرمت متفاوت بانک، یک نمونه جدا بدهید (قالب ۲، ۳، …).</p>
  <textarea id="hp-sample" class="inp" rows="5" placeholder="مثلاً:&#10;واریز به کارت 1234&#10;مبلغ 25,000,000 ریال&#10;مانده 87,500,000 ریال&#10;تاریخ 1405/06/29 ساعت 14:32&#10;کد پیگیری 123456"></textarea>
  <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <button class="btn btn-ghost" id="hp-analyze" type="button">🔎 تحلیل نمونه</button>
    <input class="inp" id="hp-rule-name" style="width:180px" placeholder="نام قالب (قالب ۱)">
    <input class="inp" id="hp-rule-prio" style="width:90px" type="number" value="10" min="1" title="اولویت">
    <button class="btn btn-primary" id="hp-build" type="button" <?= $edit['id'] > 0 ? '' : 'disabled title="اول کارت را ذخیره کنید"' ?>>✓ ساخت و ذخیره قالب</button>
  </div>
  <div id="hp-segments" style="margin-top:12px"></div>
  <div id="hp-build-result" style="margin-top:10px"></div>
</div>

<!-- ─── تست پارسر ─── -->
<div class="card" style="max-width:900px;margin-top:14px">
  <div class="card-title">🧪 تست پارسر</div>
  <p class="hint">یک پیامک (و شماره فرستنده) بدهید تا دقیقاً مثل اپ اندروید پردازش شود: تطبیق فرستنده ← انتخاب قالب ← استخراج فیلدها.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <input class="inp ltr" id="hp-test-sender" style="width:170px" dir="ltr" placeholder="فرستنده، مثلاً 30001234">
    <button class="btn btn-primary" id="hp-test" type="button">TEST</button>
  </div>
  <textarea id="hp-test-text" class="inp" rows="4" style="margin-top:8px" placeholder="متن پیامک…"></textarea>
  <div id="hp-test-result" style="margin-top:10px"></div>
</div>

<script nonce="<?= Helpers::e(Http::cspNonce()) ?>">
(function () {
  var CSRF = '<?= Helpers::e($csrf) ?>';
  var CARD_ID = <?= (int) ($edit['id'] ?? 0) ?>;
  var FIELDS = <?= json_encode($fieldKeys, JSON_UNESCAPED_UNICODE) ?>;
  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams(data),
    }).then(function (r) {
      if (!r.ok) { return r.json().catch(function () { return { ok: false, message: 'خطای HTTP ' + r.status }; }); }
      return r.json();
    });
  }
  function busy(btn, on, label) {
    btn.disabled = on;
    if (on) { btn.dataset.orig = btn.textContent; btn.textContent = label || '… در حال پردازش'; }
    else if (btn.dataset.orig) { btn.textContent = btn.dataset.orig; }
  }
  function netErr(box, err) {
    box.innerHTML = '<p class="hint" style="color:var(--bad)">خطا در ارتباط با سرور: ' + String(err && err.message ? err.message : err) + '</p>';
  }
  var spans = [];
  document.getElementById('hp-analyze').onclick = function () {
    var btn = this;
    busy(btn, true, '… در حال تحلیل');
    post('<?= $base ?>/analyze', { sample: document.getElementById('hp-sample').value }).then(function (res) {
      var box = document.getElementById('hp-segments');
      box.innerHTML = '';
      document.getElementById('hp-build-result').innerHTML = '';
      if (!res.ok) { box.innerHTML = '<p class="hint" style="color:var(--bad)">' + (res.message || 'خطا') + '</p>'; return; }
      spans = [];
      res.segments.forEach(function (seg) {
        if (seg.span) spans.push(seg.span);
        var wrap = document.createElement('span');
        if (seg.span) {
          wrap.className = 'code-box';
          // کاندیدای بدون پیشنهاد (مثل شماره حساب) = خط‌چین خاکستری؛ پیشنهاد‌دار = پررنگ
          var isCand = seg.span[2] === null || seg.span[2] === '';
          wrap.style.cssText = 'display:inline-block;margin:2px;padding:2px 6px;border:1.5px ' + (isCand ? 'dashed #9aa3bd' : 'solid var(--brand)') + ';border-radius:8px;cursor:pointer' + (isCand ? ';opacity:.85' : '');
          var sel = document.createElement('select');
          sel.style.cssText = 'border:none;background:transparent;font:inherit;color:inherit';
          sel.dataset.span = JSON.stringify(seg.span);
          var optNone = document.createElement('option');
          optNone.value = ''; optNone.textContent = '— متن ثابت —';
          sel.appendChild(optNone);
          Object.keys(FIELDS).forEach(function (k) {
            var o = document.createElement('option');
            o.value = k; o.textContent = FIELDS[k];
            if (k === seg.span[2]) o.selected = true;
            sel.appendChild(o);
          });
          wrap.appendChild(sel);
          wrap.appendChild(document.createTextNode(' ' + seg.text));
        } else {
          wrap.textContent = seg.text;
          wrap.style.whiteSpace = 'pre-wrap';
        }
        box.appendChild(wrap);
      });
      var hint = document.createElement('p');
      hint.className = 'hint';
      hint.textContent = 'نوع هر بخش را از منوهای کنارش تأیید/اصلاح کنید (یا «متن ثابت» بگذارید)، بعد «ساخت و ذخیره قالب» را بزنید. کادرهای خط‌چین = اعدادی که نوعشان پیشنهاد نشده ولی در صورت نیاز قابل انتخاب‌اند.';
      box.appendChild(hint);
    }, function (err) { netErr(document.getElementById('hp-segments'), err); })
      .finally(function () { busy(btn, false); });
  };
  document.getElementById('hp-build').onclick = function () {
    if (spans.length === 0) {
      document.getElementById('hp-build-result').innerHTML = '<p class="hint" style="color:var(--warn)">اول «تحلیل نمونه» را بزنید و نوع بخش‌ها را انتخاب کنید.</p>';
      return;
    }
    var btn = this;
    busy(btn, true, '… در حال ساخت قالب');
    var chosen = [];
    document.querySelectorAll('#hp-segments select').forEach(function (sel) {
      if (sel.value) {
        var sp = JSON.parse(sel.dataset.span);
        chosen.push([sp[0], sp[1], sel.value]);
      }
    });
    post('<?= $base ?>/build-rule', {
      sample: document.getElementById('hp-sample').value,
      spans: JSON.stringify(chosen),
      name: document.getElementById('hp-rule-name').value,
      priority: document.getElementById('hp-rule-prio').value,
      card_id: CARD_ID,
    }).then(function (res) {
      var box = document.getElementById('hp-build-result');
      if (!res.ok) { box.innerHTML = '<p class="hint" style="color:var(--bad)">' + (res.message || 'خطا') + '</p>'; return; }
      var f = res.preview.fields, rows = '';
      Object.keys(f).forEach(function (k) {
        rows += '<div class="k">' + (FIELDS[k] || k) + '</div><div><code>' + String(f[k]).replace(/</g, '&lt;') + '</code></div>';
      });
      box.innerHTML = '<div class="kv" style="margin-top:6px">' + rows +
        '<div class="k">مبلغ (ریال)</div><div><code>' + res.preview.amount_rial.toLocaleString('en') + '</code></div>' +
        '<div class="k">ارز</div><div><code>' + res.preview.currency + '</code></div></div>' +
        '<p class="hint" style="color:var(--good)">قالب «' + res.rule.name + '» ذخیره شد و روی خود نمونه درست پاسخ داد ✓' +
        (res.rule_id ? ' — در جدول قالب‌ها بالای صفحه دیده می‌شود.' : '') + '</p>' +
        '<a class="btn btn-ghost btn-sm" href="<?= $base ?>?edit=' + CARD_ID + '">رفرش جدول قالب‌ها</a>';
    }, function (err) { netErr(document.getElementById('hp-build-result'), err); })
      .finally(function () { busy(btn, false); });
  };
  document.getElementById('hp-test').onclick = function () {
    var btn = this;
    busy(btn, true, '… در حال تست');
    post('<?= $base ?>/test', {
      card_id: CARD_ID,
      sender: document.getElementById('hp-test-sender').value,
      text: document.getElementById('hp-test-text').value,
    }).then(function (res) {
      var box = document.getElementById('hp-test-result');
      if (!res.ok) { box.innerHTML = '<div class="code-box" style="border-color:var(--bad);padding:10px"><b style="color:var(--bad)">INVALID</b><br>' + (res.message || '') + '</div>'; return; }
      var f = res.fields, rows = '';
      Object.keys(f).forEach(function (k) { rows += '<div class="k">' + (FIELDS[k] || k) + '</div><div><code>' + String(f[k]).replace(/</g, '&lt;') + '</code></div>'; });
      box.innerHTML = '<div class="code-box" style="border-color:var(--good);padding:10px"><b style="color:var(--good)">VALID ✓</b> — کارت: ' + res.card + ' | قالب: ' + res.matched_rule + ' | اطمینان: ' + res.confidence +
        '<div class="kv" style="margin-top:8px">' + rows +
        '<div class="k">مبلغ</div><div><code>' + res.amount_rial.toLocaleString('en') + ' ' + res.currency + '</code></div></div></div>';
    }, function (err) { netErr(document.getElementById('hp-test-result'), err); })
      .finally(function () { busy(btn, false); });
  };
})();
</script>
<?php else: ?>
<!-- ═══════════════ لیست کارت‌ها ═══════════════ -->
<?php if (isset($_SESSION['flash']) ) unset($_SESSION['flash']); ?>
<div class="card">
  <table class="table">
    <tr><th>کارت</th><th>شماره (ماسک)</th><th>وضعیت</th><th>فرستنده‌های مجاز</th><th>Parser</th><th>آخرین تراکنش پارس‌شده</th><th></th></tr>
    <?php foreach ($cards as $c): ?>
      <?php $rulesOk = count(array_filter($c['rules'], static fn ($r) => (int) $r['enabled'] === 1)); ?>
      <tr>
        <td>
          <b><?= Helpers::e(($c['label'] ?? '') !== '' ? $c['label'] : $c['bank_name']) ?></b><br>
          <span class="hint"><?= Helpers::e($c['bank_name']) ?> — <?= Helpers::e($c['holder']) ?><?= (int) $c['is_default'] === 1 ? ' ★ پیش‌فرض' : '' ?></span>
        </td>
        <td><code>**** **** **** <?= Helpers::e($c['number_last4']) ?></code></td>
        <td><?= (int) $c['is_active'] === 1 ? '<span style="color:var(--good)">● فعال</span>' : '<span style="color:var(--bad)">● غیرفعال</span>' ?></td>
        <td><?= count($c['senders']) > 0 ? count($c['senders']) . ' شماره' : '<span class="hint" style="color:var(--warn)">ثبت نشده ⚠</span>' ?></td>
        <td>
          <?php if ($rulesOk === 0): ?><span class="hint" style="color:var(--warn)">تنظیم نشده ⚠</span>
          <?php else: ?>✓ <?= $rulesOk ?> قالب<?= array_sum(array_column($c['rules'], 'tested')) > 0 ? ' (تست‌شده)' : '' ?><?php endif; ?>
        </td>
        <td class="hint"><?= $c['last_tx'] ? Helpers::faNumber($c['last_tx']['amount_rial']) . ' ' . ($c['last_tx']['currency'] === 'IRT' ? 'تومان' : 'ریال') : '—' ?></td>
        <td>
          <a class="btn btn-ghost btn-sm" href="<?= $base ?>?edit=<?= (int) $c['id'] ?>">تنظیم پیامک/ویرایش</a>
          <form method="post" action="<?= $base ?>/action" style="display:inline">
            <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>"><input type="hidden" name="card_id" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-ghost btn-sm" name="do" value="toggle"><?= (int) $c['is_active'] === 1 ? 'غیرفعال' : 'فعال' ?></button>
            <?php if ((int) $c['is_default'] !== 1): ?><button class="btn btn-ghost btn-sm" name="do" value="default">پیش‌فرض</button><?php endif; ?>
            <button class="btn btn-danger btn-sm" name="do" value="delete" onclick="return confirm('کارت حذف شود؟')">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($cards === []): ?><tr><td colspan="7" class="hint">هنوز کارتی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
</div>
<div class="card" style="margin-top:14px;max-width:900px">
  <div class="card-title">افزودن کارت جدید</div>
  <form method="post" action="<?= $base ?>/save">
    <input type="hidden" name="_csrf" value="<?= Helpers::e($csrf) ?>">
    <div class="kv">
      <div class="k">نام کارت</div><div><input class="inp" name="label" placeholder="کارت اصلی فروشگاه"></div>
      <div class="k">بانک *</div><div><input class="inp" name="bank_name" required placeholder="بانک ملت"></div>
      <div class="k">صاحب کارت *</div><div><input class="inp" name="holder" required></div>
      <div class="k">شماره کارت *</div><div><input class="inp ltr" name="number" dir="ltr" required></div>
      <div class="k">فرستنده‌های مجاز <span class="hint">هر خط یک شماره</span></div><div><textarea class="inp" name="senders" rows="3" dir="ltr" placeholder="30001234&#10;+982112345678"></textarea></div>
    </div>
    <button class="btn btn-primary" type="submit">افزودن کارت</button>
  </form>
</div>
<?php endif; ?>
