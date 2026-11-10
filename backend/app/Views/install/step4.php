<div class="card">
  <div class="card-title">اطلاعات فروشگاه</div>
  <form method="post" action="?step=4">
    <input type="hidden" name="_csrf" value="<?= Helpers::e(Session::csrfToken()) ?>">
    <div class="field"><label>نام فروشگاه</label>
      <input type="text" name="store_name" required placeholder="مثلاً فروشگاه آریا"></div>
    <div class="field"><label>آدرس سایت فروشگاه (اختیاری)</label>
      <input type="url" name="store_url" dir="ltr" placeholder="https://shop.example.com"></div>
    <div class="form-row">
      <div class="field"><label>تلفن پشتیبانی (اختیاری)</label>
        <input type="text" name="support_phone" dir="ltr" placeholder="09123456789"></div>
      <div class="field"><label>تلگرام پشتیبانی (اختیاری)</label>
        <input type="text" name="support_telegram" dir="ltr" placeholder="@support"></div>
    </div>
    <div class="form-row">
      <div class="field"><label>واحد نمایش مبالغ</label>
        <select name="currency">
          <option value="IRT">تومان</option>
          <option value="IRR">ریال</option>
        </select>
        <div class="hint">سیستم همیشه با ریال کار می‌کند؛ این فقط نحوه نمایش است.</div></div>
      <div class="field"><label>منطقه زمانی</label>
        <select name="timezone">
          <option value="Asia/Tehran">تهران (ایران)</option>
        </select></div>
    </div>
    <button class="btn btn-primary btn-block">ادامه →</button>
  </form>
</div>
