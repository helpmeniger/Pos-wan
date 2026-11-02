<?php
/**
 * Sessions Wi-Fi, notifications et alertes, Optimus IA.
 */
$opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"CREATE TABLE IF NOT EXISTS wifi_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    router_id INT UNSIGNED NOT NULL,
    ticket_id BIGINT UNSIGNED NULL,
    username VARCHAR(64) NOT NULL,
    ros_session_id VARCHAR(20) NULL COMMENT '.id dans /ip/hotspot/active',
    ip_address VARCHAR(45) NULL,
    mac_address VARCHAR(17) NULL,
    login_at DATETIME NOT NULL,
    logout_at DATETIME NULL,
    uptime_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    terminate_cause VARCHAR(100) NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ws_active (router_id, is_active),
    INDEX idx_ws_user (username, login_at),
    INDEX idx_ws_mac (mac_address),
    INDEX idx_ws_login (login_at),
    CONSTRAINT fk_ws_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE,
    CONSTRAINT fk_ws_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) $opts",

"CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL COMMENT 'NULL = destinée aux détenteurs de la permission',
    permission VARCHAR(80) NULL,
    type VARCHAR(60) NOT NULL COMMENT 'router_offline, cpu_high, stock_low…',
    level ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    title VARCHAR(150) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    link VARCHAR(255) NULL,
    router_id INT UNSIGNED NULL,
    dedupe_key VARCHAR(150) NULL COMMENT 'Évite de répéter la même alerte',
    data JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id, created_at),
    INDEX idx_notif_dedupe (dedupe_key, created_at)
) $opts",

"CREATE TABLE IF NOT EXISTS notification_reads (
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, user_id),
    CONSTRAINT fk_nr_notif FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_nr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) $opts",

"CREATE TABLE IF NOT EXISTS notification_channels (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('email','whatsapp','sms') NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    config_enc TEXT NULL,
    recipients JSON NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) $opts",

"CREATE TABLE IF NOT EXISTS alert_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    threshold DECIMAL(14,2) NULL,
    level ENUM('info','warning','danger') NOT NULL DEFAULT 'warning',
    channels JSON NULL,
    cooldown_minutes INT UNSIGNED NOT NULL DEFAULT 30,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) $opts",

"CREATE TABLE IF NOT EXISTS ai_conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_aic_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) $opts",

"CREATE TABLE IF NOT EXISTS ai_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role ENUM('user','assistant','system') NOT NULL,
    content TEXT NOT NULL,
    intent VARCHAR(80) NULL,
    data JSON NULL COMMENT 'Données réelles ayant servi à la réponse',
    engine VARCHAR(40) NOT NULL DEFAULT 'local',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aim_conv FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
) $opts",

"CREATE TABLE IF NOT EXISTS ai_pending_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    params JSON NOT NULL,
    summary VARCHAR(500) NOT NULL,
    status ENUM('awaiting_confirmation','confirmed','rejected','executed','failed','expired') NOT NULL DEFAULT 'awaiting_confirmation',
    result JSON NULL,
    confirmed_at DATETIME NULL,
    executed_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) $opts",
]];
