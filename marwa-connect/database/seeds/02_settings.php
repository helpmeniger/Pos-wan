<?php
/**
 * Paramètres par défaut (n'écrase jamais une valeur existante).
 */
return static function (PDO $pdo): void {
    $defaults = [
        // [groupe, clé, valeur, type]
        ['company', 'company.name', 'MARWA CONNECT', 'string'],
        ['company', 'company.slogan', 'Smart Wi-Fi Management & Ticketing System', 'string'],
        ['company', 'company.phone', '', 'string'],
        ['company', 'company.email', '', 'string'],
        ['company', 'company.address', 'Niamey, Niger', 'string'],
        ['company', 'company.logo', '', 'string'],
        ['appearance', 'appearance.primary_color', '#D7192A', 'string'],
        ['appearance', 'appearance.nav_color', '#0B1A33', 'string'],
        ['appearance', 'appearance.default_theme', 'light', 'string'],
        ['regional', 'regional.currency', 'FCFA', 'string'],
        ['regional', 'regional.locale', 'fr', 'string'],
        ['regional', 'regional.timezone', 'Africa/Niamey', 'string'],
        ['tickets', 'tickets.default_prefix', 'MC', 'string'],
        ['tickets', 'tickets.username_length', '8', 'int'],
        ['tickets', 'tickets.password_length', '6', 'int'],
        ['tickets', 'tickets.charset', 'alphanumeric_upper', 'string'],
        ['tickets', 'tickets.low_stock_threshold', '20', 'int'],
        ['tickets', 'tickets.wifi_ssid', '', 'string'],
        ['tickets', 'tickets.login_instructions', "Connectez-vous au réseau Wi-Fi, ouvrez votre navigateur puis saisissez l'identifiant et le mot de passe.", 'text'],
        ['sales', 'sales.require_open_cash_session', '1', 'bool'],
        ['sales', 'sales.max_discount_percent', '10', 'int'],
        ['sales', 'sales.receipt_footer', 'Merci pour votre confiance !', 'string'],
        ['mikrotik', 'mikrotik.sync_interval_minutes', '5', 'int'],
        ['mikrotik', 'mikrotik.timeout_seconds', '5', 'int'],
        ['mikrotik', 'mikrotik.offline_after_failures', '2', 'int'],
        ['security', 'security.session_idle_minutes', '30', 'int'],
        ['security', 'security.audit_retention_days', '365', 'int'],
        ['backup', 'backup.keep_last', '14', 'int'],
        ['backup', 'backup.auto_daily', '1', 'bool'],
        ['ai', 'ai.external_enabled', '0', 'bool'],
        ['ai', 'ai.provider', '', 'string'],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO settings (`group`, `key`, `value`, `type`) VALUES (?, ?, ?, ?)');
    foreach ($defaults as $row) {
        $stmt->execute($row);
    }
};
