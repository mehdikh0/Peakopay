-- Peakopay 004 — Two-Factor Authentication (TOTP + recovery codes + challenges)
-- دور ۲۹ — ورود دومرحله‌ای آفلاین (RFC 6238) برای پنل مدیریت.
-- روند ارتقا ایمن است: فقط جدول‌های جدید اضافه می‌شوند؛ هیچ جدول/رکورد موجودی
-- تغییر یا حذف نمی‌شود — کاربران بدون 2FA مثل قبل وارد می‌شوند.

-- پیکربندی 2FA هر کاربر: راز TOTP فقط به‌صورت رمزنگاری‌شده (Crypto::encrypt با
-- کلید نصب) ذخیره می‌شود؛ enabled=0 یعنی «در انتظار تأیید اولین کد».
-- last_step = آخرین گام زمانیِ پذیرفته‌شده (جلوگیری از Replay همان کد).
CREATE TABLE user_2fa (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  secret_enc TEXT NOT NULL,
  enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
  enabled_at DATETIME NULL,
  last_step BIGINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_2fa_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- کدهای بازیابی اضطراری — فقط هش SHA-256 ذخیره می‌شود؛ هر کد یک‌بارمصرف
-- (used_at پر می‌شود) و هنگام بازتولید، همهٔ قبلی‌ها حذف می‌شوند.
CREATE TABLE user_recovery_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rc_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- چالش ورود دومرحله‌ای: بعد از تأیید رمز عبور، یک توکن تصادفی در نشست و
-- «هش SHA-256» آن اینجا ذخیره می‌شود (10 دقیقه اعتبار، سقف 5 تلاش).
CREATE TABLE auth_challenges (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_ch_user (user_id),
  KEY idx_ch_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- نشانگر ابطال نشست‌ها: هر نشستِ با login_at قدیمی‌تر از revoked_at باطل
-- می‌شود (پس از ریست ادمینی/تغییر امنیتی) — بدون نیاز به دسترسی به
-- فایل‌های session هاست اشتراکی.
CREATE TABLE user_session_revocations (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  revoked_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سوابق ریست ادمینی 2FA (هویت ادمین + کاربر + دلیل + زمان) — جدا از
-- audit_logs برای نمایش اعلان به کاربر تا زمان دیدن (seen_at).
CREATE TABLE twofactor_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT UNSIGNED NOT NULL,
  target_user_id INT UNSIGNED NOT NULL,
  reason VARCHAR(480) NOT NULL,
  seen_at DATETIME NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY idx_tfr_target (target_user_id, seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
