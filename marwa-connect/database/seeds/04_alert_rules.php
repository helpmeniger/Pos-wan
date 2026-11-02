<?php
return static function (PDO $pdo): void {
    $rules = [
        ['router_offline', 'Routeur déconnecté / inaccessible', null, 'danger', 15],
        ['cpu_high', 'Utilisation CPU élevée (%)', 85, 'warning', 30],
        ['memory_high', 'Mémoire saturée (% utilisée)', 90, 'warning', 30],
        ['traffic_abnormal', 'Débit anormal (Mbit/s)', 500, 'warning', 30],
        ['connections_high', 'Nombre élevé de connexions', 200, 'warning', 30],
        ['stock_low', 'Stock de tickets faible', 20, 'warning', 360],
        ['ticket_expired', 'Tickets expirés', null, 'info', 1440],
        ['payment_unconfirmed', 'Paiement non confirmé (minutes)', 15, 'warning', 30],
        ['cash_gap', 'Écart de caisse (FCFA)', 0, 'danger', 0],
        ['sync_failed', 'Problème de synchronisation', null, 'warning', 30],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO alert_rules (code, name, threshold, level, cooldown_minutes, channels) VALUES (?,?,?,?,?, ?)');
    foreach ($rules as $r) {
        $stmt->execute([...$r, json_encode(['app'])]);
    }
};
