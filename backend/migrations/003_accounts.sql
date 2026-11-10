-- Peakopay 003 — multi-store accounts (account ↔ stores ↔ members with roles)
-- روند ارتقا ایمن است: فقط جدول/ستون اضافه می‌شود؛ داده‌های موجود با backfill پی‌اچ‌پی
-- (Account::backfill در Installer::migrate) به حساب‌ها متصل می‌شوند.

CREATE TABLE accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_accounts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- عضویت کاربر در فروشگاه با نقش محلی — منبع حقیقتِ «کدام فروشگاه‌ها»
CREATE TABLE store_members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_store_member (store_id, user_id),
  KEY idx_sm_user (user_id),
  CONSTRAINT fk_sm_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  CONSTRAINT fk_sm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN account_id INT UNSIGNED NULL AFTER store_id;
ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
ALTER TABLE users ADD KEY idx_users_account (account_id);
ALTER TABLE users ADD CONSTRAINT fk_users_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE;

ALTER TABLE stores ADD COLUMN account_id INT UNSIGNED NULL AFTER id;
ALTER TABLE stores ADD COLUMN domain VARCHAR(190) NOT NULL DEFAULT '' AFTER name;
ALTER TABLE stores ADD COLUMN connection_status VARCHAR(20) NOT NULL DEFAULT 'connected' AFTER domain;
ALTER TABLE stores ADD KEY idx_stores_account (account_id);
ALTER TABLE stores ADD CONSTRAINT fk_stores_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL;

-- دستگاهِ «ورود با حساب» هم به حساب متصل است (store_id = فروشگاه اصلی/اول؛
-- دامنهٔ واقعی داده‌ها از طریق account_id همهٔ فروشگاه‌های حساب است)
ALTER TABLE devices ADD COLUMN account_id INT UNSIGNED NULL AFTER store_id;
ALTER TABLE devices ADD KEY idx_devices_account (account_id);
ALTER TABLE devices ADD CONSTRAINT fk_devices_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL;

-- منشأ دستگاه: «pair» = همراه‌سازی با کدِ یک فروشگاه مشخص (فروشگاهش unambiguous)؛
-- «login» = ورود با حساب (چندفروشگاهی — بدون card_id نباید به فروشگاهی دلخواه وصل شود)
ALTER TABLE devices ADD COLUMN source VARCHAR(12) NOT NULL DEFAULT 'pair';

-- دور ۲۳ — تنظیمات سطحِ «نصب» (کرون برای همهٔ فروشگاه‌هاست؛ توکن کرون و
-- مقادیر آیندهٔ نصب-محور اینجا می‌نشینند، جدا از settings که per-store است
-- و FK به stores دارد).
CREATE TABLE IF NOT EXISTS install_meta (
    skey       VARCHAR(64)  NOT NULL,
    svalue     TEXT         NOT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- backfill: توکن کرونِ سطح‌فروشگاهِ نصب‌های قدیمی به سطح نصب منتقل شود
-- (INSERT IGNORE → idempotent؛ فقط بار نخست).
INSERT IGNORE INTO install_meta (skey, svalue)
SELECT 'cron_token', svalue FROM settings
WHERE skey = 'cron_token' AND store_id > 0
ORDER BY store_id LIMIT 1;
