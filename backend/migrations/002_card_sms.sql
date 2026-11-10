-- Peakopay 002 — per-card SMS senders + parser rules (card-configurable SMS engine)

ALTER TABLE cards ADD COLUMN label VARCHAR(120) NOT NULL DEFAULT '' AFTER store_id;

CREATE TABLE card_senders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id INT UNSIGNED NOT NULL,
  sender_raw VARCHAR(40) NOT NULL,
  sender_norm VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_card_sender (card_id, sender_norm),
  KEY idx_sender_norm (sender_norm),
  CONSTRAINT fk_senders_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE card_parser_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  priority INT NOT NULL DEFAULT 100,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  rule_json MEDIUMTEXT NOT NULL,
  sample_text TEXT NULL,
  tested TINYINT(1) NOT NULL DEFAULT 0,
  last_matched_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_rules_card (card_id, enabled, priority),
  CONSTRAINT fk_rules_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions
  ADD COLUMN card_id INT UNSIGNED NULL AFTER device_id,
  ADD COLUMN fingerprint CHAR(64) NULL AFTER raw_hash,
  ADD KEY idx_txn_card (card_id),
  ADD UNIQUE KEY uq_txn_fp (fingerprint),
  ADD CONSTRAINT fk_txn_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE SET NULL;
