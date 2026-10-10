-- =====================================================================
-- POS Wan — migration 0003 : imprimantes, devis, langues, canaux de vente,
-- opérateurs mobile money
-- =====================================================================

-- Imprimantes : plusieurs imprimantes branchées sur le poste ou en réseau
CREATE TABLE IF NOT EXISTS printers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  driver ENUM('browser','network','windows') NOT NULL DEFAULT 'browser',
  address VARCHAR(255) NULL,          -- 192.168.1.50:9100 ou \\localhost\XP-80
  paper ENUM('58','80','a4') NOT NULL DEFAULT '80',
  usages VARCHAR(255) NOT NULL DEFAULT 'receipt',  -- receipt, invoice, kitchen:cuisine, kitchen:bar…
  register_id INT UNSIGNED NULL,      -- caisse concernée (NULL = toutes)
  copies TINYINT UNSIGNED NOT NULL DEFAULT 1,
  cut TINYINT(1) NOT NULL DEFAULT 1,
  open_drawer TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_printer_reg FOREIGN KEY (register_id) REFERENCES cash_registers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canal de vente : boutique uniquement, restaurant uniquement, ou les deux
ALTER TABLE products ADD COLUMN sales_channel ENUM('all','shop','restaurant') NOT NULL DEFAULT 'all' AFTER sellable;
ALTER TABLE categories ADD COLUMN sales_channel ENUM('all','shop','restaurant') NOT NULL DEFAULT 'all' AFTER kitchen_station;

-- Opérateur de mobile money utilisé (Airtel Money, Moov Money, Zamani Cash…)
ALTER TABLE sale_payments ADD COLUMN provider VARCHAR(50) NULL AFTER method;

-- Langue préférée de l'utilisateur (NULL = langue par défaut)
ALTER TABLE users ADD COLUMN locale VARCHAR(5) NULL AFTER phone;

-- Devis
CREATE TABLE IF NOT EXISTS quotes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  store_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  customer_name VARCHAR(150) NULL,
  customer_phone VARCHAR(50) NULL,
  customer_address VARCHAR(255) NULL,
  valid_until DATE NULL,
  status ENUM('draft','sent','accepted','refused','converted','cancelled') NOT NULL DEFAULT 'draft',
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  note TEXT NULL,
  sale_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_quote_status (status),
  CONSTRAINT fk_quote_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_quote_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_quote_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  unit_price DECIMAL(15,2) NOT NULL,
  discount DECIMAL(15,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_qi_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_qi_prod FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('quote', 'DV', 1, YEAR(CURDATE()));
INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('currency_code', 'XOF'), ('currency_symbol', 'F'), ('currency_name', 'francs CFA'), ('currency_position', 'after'), ('currency_decimals', '0'),
  ('default_locale', 'fr'), ('ticket_template', '1'), ('invoice_template', '1'), ('receipt_qr', '1'), ('pos_quick_validate', '1'),
  ('mobile_money_providers', 'Airtel Money,Moov Money,Zamani Cash,Nita,Amanata'),
  ('quote_validity_days', '15');
-- Les bons cuisine s'impriment désormais à la demande (l'écran cuisine affiche les commandes)
UPDATE settings SET svalue = '0' WHERE skey = 'restaurant_print_kitchen';
