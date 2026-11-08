-- Module Boutique en ligne : articles publiés, commandes des clients, historique des statuts.

CREATE TABLE IF NOT EXISTS shop_products (
  product_id INT UNSIGNED NOT NULL PRIMARY KEY,
  hidden TINYINT(1) NOT NULL DEFAULT 0,          -- 1 : jamais affiché en ligne
  featured TINYINT(1) NOT NULL DEFAULT 0,        -- mis en avant (« À la une »)
  web_description TEXT NULL,                     -- texte en ligne (sinon description de l'article)
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_shopp_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  token CHAR(32) NOT NULL,                       -- accès du client à son reçu (lien secret)
  customer_name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  email VARCHAR(150) NULL,
  delivery ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
  address VARCHAR(255) NULL,
  note VARCHAR(500) NULL,
  status ENUM('new','confirmed','paid','delivered','cancelled') NOT NULL DEFAULT 'new',
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  delivery_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  warehouse_id INT UNSIGNED NULL,                -- stock consulté au moment de la commande
  sale_id INT UNSIGNED NULL,                     -- vente créée à l'encaissement (stock déstocké)
  sale_uuid CHAR(36) NULL,
  customer_id INT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at DATETIME NULL, confirmed_by INT UNSIGNED NULL,
  paid_at DATETIME NULL, paid_by INT UNSIGNED NULL,
  delivered_at DATETIME NULL, delivered_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL, cancelled_by INT UNSIGNED NULL,
  cancel_reason VARCHAR(255) NULL,
  INDEX idx_shopo_status (status, created_at),
  INDEX idx_shopo_phone (phone),
  INDEX idx_shopo_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(200) NOT NULL,
  unit_price DECIMAL(15,2) NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  line_total DECIMAL(15,2) NOT NULL,
  INDEX idx_shopi_order (order_id),
  INDEX idx_shopi_product (product_id),
  CONSTRAINT fk_shopi_order FOREIGN KEY (order_id) REFERENCES shop_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_order_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_shopl_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('shop_order', 'WEB', 1, 0);

INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('shop_enabled', '1'),
  ('shop_title', ''),
  ('shop_tagline', 'Commandez en ligne, retirez en boutique ou faites-vous livrer.'),
  ('shop_color', '#0d6efd'),
  ('shop_register_id', ''),
  ('shop_delivery', '1'),
  ('shop_delivery_fee', '0'),
  ('shop_min_order', '0'),
  ('shop_stock_display', 'badge'),
  ('shop_require_open', '0'),
  ('shop_out_of_stock', 'show'),
  ('shop_whatsapp', '');
