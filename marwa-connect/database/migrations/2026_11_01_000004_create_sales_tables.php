<?php
/**
 * Ventes, caisse, paiements (physiques et en ligne), remboursements, webhooks, mouvements revendeurs.
 */
$opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"CREATE TABLE IF NOT EXISTS payment_methods (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    type ENUM('cash','mobile_money','card','reseller_balance','other') NOT NULL,
    provider VARCHAR(60) NULL COMMENT 'Connecteur : nita, airtel_money, moov_money, amana…',
    config_enc TEXT NULL COMMENT 'Configuration chiffrée (clés API)',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    available_online TINYINT(1) NOT NULL DEFAULT 0,
    available_pos TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) $opts",

"CREATE TABLE IF NOT EXISTS cash_registers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    site_id INT UNSIGNED NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cr_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) $opts",

"CREATE TABLE IF NOT EXISTS cash_sessions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    register_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opening_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    closed_at DATETIME NULL,
    closed_by INT UNSIGNED NULL,
    expected_amount DECIMAL(14,2) NULL,
    declared_amount DECIMAL(14,2) NULL,
    difference DECIMAL(14,2) NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    INDEX idx_cs_user (user_id, status),
    CONSTRAINT fk_cs_register FOREIGN KEY (register_id) REFERENCES cash_registers(id) ON DELETE SET NULL,
    CONSTRAINT fk_cs_user FOREIGN KEY (user_id) REFERENCES users(id)
) $opts",

"CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL UNIQUE,
    channel ENUM('physical','online','reseller') NOT NULL,
    cash_session_id INT UNSIGNED NULL,
    cashier_id INT UNSIGNED NULL,
    reseller_id INT UNSIGNED NULL,
    router_id INT UNSIGNED NULL,
    customer_name VARCHAR(150) NULL,
    customer_phone VARCHAR(30) NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_method_id INT UNSIGNED NULL,
    payment_status ENUM('pending','paid','failed','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
    status ENUM('pending','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    idempotency_key VARCHAR(64) NULL UNIQUE COMMENT 'Empêche les doubles ventes (double clic, rejeu)',
    cancelled_by INT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(500) NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sales_date (created_at, status),
    INDEX idx_sales_router (router_id, created_at),
    INDEX idx_sales_reseller (reseller_id, created_at),
    CONSTRAINT fk_sales_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_cashier FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_pm FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL
) $opts",

"CREATE TABLE IF NOT EXISTS sale_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    ticket_id BIGINT UNSIGNED NOT NULL,
    offer_id INT UNSIGNED NOT NULL,
    unit_price DECIMAL(14,2) NOT NULL,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL,
    UNIQUE KEY uq_sale_ticket (ticket_id) COMMENT 'Un ticket ne peut être vendu qu\'une fois',
    CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_si_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id),
    CONSTRAINT fk_si_offer FOREIGN KEY (offer_id) REFERENCES offers(id)
) $opts",

"CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NULL,
    online_order_id BIGINT UNSIGNED NULL,
    payment_method_id INT UNSIGNED NOT NULL,
    provider VARCHAR(60) NULL,
    provider_reference VARCHAR(120) NULL COMMENT 'Référence de transaction du prestataire',
    amount DECIMAL(14,2) NOT NULL,
    phone VARCHAR(30) NULL,
    status ENUM('initiated','pending','confirmed','failed','cancelled','refunded') NOT NULL DEFAULT 'initiated',
    provider_status VARCHAR(60) NULL,
    raw_response JSON NULL,
    confirmed_at DATETIME NULL,
    confirmed_via ENUM('cash','webhook','status_check','manual') NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_ref (provider, provider_reference),
    INDEX idx_payments_sale (sale_id),
    CONSTRAINT fk_pay_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_pm FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) $opts",

"CREATE TABLE IF NOT EXISTS refunds (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('requested','approved','rejected','completed') NOT NULL DEFAULT 'requested',
    requested_by INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ref_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) $opts",

"CREATE TABLE IF NOT EXISTS online_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL UNIQUE,
    offer_id INT UNSIGNED NOT NULL,
    router_id INT UNSIGNED NOT NULL,
    customer_phone VARCHAR(30) NOT NULL,
    customer_email VARCHAR(190) NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_method_id INT UNSIGNED NOT NULL,
    status ENUM('created','awaiting_payment','paid','delivered','failed','expired','cancelled') NOT NULL DEFAULT 'created',
    sale_id BIGINT UNSIGNED NULL,
    ticket_id BIGINT UNSIGNED NULL,
    access_token CHAR(64) NOT NULL COMMENT 'Hash du jeton de consultation de la commande',
    ip_address VARCHAR(45) NULL,
    expires_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_oo_status (status, expires_at),
    CONSTRAINT fk_oo_offer FOREIGN KEY (offer_id) REFERENCES offers(id),
    CONSTRAINT fk_oo_router FOREIGN KEY (router_id) REFERENCES routers(id),
    CONSTRAINT fk_oo_pm FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    CONSTRAINT fk_oo_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) $opts",

"CREATE TABLE IF NOT EXISTS webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(60) NOT NULL,
    event_id VARCHAR(150) NOT NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    payload MEDIUMTEXT NOT NULL,
    headers TEXT NULL,
    processed TINYINT(1) NOT NULL DEFAULT 0,
    processed_at DATETIME NULL,
    error VARCHAR(1000) NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_webhook (provider, event_id)
) $opts",

"CREATE TABLE IF NOT EXISTS reseller_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT UNSIGNED NOT NULL,
    type ENUM('topup','purchase','sale','commission','payout','adjustment','refund') NOT NULL,
    amount DECIMAL(14,2) NOT NULL COMMENT 'Positif = crédit, négatif = débit',
    balance_after DECIMAL(14,2) NOT NULL,
    reference VARCHAR(60) NULL,
    sale_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rt_reseller (reseller_id, created_at),
    CONSTRAINT fk_rt_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    CONSTRAINT fk_rt_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
) $opts",

// Clé étrangère tickets.sale_id ajoutée après la création de la table sales.
static function (PDO $pdo): void {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND CONSTRAINT_NAME = 'fk_t_sale'")->fetchColumn();
    if (!$exists) {
        $pdo->exec('ALTER TABLE tickets ADD CONSTRAINT fk_t_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL');
    }
},
]];
