-- =====================================================================
-- POS Marwa — migration 0002 : module Restaurant + fiches techniques (recettes)
-- =====================================================================

-- Poste de préparation de chaque catégorie (cuisine, bar…) pour router les tickets
ALTER TABLE categories ADD COLUMN kitchen_station VARCHAR(30) NULL AFTER image;

-- Articles non vendables (ingrédients, matières premières)
ALTER TABLE products ADD COLUMN sellable TINYINT(1) NOT NULL DEFAULT 1 AFTER status;

-- Lien vente ↔ commande restaurant (une commande peut être payée en plusieurs fois)
ALTER TABLE sales ADD COLUMN restaurant_order_id INT UNSIGNED NULL AFTER customer_id;
ALTER TABLE sales ADD INDEX idx_sales_rorder (restaurant_order_id);

-- Fiche technique : 1 unité de l'article consomme `quantity` de l'ingrédient
CREATE TABLE IF NOT EXISTS product_recipes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  ingredient_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(15,4) NOT NULL,
  UNIQUE KEY uq_recipe (product_id, ingredient_id),
  CONSTRAINT fk_rec_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_ing FOREIGN KEY (ingredient_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingrédients réellement déstockés par une ligne de vente (annulations / retours)
CREATE TABLE IF NOT EXISTS sale_item_components (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_item_id INT UNSIGNED NOT NULL,
  ingredient_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  quantity DECIMAL(15,4) NOT NULL,
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_sic_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
  CONSTRAINT fk_sic_ing FOREIGN KEY (ingredient_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_areas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_area_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_tables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  area_id INT UNSIGNED NOT NULL,
  name VARCHAR(50) NOT NULL,
  seats SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  shape ENUM('square','round','long') NOT NULL DEFAULT 'square',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_table_name (area_id, name),
  CONSTRAINT fk_table_area FOREIGN KEY (area_id) REFERENCES restaurant_areas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  store_id INT UNSIGNED NOT NULL,
  table_id INT UNSIGNED NULL,
  type ENUM('dine_in','takeaway','delivery') NOT NULL DEFAULT 'dine_in',
  covers SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  waiter_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  customer_name VARCHAR(150) NULL,
  customer_phone VARCHAR(50) NULL,
  delivery_address VARCHAR(255) NULL,
  note VARCHAR(255) NULL,
  status ENUM('open','billed','paid','cancelled') NOT NULL DEFAULT 'open',
  bill_count INT UNSIGNED NOT NULL DEFAULT 0,
  cancel_reason VARCHAR(255) NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  -- une seule commande en cours par table (index unique sur colonne générée)
  open_table TINYINT GENERATED ALWAYS AS (IF(status IN ('open','billed') AND table_id IS NOT NULL, 1, NULL)) STORED,
  UNIQUE KEY uq_open_table (table_id, open_table),
  INDEX idx_ro_status (status),
  INDEX idx_ro_opened (opened_at),
  CONSTRAINT fk_ro_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_ro_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(id),
  CONSTRAINT fk_ro_waiter FOREIGN KEY (waiter_id) REFERENCES users(id),
  CONSTRAINT fk_ro_cust FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kitchen_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  order_id INT UNSIGNED NOT NULL,
  station VARCHAR(30) NOT NULL,
  type ENUM('order','cancel') NOT NULL DEFAULT 'order',
  status ENUM('pending','preparing','ready','served') NOT NULL DEFAULT 'pending',
  user_id INT UNSIGNED NOT NULL,
  print_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  ready_at DATETIME NULL,
  served_at DATETIME NULL,
  INDEX idx_kt_station (station, status),
  CONSTRAINT fk_kt_order FOREIGN KEY (order_id) REFERENCES restaurant_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  paid_qty DECIMAL(15,3) NOT NULL DEFAULT 0,
  unit_price DECIMAL(15,2) NOT NULL,
  note VARCHAR(255) NULL,
  course TINYINT UNSIGNED NOT NULL DEFAULT 2,   -- 1 entrée, 2 plat, 3 dessert, 0 boisson
  station VARCHAR(30) NULL,
  status ENUM('new','sent','preparing','ready','served','cancelled') NOT NULL DEFAULT 'new',
  kitchen_ticket_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  cancelled_by INT UNSIGNED NULL,
  cancel_reason VARCHAR(255) NULL,
  wasted TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_roi_order (order_id),
  CONSTRAINT fk_roi_order FOREIGN KEY (order_id) REFERENCES restaurant_orders(id),
  CONSTRAINT fk_roi_prod FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_roi_ticket FOREIGN KEY (kitchen_ticket_id) REFERENCES kitchen_tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lignes d'un ticket d'annulation (cuisine)
CREATE TABLE IF NOT EXISTS kitchen_ticket_cancellations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kitchen_ticket_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  CONSTRAINT fk_ktc_ticket FOREIGN KEY (kitchen_ticket_id) REFERENCES kitchen_tickets(id),
  CONSTRAINT fk_ktc_item FOREIGN KEY (order_item_id) REFERENCES restaurant_order_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('restaurant_order', 'CMD', 1, YEAR(CURDATE())), ('kitchen_ticket', 'KT', 1, YEAR(CURDATE()));
