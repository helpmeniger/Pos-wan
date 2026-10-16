-- =====================================================================
-- POS Marwa — Schéma de base de données (MySQL 8 / MariaDB 10.4+)
-- Encodage : utf8mb4. Moteur : InnoDB (transactions + clés étrangères).
-- Ce fichier crée la version 1 du schéma. Les évolutions ultérieures
-- sont dans database/migrations/ (appliquées par bin/migrate.php).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Paramètres, migrations, séquences de numérotation
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(100) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(100) NOT NULL PRIMARY KEY,
  svalue TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sequences (
  name VARCHAR(50) NOT NULL PRIMARY KEY,
  prefix VARCHAR(20) NOT NULL,
  next_value INT UNSIGNED NOT NULL DEFAULT 1,
  year SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Boutiques, entrepôts, caisses
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(20) NOT NULL UNIQUE,
  address VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(20) NOT NULL UNIQUE,
  is_sales_point TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wh_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_registers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reg_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_reg_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Utilisateurs, rôles, permissions, sécurité
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  module VARCHAR(50) NOT NULL,
  label VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NULL,
  badge_hash CHAR(64) NULL UNIQUE,
  role_id INT UNSIGNED NOT NULL,
  store_id INT UNSIGNED NULL,
  register_id INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_user_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_user_reg FOREIGN KEY (register_id) REFERENCES cash_registers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(100) NULL,
  method VARCHAR(20) NOT NULL DEFAULT 'password',
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_ip (ip, created_at),
  INDEX idx_la_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(50) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_date (created_at),
  INDEX idx_audit_entity (entity, entity_id),
  INDEX idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Catalogue
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#0d6efd',
  icon VARCHAR(50) NULL,
  image VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  short_name VARCHAR(10) NOT NULL,
  allow_decimal TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  contact_name VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  nif VARCHAR(50) NULL,
  balance DECIMAL(15,2) NOT NULL DEFAULT 0, -- dette envers le fournisseur (positif = on doit)
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NULL,                 -- produit parent si variante
  reference VARCHAR(50) NOT NULL UNIQUE,
  barcode VARCHAR(64) NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  category_id INT UNSIGNED NULL,
  brand_id INT UNSIGNED NULL,
  unit_id INT UNSIGNED NULL,
  supplier_id INT UNSIGNED NULL,
  description TEXT NULL,
  variant_size VARCHAR(50) NULL,
  variant_color VARCHAR(50) NULL,
  cost_price DECIMAL(15,2) NOT NULL DEFAULT 0,   -- coût moyen pondéré (CMP)
  sale_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(15,2) NULL,
  wholesale_min_qty DECIMAL(15,3) NULL,
  promo_price DECIMAL(15,2) NULL,
  promo_start DATE NULL,
  promo_end DATE NULL,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  min_stock DECIMAL(15,3) NOT NULL DEFAULT 0,
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  track_lots TINYINT(1) NOT NULL DEFAULT 0,    -- lots + péremption (FEFO)
  sold_by_weight TINYINT(1) NOT NULL DEFAULT 0,
  image VARCHAR(255) NULL,
  -- Pharmacie
  is_medicine TINYINT(1) NOT NULL DEFAULT 0,
  active_ingredient VARCHAR(200) NULL,
  dosage VARCHAR(100) NULL,
  pharma_form VARCHAR(100) NULL,
  presentation VARCHAR(150) NULL,
  requires_prescription TINYINT(1) NOT NULL DEFAULT 0,
  is_controlled TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_prod_name (name),
  INDEX idx_prod_cat (category_id),
  INDEX idx_prod_status (status),
  CONSTRAINT fk_prod_parent FOREIGN KEY (parent_id) REFERENCES products(id),
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_prod_brand FOREIGN KEY (brand_id) REFERENCES brands(id),
  CONSTRAINT fk_prod_unit FOREIGN KEY (unit_id) REFERENCES units(id),
  CONSTRAINT fk_prod_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conditionnements (paquet, carton...) : 1 carton = factor unités de base
CREATE TABLE IF NOT EXISTS product_packagings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(50) NOT NULL,
  factor DECIMAL(15,3) NOT NULL,
  barcode VARCHAR(64) NULL UNIQUE,
  sale_price DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_pack_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  field VARCHAR(30) NOT NULL,
  old_value DECIMAL(15,2) NULL,
  new_value DECIMAL(15,2) NULL,
  source VARCHAR(50) NOT NULL DEFAULT 'manual',
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ph_prod (product_id, created_at),
  CONSTRAINT fk_ph_prod FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Stocks
-- stock_levels est un cache maintenu UNIQUEMENT par StockService,
-- dans la même transaction que le mouvement correspondant.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_levels (
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
  reserved DECIMAL(15,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, warehouse_id),
  CONSTRAINT fk_sl_prod FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_sl_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_lots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  lot_number VARCHAR(80) NOT NULL,
  expiry_date DATE NULL,
  quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
  recalled TINYINT(1) NOT NULL DEFAULT 0,
  recall_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lot (product_id, warehouse_id, lot_number),
  INDEX idx_lot_expiry (expiry_date),
  CONSTRAINT fk_lot_prod FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_lot_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  type VARCHAR(30) NOT NULL, -- sale, return, reception, adjustment, transfer_in, transfer_out, inventory, loss, expired, supplier_return, initial
  quantity DECIMAL(15,3) NOT NULL, -- signé : + entrée, - sortie
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
  balance_after DECIMAL(15,3) NOT NULL,
  ref_type VARCHAR(30) NULL,
  ref_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sm_prod (product_id, created_at),
  INDEX idx_sm_ref (ref_type, ref_id),
  INDEX idx_sm_date (created_at),
  CONSTRAINT fk_sm_prod FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_sm_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_sm_lot FOREIGN KEY (lot_id) REFERENCES stock_lots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  from_warehouse_id INT UNSIGNED NOT NULL,
  to_warehouse_id INT UNSIGNED NOT NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tr_from FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_tr_to FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  CONSTRAINT fk_tri_tr FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id),
  CONSTRAINT fk_tri_prod FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('draft','validated','cancelled') NOT NULL DEFAULT 'draft',
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  validated_by INT UNSIGNED NULL,
  validated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  expected_qty DECIMAL(15,3) NOT NULL DEFAULT 0,
  counted_qty DECIMAL(15,3) NULL,
  UNIQUE KEY uq_inv_item (inventory_id, product_id),
  CONSTRAINT fk_invi_inv FOREIGN KEY (inventory_id) REFERENCES inventories(id) ON DELETE CASCADE,
  CONSTRAINT fk_invi_prod FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Clients
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  nif VARCHAR(50) NULL,
  credit_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
  balance DECIMAL(15,2) NOT NULL DEFAULT 0, -- créance (positif = le client doit)
  loyalty_points INT NOT NULL DEFAULT 0,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cust_phone (phone),
  INDEX idx_cust_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grand livre client : chaque variation de solde est tracée
CREATE TABLE IF NOT EXISTS customer_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL, -- credit_sale, payment, deposit, refund, adjustment
  amount DECIMAL(15,2) NOT NULL, -- signé : + augmente la dette du client
  balance_after DECIMAL(15,2) NOT NULL,
  method VARCHAR(30) NULL,
  ref_type VARCHAR(30) NULL,
  ref_id BIGINT UNSIGNED NULL,
  due_date DATE NULL,
  note VARCHAR(255) NULL,
  cash_session_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ct_cust (customer_id, created_at),
  CONSTRAINT fk_ct_cust FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Caisse (sessions + mouvements)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cash_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  register_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  opening_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_amount DECIMAL(15,2) NULL,
  counted_amount DECIMAL(15,2) NULL,
  difference DECIMAL(15,2) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  closed_by INT UNSIGNED NULL,
  closing_note VARCHAR(255) NULL,
  open_marker TINYINT GENERATED ALWAYS AS (IF(status='open',1,NULL)) STORED,
  UNIQUE KEY uq_one_open_session (register_id, open_marker),
  CONSTRAINT fk_cs_reg FOREIGN KEY (register_id) REFERENCES cash_registers(id),
  CONSTRAINT fk_cs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cash_session_id INT UNSIGNED NULL,
  type VARCHAR(30) NOT NULL, -- sale, refund, cash_in, cash_out, expense, customer_payment, supplier_payment, income, deposit, withdrawal
  method VARCHAR(30) NOT NULL DEFAULT 'cash',
  amount DECIMAL(15,2) NOT NULL, -- signé : + entrée d'argent, - sortie
  ref_type VARCHAR(30) NULL,
  ref_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cm_session (cash_session_id),
  INDEX idx_cm_date (created_at),
  CONSTRAINT fk_cm_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Ventes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  client_uuid CHAR(36) NOT NULL UNIQUE,      -- clé d'idempotence (anti-doublon)
  store_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  register_id INT UNSIGNED NULL,
  cash_session_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  is_wholesale TINYINT(1) NOT NULL DEFAULT 0,
  subtotal DECIMAL(15,2) NOT NULL,
  discount_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL,
  paid_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  tendered DECIMAL(15,2) NOT NULL DEFAULT 0,
  change_due DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  cost_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  status ENUM('completed','cancelled','partially_returned','returned') NOT NULL DEFAULT 'completed',
  authorized_by INT UNSIGNED NULL,
  cancelled_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  print_count INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sales_date (created_at),
  INDEX idx_sales_user (user_id),
  INDEX idx_sales_cust (customer_id),
  CONSTRAINT fk_sale_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_sale_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_sale_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id),
  CONSTRAINT fk_sale_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_sale_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  packaging_id INT UNSIGNED NULL,
  product_name VARCHAR(200) NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,         -- quantité dans l'unité vendue
  base_quantity DECIMAL(15,3) NOT NULL,    -- quantité en unité de base (stock)
  unit_price DECIMAL(15,2) NOT NULL,
  list_price DECIMAL(15,2) NOT NULL,       -- prix catalogue au moment de la vente
  discount DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(15,2) NOT NULL,
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
  returned_qty DECIMAL(15,3) NOT NULL DEFAULT 0,
  CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
  CONSTRAINT fk_si_prod FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Répartition FEFO d'une ligne de vente sur les lots
CREATE TABLE IF NOT EXISTS sale_item_lots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_item_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  CONSTRAINT fk_sil_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
  CONSTRAINT fk_sil_lot FOREIGN KEY (lot_id) REFERENCES stock_lots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id INT UNSIGNED NOT NULL,
  method VARCHAR(30) NOT NULL, -- cash, card, transfer, mobile_money, credit
  amount DECIMAL(15,2) NOT NULL,
  reference VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sp_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ordonnances (pharmacie)
CREATE TABLE IF NOT EXISTS prescriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id INT UNSIGNED NOT NULL,
  prescriber VARCHAR(150) NOT NULL,
  patient_name VARCHAR(150) NOT NULL,
  prescription_ref VARCHAR(100) NULL,
  prescription_date DATE NULL,
  validated_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_presc_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS held_sales (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  register_id INT UNSIGNED NULL,
  label VARCHAR(100) NOT NULL,
  cart_json MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  sale_id INT UNSIGNED NOT NULL,
  total DECIMAL(15,2) NOT NULL,
  refund_method VARCHAR(30) NOT NULL, -- cash, credit_note (avoir sur compte client), card, mobile_money
  reason VARCHAR(255) NOT NULL,
  restock TINYINT(1) NOT NULL DEFAULT 1,
  cash_session_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  authorized_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ret_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_return_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id INT UNSIGNED NOT NULL,
  sale_item_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_rti_ret FOREIGN KEY (return_id) REFERENCES sale_returns(id),
  CONSTRAINT fk_rti_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Achats
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  supplier_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('draft','ordered','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  expected_date DATE NULL,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_po_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_po_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  received_qty DECIMAL(15,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_prod FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  purchase_order_id INT UNSIGNED NULL,
  supplier_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  supplier_invoice_ref VARCHAR(100) NULL,
  goods_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  extra_costs DECIMAL(15,2) NOT NULL DEFAULT 0, -- transport, manutention... réparti au prorata
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  due_date DATE NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rec_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
  CONSTRAINT fk_rec_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_rec_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reception_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reception_id INT UNSIGNED NOT NULL,
  po_item_id INT UNSIGNED NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  unit_cost DECIMAL(15,2) NOT NULL,
  landed_unit_cost DECIMAL(15,2) NOT NULL,
  lot_number VARCHAR(80) NULL,
  expiry_date DATE NULL,
  lot_id INT UNSIGNED NULL,
  CONSTRAINT fk_ri_rec FOREIGN KEY (reception_id) REFERENCES receptions(id),
  CONSTRAINT fk_ri_prod FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  supplier_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  total DECIMAL(15,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sr_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_return_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_return_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  quantity DECIMAL(15,3) NOT NULL,
  unit_cost DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_sri_sr FOREIGN KEY (supplier_return_id) REFERENCES supplier_returns(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grand livre fournisseur
CREATE TABLE IF NOT EXISTS supplier_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL, -- invoice, payment, return, adjustment
  amount DECIMAL(15,2) NOT NULL, -- signé : + augmente notre dette
  balance_after DECIMAL(15,2) NOT NULL,
  method VARCHAR(30) NULL,
  ref_type VARCHAR(30) NULL,
  ref_id BIGINT UNSIGNED NULL,
  due_date DATE NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_st_sup (supplier_id, created_at),
  CONSTRAINT fk_st_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Dépenses et recettes diverses
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expense_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  type ENUM('expense','income') NOT NULL DEFAULT 'expense'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  type ENUM('expense','income') NOT NULL DEFAULT 'expense',
  category_id INT UNSIGNED NOT NULL,
  store_id INT UNSIGNED NULL,
  amount DECIMAL(15,2) NOT NULL,
  method VARCHAR(30) NOT NULL DEFAULT 'cash',
  expense_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  cash_session_id INT UNSIGNED NULL,
  status ENUM('valid','reversed') NOT NULL DEFAULT 'valid',
  reversed_by INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exp_date (expense_date),
  CONSTRAINT fk_exp_cat FOREIGN KEY (category_id) REFERENCES expense_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
