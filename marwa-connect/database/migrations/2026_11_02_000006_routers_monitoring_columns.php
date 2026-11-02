<?php
/**
 * Colonnes complémentaires : interface WAN surveillée, dernières mesures, réglages paiement.
 */
return ['up' => [
    static function (PDO $pdo): void {
        $add = static function (string $table, string $column, string $ddl) use ($pdo): void {
            $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'")->fetchColumn();
            if (!$exists) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
            }
        };
        $add('routers', 'wan_interface', "`wan_interface` VARCHAR(100) NULL AFTER `wifi_ssid`");
        $add('routers', 'last_cpu', "`last_cpu` TINYINT UNSIGNED NULL AFTER `last_error`");
        $add('routers', 'last_memory_used_pct', "`last_memory_used_pct` TINYINT UNSIGNED NULL AFTER `last_cpu`");
        $add('routers', 'last_uptime', "`last_uptime` BIGINT UNSIGNED NULL AFTER `last_memory_used_pct`");
        $add('routers', 'last_active_users', "`last_active_users` INT UNSIGNED NULL AFTER `last_uptime`");
        $add('tickets', 'customer_phone', "`customer_phone` VARCHAR(30) NULL AFTER `sale_id`");
        $add('payment_methods', 'merchant_number', "`merchant_number` VARCHAR(60) NULL AFTER `provider`");
        $add('payment_methods', 'instructions', "`instructions` VARCHAR(500) NULL AFTER `merchant_number`");
    },
]];
