<?php
/**
 * Moyens de paiement de référence. Les moyens électroniques sont désactivés tant que
 * leur connecteur n'est pas configuré (aucun paiement n'est simulé).
 */
return static function (PDO $pdo): void {
    $methods = [
        // code, nom, type, provider, actif, en ligne, caisse, ordre
        ['cash', 'Espèces', 'cash', null, 1, 0, 1, 1],
        ['nita', 'Nita', 'mobile_money', 'nita', 0, 1, 1, 2],
        ['airtel_money', 'Airtel Money', 'mobile_money', 'airtel_money', 0, 1, 1, 3],
        ['moov_money', 'Moov Money (Flooz)', 'mobile_money', 'moov_money', 0, 1, 1, 4],
        ['amana', 'Amana', 'mobile_money', 'amana', 0, 1, 1, 5],
        ['card', 'Carte bancaire', 'card', null, 0, 1, 1, 6],
        ['reseller_balance', 'Solde revendeur', 'reseller_balance', null, 1, 0, 0, 7],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO payment_methods (code, name, type, provider, is_active, available_online, available_pos, sort_order) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($methods as $m) {
        $stmt->execute($m);
    }
};
