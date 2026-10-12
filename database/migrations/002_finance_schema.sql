-- =====================================================================
-- MARWA — Migration 002 : paiements, commissions, grand livre, versements
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE users ADD CONSTRAINT fk_users_photo FOREIGN KEY (photo_file_id) REFERENCES files(id) ON DELETE SET NULL;

CREATE TABLE payments (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL UNIQUE,
  order_id         BIGINT UNSIGNED NOT NULL,
  payer_user_id    BIGINT UNSIGNED NULL,
  method           ENUM('cash','demo_mobile_money','mobile_money','card','business_account') NOT NULL,
  gateway          VARCHAR(30) NOT NULL COMMENT 'cash, demo, business, <fournisseur>',
  amount           INT UNSIGNED NOT NULL COMMENT 'Montant attendu',
  declared_amount  INT UNSIGNED NULL COMMENT 'Espèces : montant déclaré par le collecteur',
  verified_amount  INT UNSIGNED NULL COMMENT 'Montant vérifié (rapprochement)',
  fee_amount       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Frais du prestataire de paiement',
  status           ENUM('pending','declared','confirmed','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
  collector_type   ENUM('provider','merchant','gateway','business','marwa') NOT NULL,
  collector_id     BIGINT UNSIGNED NULL,
  idempotency_key  VARCHAR(80) NOT NULL,
  external_ref     VARCHAR(120) NULL,
  failure_reason   VARCHAR(255) NULL,
  verified_by      BIGINT UNSIGNED NULL,
  verified_at      DATETIME NULL,
  confirmed_at     DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_idem (idempotency_key),
  UNIQUE KEY uq_pay_external (gateway, external_ref),
  KEY idx_pay_order (order_id),
  KEY idx_pay_status (status, created_at),
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_pay_payer FOREIGN KEY (payer_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal brut des notifications reçues des prestataires de paiement (webhooks)
CREATE TABLE payment_webhooks (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway         VARCHAR(30) NOT NULL,
  event_id        VARCHAR(120) NOT NULL,
  external_ref    VARCHAR(120) NULL,
  signature_valid TINYINT(1) NOT NULL,
  payload         LONGTEXT NOT NULL,
  processed_at    DATETIME NULL,
  result          VARCHAR(255) NULL,
  received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_webhook_event (gateway, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       BIGINT UNSIGNED NOT NULL,
  payment_id     BIGINT UNSIGNED NULL,
  amount         INT UNSIGNED NOT NULL,
  reason         VARCHAR(255) NOT NULL,
  borne_by       ENUM('marwa','provider','merchant') NOT NULL DEFAULT 'marwa',
  status         ENUM('requested','approved','completed','rejected') NOT NULL DEFAULT 'requested',
  requested_by   BIGINT UNSIGNED NOT NULL,
  approved_by    BIGINT UNSIGNED NULL,
  complaint_id   BIGINT UNSIGNED NULL,
  ledger_txn_id  BIGINT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at   DATETIME NULL,
  KEY idx_refund_order (order_id),
  CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_refund_requester FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT chk_refund_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Détail des commissions calculées par commande (une ligne par partie)
CREATE TABLE commissions (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id         BIGINT UNSIGNED NOT NULL,
  party            ENUM('provider','merchant') NOT NULL,
  party_user_id    BIGINT UNSIGNED NOT NULL,
  rule_id          INT UNSIGNED NULL,
  model            VARCHAR(20) NOT NULL,
  base_amount      INT UNSIGNED NOT NULL,
  percent_bp       INT UNSIGNED NOT NULL DEFAULT 0,
  fixed_amount     INT UNSIGNED NOT NULL DEFAULT 0,
  commission       INT UNSIGNED NOT NULL,
  vat_amount       INT UNSIGNED NOT NULL DEFAULT 0,
  partner_net      INT NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commission (order_id, party),
  CONSTRAINT fk_comm_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_comm_rule FOREIGN KEY (rule_id) REFERENCES commission_rules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Grand livre interne en partie double (écritures immuables)
-- Comptes de partenaire : solde créditeur = MARWA doit au partenaire ;
-- solde débiteur = le partenaire doit à MARWA (ex. commission sur espèces).
-- ---------------------------------------------------------------------
CREATE TABLE ledger_accounts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40)  NOT NULL UNIQUE,
  name         VARCHAR(150) NOT NULL,
  type         ENUM('asset','liability','revenue','expense','equity') NOT NULL,
  owner_type   ENUM('system','provider','merchant','business') NOT NULL DEFAULT 'system',
  owner_id     BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ledger_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ledger_transactions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  kind          VARCHAR(40) NOT NULL COMMENT 'settlement, payment, refund, payout, remittance, expense, adjustment, subscription, fee',
  dedupe_key    VARCHAR(120) NULL COMMENT 'Empêche la double comptabilisation',
  order_id      BIGINT UNSIGNED NULL,
  description   VARCHAR(255) NOT NULL,
  reverses_id   BIGINT UNSIGNED NULL,
  created_by    BIGINT UNSIGNED NULL,
  occurred_at   DATETIME NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ledger_dedupe (dedupe_key),
  KEY idx_lt_order (order_id),
  KEY idx_lt_kind (kind, occurred_at),
  CONSTRAINT fk_lt_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_lt_reverses FOREIGN KEY (reverses_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ledger_entries (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id  BIGINT UNSIGNED NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  debit           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  credit          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  memo            VARCHAR(190) NULL,
  occurred_at     DATETIME NOT NULL,
  KEY idx_le_account (account_id, occurred_at),
  KEY idx_le_txn (transaction_id),
  CONSTRAINT fk_le_txn FOREIGN KEY (transaction_id) REFERENCES ledger_transactions(id),
  CONSTRAINT fk_le_account FOREIGN KEY (account_id) REFERENCES ledger_accounts(id),
  CONSTRAINT chk_le_side CHECK ((debit = 0 AND credit > 0) OR (credit = 0 AND debit > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Soldes des partenaires calculés depuis le grand livre (positif = MARWA doit au partenaire)
CREATE VIEW partner_balances AS
  SELECT a.id AS account_id, a.owner_type, a.owner_id, a.name,
         COALESCE(SUM(e.credit),0) - COALESCE(SUM(e.debit),0) AS balance
    FROM ledger_accounts a
    LEFT JOIN ledger_entries e ON e.account_id = a.id
   WHERE a.owner_type IN ('provider','merchant','business')
   GROUP BY a.id, a.owner_type, a.owner_id, a.name;

CREATE TABLE payouts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  direction       ENUM('to_partner','from_partner') NOT NULL DEFAULT 'to_partner' COMMENT 'from_partner = reversement de commissions encaissées en espèces',
  partner_type    ENUM('provider','merchant') NOT NULL,
  partner_user_id BIGINT UNSIGNED NOT NULL,
  amount          INT UNSIGNED NOT NULL,
  method          VARCHAR(40) NOT NULL COMMENT 'cash, mobile_money, bank_transfer',
  destination     VARCHAR(120) NULL COMMENT 'N° Mobile Money / IBAN partiel',
  status          ENUM('requested','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'requested',
  reference       VARCHAR(120) NULL COMMENT 'Référence du justificatif',
  proof_file_id   BIGINT UNSIGNED NULL,
  note            VARCHAR(255) NULL,
  requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by     BIGINT UNSIGNED NULL,
  reviewed_at     DATETIME NULL,
  paid_at         DATETIME NULL,
  ledger_txn_id   BIGINT UNSIGNED NULL,
  KEY idx_payout_partner (partner_user_id, status),
  KEY idx_payout_status (status),
  CONSTRAINT fk_payout_partner FOREIGN KEY (partner_user_id) REFERENCES users(id),
  CONSTRAINT fk_payout_proof FOREIGN KEY (proof_file_id) REFERENCES files(id) ON DELETE SET NULL,
  CONSTRAINT chk_payout_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category      VARCHAR(60) NOT NULL,
  description   VARCHAR(255) NOT NULL,
  amount        INT UNSIGNED NOT NULL,
  expense_date  DATE NOT NULL,
  city_id       INT UNSIGNED NULL,
  paid_from     ENUM('cash','bank','mobile_money') NOT NULL DEFAULT 'cash',
  receipt_file_id BIGINT UNSIGNED NULL,
  created_by    BIGINT UNSIGNED NOT NULL,
  ledger_txn_id BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_expense_date (expense_date),
  CONSTRAINT fk_expense_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL,
  CONSTRAINT fk_expense_user FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_expense_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriptions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  partner_type    ENUM('provider','merchant','business') NOT NULL,
  partner_user_id BIGINT UNSIGNED NOT NULL,
  plan_name       VARCHAR(80) NOT NULL,
  amount          INT UNSIGNED NOT NULL,
  period_start    DATE NOT NULL,
  period_end      DATE NOT NULL,
  status          ENUM('active','billed','cancelled') NOT NULL DEFAULT 'active',
  ledger_txn_id   BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sub_period (partner_user_id, period_start),
  CONSTRAINT fk_sub_user FOREIGN KEY (partner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number        VARCHAR(30) NOT NULL UNIQUE,
  kind          ENUM('receipt','business_statement','partner_statement') NOT NULL,
  order_id      BIGINT UNSIGNED NULL,
  business_id   BIGINT UNSIGNED NULL,
  user_id       BIGINT UNSIGNED NULL,
  period_start  DATE NULL,
  period_end    DATE NULL,
  amount        BIGINT NOT NULL,
  status        ENUM('issued','paid','void') NOT NULL DEFAULT 'issued',
  issued_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at       DATETIME NULL,
  UNIQUE KEY uq_invoice_order (kind, order_id),
  KEY idx_invoice_business (business_id),
  CONSTRAINT fk_invoice_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_invoice_business FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immuabilité du grand livre : aucune modification ni suppression possible
CREATE TRIGGER trg_ledger_entries_no_update BEFORE UPDATE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries est immuable : passez une ecriture d ajustement';

CREATE TRIGGER trg_ledger_entries_no_delete BEFORE DELETE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries est immuable : passez une ecriture d ajustement';

CREATE TRIGGER trg_ledger_txn_no_update BEFORE UPDATE ON ledger_transactions
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_transactions est immuable';

CREATE TRIGGER trg_ledger_txn_no_delete BEFORE DELETE ON ledger_transactions
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_transactions est immuable';
