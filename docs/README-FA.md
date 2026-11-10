# پیکو پی (Peakopay)

سامانه پرداخت **کارت به کارت** برای فروشگاه‌های ووکامرسی — کاملاً روی هاست خودتان (cPanel)، بدون SSH، بدون Cron، بدون Composer.

```
سفارش ووکامرس ──► صفحه پرداخت پیکو پی (کارت مقصد + مبلغ دقیق + شمارش معکوس)
      ▲                                                      │
      │                                                       ▼ مشتری واریز می‌کند
وب‌هوک امضادار ◄── تطبیق ◄── اپ اندروید فروشنده (پیامک بانکی) ─┘
      │
      ▼
سفارش «پرداخت‌شده» ✅ — صفحه پرداخت مشتری هم همان لحظه به‌روز می‌شود
```

## ویژگی‌ها

- **نصب یک‌کلیکی وبی** در ۷ گام (`/install`) — بدون ترمینال، بدون SQL دستی؛ جدول‌ها و مهاجرت‌ها خودکار.
- **بدون Cron:** تطبیق و تأیید پرداخت **آنی و در همان لحظه** انجام می‌شود.
- **بدون وابستگی:** PHP خالص ۸.۱+ و PDO — نه فریم‌ورک، نه Composer، نه Node.
- **امنیت جدی:** رمزنگاری AES-256-GCM (Sodium یا OpenSSL)، وب‌هوک‌های HMAC-SHA256 با محافظت replay، کلیدهای هش‌شده، CSRF، rate limit، قفل تراکنشی، لاگ ممیزی. جزئیات: `docs/SECURITY.md`
- **مبلغ یونیک:** هر پرداخت تا ۹۹۹ ریال مبلغ یکتا می‌گیرد تا واریزهای هم‌مبلغ اشتباه نشوند.
- **حالت تست امن:** پرداخت و تراکنش تستی با برچسب «تست» — هرگز با واقعی اشتباه نمی‌شود.
- **اپ اندروید فارسی:** همراه‌سازی با کد کوتاه + تأیید مدیر، صف ارسال آفلاین، داشبورد و اعلان.
- **رابط کاربری فارسی راست‌به‌چپ** با طراحی متریال مدرن (آبی/بنفش/سفید) و ارقام فارسی.

## ساختار پروژه

```
peakopay/
├── backend/          سرور (PHP 8.1+، بدون وابستگی) — قابل استقرار مستقیم به‌عنوان Document Root
│   ├── index.php     Front controller — همه درخواست‌ها از اینجا می‌گذرند
│   ├── .htaccess     مسیریابی + امنیت + مسدودسازی app/config/storage/ از وب
│   ├── cron.php      نگهداری دوره‌ای (کران cPanel یا HTTP)
│   ├── .env.example  نمونه تنظیمات محیطی (اختیاری)
│   ├── app/          Core + Models + Services + Controllers + Views   ← از وب 403
│   ├── config/       config.sample.php + sms-rules.json (قواعد پیامک)  ← از وب 403
│   ├── migrations/   001 تا 004 (جدول‌ها + ایندکس‌ها، اعمال خودکار توسط نصب‌کننده) ← از وب 403
│   ├── storage/      لاگ/کش/قفل نصب                                     ← از وب 403
│   └── assets/       CSS/JS/فونت/آیکون (عمومی)
├── woocommerce/peakopay/  افزونه ووکامرس (درگاه + وب‌هوک + تنظیمات فارسی)
├── android/          اپ اندروید (Kotlin + Compose) + Gradle Wrapper (فایل‌های امضا git-ignored)
├── docs/             راهنماهای فارسی: CPANEL / WOOCOMMERCE / ANDROID / 2FA / TROUBLESHOOTING / SECURITY / INSTALL-FA
├── tests/            ۴۰۰ تست واحد + ۳۴۵ تست end-to-end HTTP (مجموع ۷۴۵؛ نصب‌کننده تا وب‌هوک، 2FA و سناریوهای وردپرس)
├── install/          (نصب از طریق وب است؛ این پوشه خالی/راهنماست)
├── README.md         معرفی انگلیسی مخزن
├── INSTALL.md        راهنمای نصب (انگلیسی)
├── LICENSE           GPL-2.0-or-later
└── CHANGELOG — تاریخچهٔ کامل در docs/dev-history (آرشیو داخلی)
```

> **Document Root = خود پوشه `backend/`** (مثلاً Extract در `public_html/pay` و Document Root همان `pay`). پوشه `public/` نسخه‌های قبلی حذف شده است.

## وضعیت‌ها

- پرداخت: `WAITING` (در انتظار) → `VERIFYING` (در حال بررسی) → `PAID` (پرداخت‌شده) / `EXPIRED` (منقضی) / `FAILED`
- تراکنش: `RECEIVED` (دریافت) → `MATCHED` (تطبیق‌شده) → `CONFIRMED` (تأییدشده) / `DUPLICATE` / `UNMATCHED` (بدون تطبیق) / `REJECTED` (رد)

## API (خلاصه)

پاکت: `{"ok":true,"data":{…}}` یا `{"ok":false,"error":{"code","message"}}`

| Endpoint | کاربر | احراز هویت |
|---|---|---|
| `POST /api/v1/payments/create` | افزونه ووکامرس | `st_` + `hpk_` |
| `GET /api/v1/payments/{token}` | افزونه ووکامرس | `st_` + `hpk_` |
| `POST /api/v1/test-connection` | افزونه ووکامرس | `st_` + `hpk_` |
| `POST /api/v1/auth/pair` | اپ اندروید | کد کوتاه (۱۰ دقیقه) |
| `POST /api/v1/devices/register` | اپ اندروید | HMAC دستگاه |
| `POST /api/v1/devices/heartbeat` | اپ اندروید | HMAC دستگاه |
| `GET /api/v1/store/status` | اپ اندروید / ووکامرس | HMAC دستگاه یا `st_`+`hpk_` |
| `POST /api/v1/transactions` | اپ اندروید (تأییدشده) | HMAC دستگاه |
| `GET /health` | عمومی | — (فقط اطلاعات امن) |

## تست‌ها

```
php tests/run.php          # کل مجموعه: unit + e2e
php tests/run.php unit     # ۱۰۶ تست واحد (رمزنگاری، اعتبارسنجی، تطبیق، پارسر پیامک، امضا…)
php tests/run.php e2e      # ۹۴ تست HTTP: نصب‌کننده ۷گامی، CSRF، rate limit، همراه‌سازی،
                           #   آپلود تراکنش، تطبیق، وب‌هوک، انقضا، حالت تست…
```

پیش‌نیاز: PHP 8.1+ و MySQL/MariaDB (تنظیم با متغیرهای `HP_TEST_DB*`).

## نیازمندی‌ها

- **سرور:** PHP 8.1+ (توصیه ۸.۲+)، pdo_mysql، openssl (sodium اختیاری — نبودش مانع نصب نیست)، MariaDB/MySQL 10.3+، HTTPS
- **فروشگاه:** WordPress 5.8+ / WooCommerce 5.5+
- **اپ:** Android 8.0+ (ساخت از سورس با Android Studio)

## مجوز

این پروژه برای استفاده تجاری فروشگاه شما ساخته شده است. تمام داده‌ها (پیامک‌ها، پرداخت‌ها، تنظیمات) فقط روی سرور خودتان ذخیره می‌شود.
