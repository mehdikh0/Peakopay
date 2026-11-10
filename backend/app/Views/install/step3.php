<div class="card">
  <div class="card-title">حساب مدیر پنل</div>
  <form method="post" action="?step=3">
    <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
    <div class="field"><label>نام و نام خانوادگی</label>
      <input type="text" name="admin_name" required></div>
    <div class="field"><label>ایمیل (نام کاربری ورود)</label>
      <input type="email" name="admin_email" dir="ltr" required></div>
    <div class="form-row">
      <div class="field"><label>رمز عبور (حداقل ۸ کاراکتر)</label>
        <input type="password" name="admin_pass" dir="ltr" minlength="8" required></div>
      <div class="field"><label>تکرار رمز عبور</label>
        <input type="password" name="admin_pass2" dir="ltr" minlength="8" required></div>
    </div>
    <button class="btn btn-primary btn-block">ادامه →</button>
  </form>
</div>
