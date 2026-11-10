<div class="card">
  <div class="card-title">کارت مقصد واریز</div>
  <div class="alert info">مشتریان مبلغ سفارش را به این کارت واریز می‌کنند. شماره کارت با AES-256 رمزنگاری شده و در همه جا فقط به‌صورت ماسک‌شده نمایش داده می‌شود.</div>
  <form method="post" action="?step=5">
    <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
    <div class="field"><label>نام بانک</label>
      <input type="text" name="bank_name" required placeholder="مثلاً بانک ملت"></div>
    <div class="field"><label>نام صاحب کارت</label>
      <input type="text" name="card_holder" required></div>
    <div class="field"><label>شماره کارت (۱۶ رقم)</label>
      <input type="text" name="card_number" dir="ltr" class="mono" required placeholder="6219 8612 3456 1234" inputmode="numeric"></div>
    <div class="form-row">
      <div class="field"><label>شماره شبا (اختیاری)</label>
        <input type="text" name="iban" dir="ltr" class="mono" placeholder="IR..."></div>
      <div class="field"><label>شماره حساب (اختیاری)</label>
        <input type="text" name="account_number" dir="ltr" class="mono"></div>
    </div>
    <button class="btn btn-primary btn-block">ساخت فروشگاه و ادامه →</button>
  </form>
</div>
