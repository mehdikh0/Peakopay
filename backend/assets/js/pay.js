/* Peakopay — payment page behaviour: countdown, polling, claim, copy. */
(function () {
  'use strict';

  var page = document.querySelector('.hp-page');
  if (!page) return;

  var token = page.getAttribute('data-token');
  var status = page.getAttribute('data-status');
  var expiresIn = parseInt(page.getAttribute('data-expires-in') || '0', 10);

  // ---------- helpers ----------
  function $(id) { return document.getElementById(id); }

  function toast(msg) {
    var t = document.createElement('div');
    t.className = 'hp-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; }, 2200);
    setTimeout(function () { t.remove(); }, 2600);
  }

  function copy(text, btn) {
    var done = function () {
      if (btn) {
        var old = btn.textContent;
        btn.textContent = (btn.getAttribute('data-copy-ok') || 'کپی شد ✓');
        setTimeout(function () { btn.textContent = old; }, 1600);
      } else { toast('کپی شد ✓'); }
    };
    var legacy = function () {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      ta.remove();
    };
    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { legacy(); done(); });
    } else { legacy(); done(); }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();
    copy(btn.getAttribute('data-copy'), btn);
  });

  // ---------- countdown ----------
  var total = expiresIn;
  var remaining = expiresIn;
  var timerEl = $('timer'), mm = $('mm'), ss = $('ss'), bar = $('countBar');
  function tick() {
    if (remaining <= 0) {
      if (timerEl) { timerEl.classList.add('out'); }
      if (mm && ss) { mm.textContent = '۰۰'; ss.textContent = '۰۰'; }
      if (bar) { bar.style.width = '0%'; }
      refresh();
      return;
    }
    remaining--;
    var m = Math.floor(remaining / 60), s = remaining % 60;
    if (mm) mm.textContent = (m < 10 ? '۰' : '') + toFa(m);
    if (ss) ss.textContent = (s < 10 ? '۰' : '') + toFa(s);
    if (bar && total > 0) bar.style.width = Math.max(0, (remaining / total) * 100) + '%';
    if (timerEl && remaining <= 300) timerEl.classList.add('warn');
    if (bar && remaining <= 300) bar.classList.add('warn');
    if (remaining <= 0) refresh();
  }
  function toFa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  if (remaining > 0 && timerEl) { tick(); setInterval(tick, 1000); }

  // ---------- status polling ----------
  var terminal = { paid: 1, expired: 1, failed: 1 };
  var pollTimer = null;

  function api(path, opts) {
    return fetch(location.pathname.replace(/\/+$/, '') + path, opts)
      .then(function (r) { return r.json(); });
  }

  function applyUpdate(p) {
    if (!p || p.status === status) return;
    status = p.status;
    if (terminal[status]) { location.reload(); return; }
    // pending -> verifying can be updated in place
    if (status === 'verifying') {
      var banner = $('statusBanner');
      if (banner) {
        banner.className = 'hp-status st-verifying';
        var icon = $('statusIcon'); if (icon) icon.innerHTML = '<span class="spinner"></span>';
        var title = $('statusTitle'); if (title) title.textContent = 'در حال بررسی پرداخت…';
        var text = $('statusText');
        if (text) text.textContent = 'اعلام پرداخت شما ثبت شد و در حال بررسی خودکار واریز هستیم. این صفحه را نبندید.';
      }
      var claim = $('claimBtn');
      if (claim) { claim.disabled = true; claim.textContent = 'در حال بررسی…'; }
    }
  }

  function refresh() {
    api('/status', { headers: { 'Accept': 'application/json' } })
      .then(function (j) { if (j && j.ok) applyUpdate(j.data.payment); })
      .catch(function () {});
  }

  if (!terminal[status]) {
    pollTimer = setInterval(refresh, 5000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !terminal[status]) refresh();
    });
  }

  // ---------- "I have paid" ----------
  var claimBtn = $('claimBtn');
  if (claimBtn) {
    claimBtn.addEventListener('click', function () {
      if (claimBtn.disabled) return;
      claimBtn.disabled = true;
      claimBtn.textContent = 'در حال ثبت…';
      api('/claim', { method: 'POST', headers: { 'Accept': 'application/json' } })
        .then(function (j) {
          if (j && j.ok) {
            applyUpdate(j.data.payment);
            toast('اعلام پرداخت ثبت شد؛ در حال بررسی…');
          } else {
            toast((j && j.error) ? j.error.message : 'خطا در ثبت. دوباره تلاش کنید.');
            claimBtn.disabled = false;
            claimBtn.textContent = '✓ پرداخت کردم';
          }
        })
        .catch(function () {
          toast('ارتباط برقرار نشد؛ دوباره تلاش کنید.');
          claimBtn.disabled = false;
          claimBtn.textContent = '✓ پرداخت کردم';
        });
    });
  }
})();
