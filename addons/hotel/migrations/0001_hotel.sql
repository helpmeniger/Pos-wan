-- Module Hôtel : types de chambres, chambres, clients, séjours (réservations et séjours en cours),
-- folio (nuitées, prestations, taxes, remises), paiements en caisse, catalogue des prestations,
-- historique du ménage et de la maintenance, historique des séjours.

CREATE TABLE IF NOT EXISTS hotel_room_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  code VARCHAR(10) NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL DEFAULT 2,
  base_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  weekend_price DECIMAL(15,2) NULL,
  amenities VARCHAR(255) NULL,
  color VARCHAR(10) NOT NULL DEFAULT '#0d6efd',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  floor VARCHAR(30) NOT NULL DEFAULT 'Rez-de-chaussée',
  type_id INT UNSIGNED NOT NULL,
  housekeeping ENUM('propre','sale','nettoyage','inspectee') NOT NULL DEFAULT 'propre',
  out_of_order TINYINT(1) NOT NULL DEFAULT 0,
  ooo_reason VARCHAR(255) NULL,
  ooo_until DATE NULL,
  beds VARCHAR(80) NULL,
  note VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_hr_type (type_id),
  CONSTRAINT fk_hr_type FOREIGN KEY (type_id) REFERENCES hotel_room_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_guests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  last_name VARCHAR(80) NOT NULL,
  first_name VARCHAR(80) NULL,
  sex ENUM('M','F') NULL,
  birth_date DATE NULL,
  birth_place VARCHAR(100) NULL,
  nationality VARCHAR(60) NULL,
  profession VARCHAR(100) NULL,
  address VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  id_type VARCHAR(30) NULL,
  id_number VARCHAR(60) NULL,
  id_issued_on DATE NULL,
  id_issued_at VARCHAR(100) NULL,
  company VARCHAR(150) NULL,
  vip TINYINT(1) NOT NULL DEFAULT 0,
  blacklisted TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_hg_name (last_name, first_name),
  INDEX idx_hg_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_stays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  guest_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NULL,
  type_id INT UNSIGNED NOT NULL,
  status ENUM('reservee','confirmee','en_cours','terminee','annulee','no_show') NOT NULL DEFAULT 'reservee',
  source VARCHAR(20) NOT NULL DEFAULT 'direct',
  arrival DATE NOT NULL,
  departure DATE NOT NULL,
  adults TINYINT UNSIGNED NOT NULL DEFAULT 1,
  children TINYINT UNSIGNED NOT NULL DEFAULT 0,
  rate DECIMAL(15,2) NOT NULL,
  list_rate DECIMAL(15,2) NOT NULL,
  company VARCHAR(150) NULL,
  purpose VARCHAR(30) NULL,
  coming_from VARCHAR(100) NULL,
  going_to VARCHAR(100) NULL,
  note VARCHAR(255) NULL,
  charged_until DATE NULL,
  total_charges DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
  invoice_number VARCHAR(30) NULL UNIQUE,
  checked_in_at DATETIME NULL,
  checked_out_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_hs_status (status),
  INDEX idx_hs_dates (arrival, departure),
  INDEX idx_hs_room (room_id, status),
  CONSTRAINT fk_hs_guest FOREIGN KEY (guest_id) REFERENCES hotel_guests(id),
  CONSTRAINT fk_hs_room FOREIGN KEY (room_id) REFERENCES hotel_rooms(id),
  CONSTRAINT fk_hs_type FOREIGN KEY (type_id) REFERENCES hotel_room_types(id),
  CONSTRAINT fk_hs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_charges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stay_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  label VARCHAR(150) NOT NULL,
  service_date DATE NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(15,2) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  room_id INT UNSIGNED NULL,
  extra_id INT UNSIGNED NULL,
  cancelled TINYINT(1) NOT NULL DEFAULT 0,
  cancel_reason VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hc_stay (stay_id),
  INDEX idx_hc_date (service_date, kind),
  CONSTRAINT fk_hc_stay FOREIGN KEY (stay_id) REFERENCES hotel_stays(id),
  CONSTRAINT fk_hc_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stay_id INT UNSIGNED NOT NULL,
  kind ENUM('acompte','paiement','remboursement') NOT NULL,
  method VARCHAR(30) NOT NULL,
  provider VARCHAR(50) NULL,
  amount DECIMAL(15,2) NOT NULL,
  reference VARCHAR(100) NULL,
  cash_session_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hp_stay (stay_id),
  INDEX idx_hp_date (created_at),
  CONSTRAINT fk_hp_stay FOREIGN KEY (stay_id) REFERENCES hotel_stays(id),
  CONSTRAINT fk_hp_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_extras (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  kind VARCHAR(20) NOT NULL DEFAULT 'service',
  price DECIMAL(15,2) NOT NULL DEFAULT 0,
  icon VARCHAR(40) NOT NULL DEFAULT 'bi-bag',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_room_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  event VARCHAR(20) NOT NULL,
  from_state VARCHAR(20) NULL,
  to_state VARCHAR(20) NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hrl_room (room_id, id),
  CONSTRAINT fk_hrl_room FOREIGN KEY (room_id) REFERENCES hotel_rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_stay_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stay_id INT UNSIGNED NOT NULL,
  event VARCHAR(30) NOT NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hsl_stay (stay_id, id),
  CONSTRAINT fk_hsl_stay FOREIGN KEY (stay_id) REFERENCES hotel_stays(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('hotel_stay', 'RES', 1, YEAR(CURDATE())), ('hotel_invoice', 'FH', 1, YEAR(CURDATE()));

-- Configuration de départ (modifiable : Hôtel → Chambres et tarifs)
INSERT IGNORE INTO hotel_room_types (id, name, code, capacity, base_price, amenities, color, sort_order) VALUES
 (1, 'Standard ventilée', 'STV', 2, 15000, 'Ventilateur, douche', '#6c757d', 1),
 (2, 'Standard climatisée', 'STC', 2, 25000, 'Climatisation, TV, douche, Wi-Fi', '#0d6efd', 2),
 (3, 'Confort', 'CFT', 2, 35000, 'Climatisation, TV, réfrigérateur, Wi-Fi, bureau', '#198754', 3),
 (4, 'Suite', 'STE', 4, 60000, 'Salon, climatisation, TV, minibar, Wi-Fi, baignoire', '#6f42c1', 4);

INSERT IGNORE INTO hotel_rooms (number, floor, type_id, beds, sort_order) VALUES
 ('101', '1er étage', 1, '1 lit double', 101), ('102', '1er étage', 1, '2 lits simples', 102), ('103', '1er étage', 2, '1 lit double', 103), ('104', '1er étage', 2, '1 lit double', 104),
 ('105', '1er étage', 2, '2 lits simples', 105), ('106', '1er étage', 3, '1 grand lit', 106),
 ('201', '2e étage', 2, '1 lit double', 201), ('202', '2e étage', 2, '1 lit double', 202), ('203', '2e étage', 3, '1 grand lit', 203), ('204', '2e étage', 3, '1 grand lit', 204),
 ('205', '2e étage', 4, '1 grand lit + canapé-lit', 205), ('206', '2e étage', 4, '1 grand lit + canapé-lit', 206);

INSERT IGNORE INTO hotel_extras (name, kind, price, icon, sort_order) VALUES
 ('Petit-déjeuner', 'restauration', 3000, 'bi-cup-hot', 1), ('Déjeuner / dîner', 'restauration', 6000, 'bi-egg-fried', 2),
 ('Boisson (minibar)', 'minibar', 1000, 'bi-cup-straw', 3), ('Blanchisserie (pièce)', 'blanchisserie', 1000, 'bi-basket2', 4),
 ('Lit supplémentaire (nuit)', 'service', 5000, 'bi-plus-square', 5), ('Transfert aéroport', 'transport', 10000, 'bi-car-front', 6),
 ('Location de salle (demi-journée)', 'service', 50000, 'bi-easel', 7), ('Appel téléphonique', 'telephone', 500, 'bi-telephone', 8);
