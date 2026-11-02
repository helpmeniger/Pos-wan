<?php
/**
 * Offres de départ proposées dans le cahier des charges. Créées uniquement lors de la
 * première installation (table vide) ; entièrement modifiables ensuite.
 * Elles ne sont associées à aucun routeur : aucun ticket n'existe tant que
 * l'opérateur n'en génère pas.
 */
return static function (PDO $pdo): void {
    if ((int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn() > 0) {
        return;
    }
    $offers = [
        // nom, code, prix, valeur, unité, ordre
        ['1 heure', 'H1', 100, 1, 'hours', 1],
        ['24 heures', 'H24', 250, 24, 'hours', 2],
        ['3 jours', 'D3', 500, 3, 'days', 3],
        ['7 jours', 'D7', 1000, 7, 'days', 4],
        ['30 jours', 'D30', 2000, 30, 'days', 5],
    ];
    $stmt = $pdo->prepare("INSERT INTO offers (name, code, price, validity_value, validity_unit, shared_users, activation_mode, validity_mode, status, sort_order)
        VALUES (?, ?, ?, ?, ?, 1, 'first_login', 'elapsed_time', 'active', ?)");
    foreach ($offers as $o) {
        $stmt->execute($o);
    }
};
