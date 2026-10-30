<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;

/**
 * Numéros lisibles, séquentiels et sans trou (JN-FP-2026-000001), attribués AVANT l'insertion
 * (les tables financières interdisent toute modification de référence ensuite).
 * Le compteur annuel (document_sequences) est verrouillé : aucun doublon possible, même en concurrence.
 */
final class References
{
    public static function next(Database $db, string $prefix): string
    {
        if (!preg_match('/^[A-Z]{1,3}$/', $prefix)) {
            throw new \InvalidArgumentException("Préfixe invalide : {$prefix}");
        }
        $year = date('Y');
        $name = "{$prefix}-{$year}";
        return $db->transaction(function (Database $db) use ($name, $prefix, $year) {
            $db->query('INSERT IGNORE INTO document_sequences (name, current_value) VALUES (?, 0)', [$name]);
            $current = (int) $db->fetchValue('SELECT current_value FROM document_sequences WHERE name = ? FOR UPDATE', [$name]);
            $db->query('UPDATE document_sequences SET current_value = ? WHERE name = ?', [$current + 1, $name]);
            return sprintf('JN-%s-%s-%06d', $prefix, $year, $current + 1);
        });
    }
}
