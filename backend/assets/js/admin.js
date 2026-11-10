/* Peakopay — admin panel interactions (vanilla JS, no dependencies). */
(function () {
  'use strict';

  // ---- mobile sidebar ----
  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  var menuBtn = document.getElementById('menuBtn');
  if (sidebar && menuBtn) {
    menuBtn.addEventListener('click', function () {
      sidebar.classList.toggle('open');
      var on = sidebar.classList.contains('open');
      if (backdrop) backdrop.classList.toggle('show', on);
      document.body.classList.toggle('nav-open', on); // قفل اسکرول صفحه وقتی منو باز است
    });
    if (backdrop) backdrop.addEventListener('click', function () {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
      document.body.classList.remove('nav-open');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
        document.body.classList.remove('nav-open');
      }
    });
  }

  // ---- responsive tables (mobile): جدول → کارت‌های پشته‌ای ----
  // زیر ۸۶۰px هر ردیف جدول به یک کارت تبدیل می‌شود و نام ستون (از ردیف سرستون)
  // به‌صورت data-label روی هر سلول می‌آید؛ CSS (admin.css، بلاک دور ۱۵) نمایش را
  // بر عهده دارد. روی دسکتاپ هیچ کلاسی اضافه نمی‌شود → ظاهر دسکتاپ دست‌نخورده.
  var rtableMq = window.matchMedia ? window.matchMedia('(max-width: 860px)') : null;
  function rtableApply() {
    var mobile = !!(rtableMq && rtableMq.matches);
    document.querySelectorAll('table').forEach(function (t) {
      // فقط جداول دادهٔ پنل (داخل table-wrap یا با کلاس table) — نه جدول‌های چیدمانی احتمالی
      if (!t.closest('.table-wrap') && !t.classList.contains('table')) return;
      var rows = t.querySelectorAll('tr');
      if (rows.length === 0) return;
      // برچسب‌ها = متن سرستون‌های ردیف اول
      var labels = [];
      rows[0].querySelectorAll('th').forEach(function (th) { labels.push(th.textContent.trim()); });
      for (var i = 1; i < rows.length; i++) {
        var tds = rows[i].querySelectorAll('td');
        for (var j = 0; j < tds.length; j++) {
          var lbl = tds[j].hasAttribute('colspan') ? '' : (labels[j] || '');
          tds[j].setAttribute('data-label', lbl);
        }
      }
      t.classList.toggle('rtable', mobile);
      var wrap = t.closest('.table-wrap');
      if (wrap) wrap.classList.toggle('rstack', mobile);
    });
  }
  if (rtableMq) {
    rtableApply();
    if (rtableMq.addEventListener) rtableMq.addEventListener('change', rtableApply);
    else if (rtableMq.addListener) rtableMq.addListener(rtableApply); // Safari قدیمی
  }

  // ---- copy-to-clipboard buttons: <button data-copy="..."> ----
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();
    var text = btn.getAttribute('data-copy');
    var done = function () {
      var old = btn.textContent;
      btn.textContent = btn.getAttribute('data-copy-ok') || 'کپی شد ✓';
      btn.classList.add('btn-ok');
      setTimeout(function () { btn.textContent = old; btn.classList.remove('btn-ok'); }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text); done(); });
    } else { legacyCopy(text); done(); }
  });
  function legacyCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (err) {}
    document.body.removeChild(ta);
  }

  // ---- confirm dangerous forms ----
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  }, true);

  // ---- auto-dismiss flash messages ----
  document.querySelectorAll('.flash').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity .4s, transform .4s';
      el.style.opacity = '0';
      el.style.transform = 'translateY(-8px)';
      setTimeout(function () { el.remove(); }, 450);
    }, 6000);
  });

  // ---- transaction raw-SMS modal: <a data-raw="{id}"> ----
  document.addEventListener('click', function (e) {
    var link = e.target.closest('[data-raw]');
    if (!link) return;
    e.preventDefault();
    var id = link.getAttribute('data-raw');
    var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
    fetch(link.getAttribute('data-url') || ('?raw=' + id), {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { alert(j.error ? j.error.message : 'خطا'); return; }
      showModal('متن پیامک تراکنش #' + id, j.data.raw);
    }).catch(function () { alert('خطا در دریافت متن پیامک'); });
  });

  function showModal(title, bodyText) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,15,40,.55);z-index:90;display:grid;place-items:center;padding:18px';
    var box = document.createElement('div');
    box.className = 'card';
    box.style.cssText = 'max-width:560px;width:100%;max-height:80vh;overflow:auto';
    box.innerHTML = '<div class="card-title"></div><pre class="code-box" style="white-space:pre-wrap;direction:rtl;text-align:right"></pre><div style="margin-top:12px;text-align:left"><button class="btn btn-ghost btn-sm">بستن</button></div>';
    box.querySelector('.card-title').textContent = title;
    box.querySelector('pre').textContent = bodyText;
    box.querySelector('button').onclick = function () { overlay.remove(); };
    overlay.appendChild(box);
    overlay.addEventListener('click', function (ev) { if (ev.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
  }

  // ---- QR rendering: <div data-qr="text"> ----
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    var text = el.getAttribute('data-qr');
    if (!window.qrcode) return;
    try {
      var qr = window.qrcode(0, 'M');
      qr.addData(text);
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 1 });
      el.querySelector('svg').style.cssText = 'width:100%;height:auto;max-width:210px;display:block';
    } catch (err) {
      el.textContent = '—';
    }
  });
})();
