-- Module Banques et coffres : comptes (banque, coffre, propriétaire, mobile money…) et opérations

CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  kind ENUM('banque','coffre','proprietaire','mobile','autre') NOT NULL DEFAULT 'banque',
  bank_name VARCHAR(100) NULL,
  account_number VARCHAR(60) NULL,
  holder VARCHAR(120) NULL,
  opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
  balance DECIMAL(15,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bank_account_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  type ENUM('versement','depot','retrait','virement_sortant','virement_entrant','frais','interets','ajustement') NOT NULL,
  direction TINYINT NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  op_date DATE NOT NULL,
  method VARCHAR(20) NOT NULL DEFAULT 'especes',
  reference VARCHAR(80) NULL,
  person VARCHAR(120) NULL,
  description VARCHAR(255) NULL,
  cash_session_id INT UNSIGNED NULL,
  transfer_id BIGINT UNSIGNED NULL,
  counter_account_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  cancelled_at DATETIME NULL,
  cancelled_by INT UNSIGNED NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bt_account (account_id, op_date),
  INDEX idx_bt_session (cash_session_id),
  INDEX idx_bt_transfer (transfer_id),
  INDEX idx_bt_number (number),
  CONSTRAINT fk_bt_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id),
  CONSTRAINT fk_bt_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id),
  CONSTRAINT fk_bt_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('banque', 'BQ', 1, YEAR(CURDATE()));
