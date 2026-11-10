-- Peakopay — initial schema (MySQL 8+ / MariaDB 10.6+)
-- Applied automatically by the web installer. Never run manually.
-- All timestamps are UTC.

CREATE TABLE stores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(32) NOT NULL,
  name VARCHAR(190) NOT NULL,
  url VARCHAR(255) NOT NULL DEFAULT '',
  support_phone VARCHAR(40) NOT NULL DEFAULT '',
  support_telegram VARCHAR(120) NOT NULL DEFAULT '',
  currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Tehran',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_stores_public (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_store (store_id),
  CONSTRAINT fk_users_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  skey VARCHAR(80) NOT NULL,
  svalue MEDIUMTEXT NOT NULL,
  UNIQUE KEY uq_settings (store_id, skey),
  CONSTRAINT fk_settings_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  bank_name VARCHAR(80) NOT NULL,
  holder VARCHAR(120) NOT NULL,
  number_enc TEXT NOT NULL,
  number_last4 VARCHAR(4) NOT NULL DEFAULT '',
  iban_enc TEXT NULL,
  account_enc TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_cards_store (store_id, is_active),
  CONSTRAINT fk_cards_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  model VARCHAR(120) NOT NULL DEFAULT '',
  android_version VARCHAR(20) NOT NULL DEFAULT '',
  app_version VARCHAR(20) NOT NULL DEFAULT '',
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  secret_enc TEXT NOT NULL,
  last_seen_at DATETIME NULL,
  last_sms_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  paired_at DATETIME NOT NULL,
  approved_at DATETIME NULL,
  KEY idx_devices_store (store_id, status),
  CONSTRAINT fk_devices_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pairing_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_pc_store (store_id),
  KEY idx_pc_expiry (expires_at),
  CONSTRAINT fk_pc_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL DEFAULT 'WooCommerce',
  prefix VARCHAR(16) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_apikey_hash (key_hash),
  KEY idx_apikey_store (store_id, revoked_at),
  CONSTRAINT fk_apikey_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  card_id INT UNSIGNED NULL,
  token CHAR(40) NOT NULL,
  order_id VARCHAR(64) NOT NULL DEFAULT '',
  order_key VARCHAR(80) NOT NULL DEFAULT '',
  amount_base_rial BIGINT NOT NULL,
  amount_rial BIGINT NOT NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'IRR',
  amount_display BIGINT NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  customer_name VARCHAR(160) NOT NULL DEFAULT '',
  customer_phone VARCHAR(40) NOT NULL DEFAULT '',
  description VARCHAR(255) NOT NULL DEFAULT '',
  redirect_url VARCHAR(500) NOT NULL DEFAULT '',
  expected_reference VARCHAR(80) NOT NULL DEFAULT '',
  transaction_id INT UNSIGNED NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  woo_notify_status VARCHAR(16) NOT NULL DEFAULT '',
  woo_notified_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_pay_token (token),
  UNIQUE KEY uq_pay_txn (transaction_id),
  KEY idx_pay_match (store_id, status, amount_rial, expires_at),
  KEY idx_pay_order (store_id, order_id),
  KEY idx_pay_created (created_at),
  CONSTRAINT fk_pay_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NOT NULL,
  bank VARCHAR(60) NOT NULL DEFAULT '',
  sender VARCHAR(60) NOT NULL DEFAULT '',
  amount_rial BIGINT NOT NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'IRR',
  occurred_at DATETIME NOT NULL,
  dest_last4 VARCHAR(4) NOT NULL DEFAULT '',
  reference VARCHAR(80) NOT NULL DEFAULT '',
  raw_hash CHAR(64) NOT NULL,
  raw_enc TEXT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'received',
  payment_id INT UNSIGNED NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_txn_rawhash (raw_hash),
  UNIQUE KEY uq_txn_payment (payment_id),
  KEY idx_txn_store (store_id, status, created_at),
  KEY idx_txn_amount (store_id, amount_rial),
  KEY idx_txn_ref (reference),
  KEY idx_txn_device (device_id),
  CONSTRAINT fk_txn_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  CONSTRAINT fk_txn_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_txn_payment FOREIGN KEY (payment_id) REFERENCES payment_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE idempotency_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(40) NOT NULL,
  ikey VARCHAR(120) NOT NULL,
  response_hash CHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_idem (scope, ikey),
  KEY idx_idem_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  payment_id INT UNSIGNED NOT NULL,
  event VARCHAR(40) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_wh_payment (payment_id),
  KEY idx_wh_status (status, created_at),
  CONSTRAINT fk_wh_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  CONSTRAINT fk_wh_payment FOREIGN KEY (payment_id) REFERENCES payment_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  store_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(64) NOT NULL DEFAULT '',
  request_id VARCHAR(24) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY idx_audit_created (created_at),
  KEY idx_audit_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  channel VARCHAR(20) NOT NULL,
  event VARCHAR(40) NOT NULL,
  message VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  error VARCHAR(300) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  KEY idx_notif_store (store_id, created_at),
  CONSTRAINT fk_notif_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  bucket VARCHAR(120) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  blocked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

