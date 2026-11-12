<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use App\Core\DB;

/**
 * Dictionnaire de données : signification des tables et colonnes RÉELLES de la base (découvertes dans information_schema),
 * avec des descriptions rédigées pour les tables métier et une description déduite du nom pour les autres.
 */
final class DataDictionary
{
    public const TABLES = [
        'products' => 'Articles du catalogue (prix de vente TTC, coût moyen pondéré, seuil d\'alerte, suivi du stock et des lots).',
        'stock_levels' => 'Quantité en stock de chaque article dans chaque magasin (entrepôt).',
        'stock_movements' => 'Historique de chaque entrée et sortie de stock (vente, retour, réception, ajustement, transfert, inventaire) avec le solde après mouvement.',
        'stock_lots' => 'Lots d\'un article (numéro, date de péremption, quantité) pour la gestion FEFO.',
        'stock_transfers' => 'Transferts de marchandise d\'un magasin vers un autre.',
        'inventories' => 'Inventaires physiques (brouillon, validé, annulé).',
        'sales' => 'Ventes (tickets) : numéro, boutique, caisse, vendeur, client, montants, statut (completed, cancelled, returned…).',
        'sale_items' => 'Lignes de vente : article, quantité, prix, remise, TVA, coût au moment de la vente.',
        'sale_payments' => 'Règlements d\'une vente par mode (espèces, carte, mobile money, virement, crédit).',
        'sale_returns' => 'Retours clients et remboursements.',
        'cash_sessions' => 'Sessions de caisse : ouverture, fond de caisse, espèces théoriques, comptées et écart à la clôture.',
        'cash_movements' => 'Mouvements de caisse signés (+ entrée, − sortie) par type et mode de paiement.',
        'customers' => 'Clients : coordonnées, plafond de crédit, solde dû, points de fidélité.',
        'customer_transactions' => 'Compte client : ventes à crédit, règlements, ajustements.',
        'suppliers' => 'Fournisseurs et solde dû par la boutique.',
        'supplier_transactions' => 'Compte fournisseur : achats, paiements, retours.',
        'purchase_orders' => 'Commandes fournisseurs (brouillon, envoyée, partielle, reçue, annulée).',
        'receptions' => 'Réceptions de marchandise (achats) et leur montant.',
        'expenses' => 'Dépenses et recettes diverses (catégorie, montant, mode, date, statut valide / contrepassé).',
        'users' => 'Utilisateurs (rôle, boutique, caisse).',
        'roles' => 'Rôles (caissier, gérant, comptable…).',
        'permissions' => 'Droits fins attribués aux rôles.',
        'stores' => 'Boutiques (établissements).',
        'warehouses' => 'Magasins / entrepôts, rattachés ou non à une boutique.',
        'cash_registers' => 'Caisses, rattachées à une boutique et à un magasin.',
        'settings' => 'Paramètres du logiciel (clé / valeur).',
        'audit_logs' => 'Journal d\'audit des actions sensibles.',
        'payment_transactions' => 'Paiements électroniques (module Marwa Pay).',
        'fiscal_certifications' => 'Certifications fiscales des factures (module Fiscal Connect).',
    ];

    public const COLUMNS = [
        'cost_price' => 'coût moyen pondéré (prix d\'achat)', 'sale_price' => 'prix de vente TTC', 'min_stock' => 'seuil d\'alerte', 'quantity' => 'quantité', 'total' => 'montant total TTC',
        'subtotal' => 'montant brut avant remises', 'discount_total' => 'total des remises', 'tax_total' => 'TVA comprise', 'cost_total' => 'coût des articles vendus', 'credit_amount' => 'part à crédit',
        'balance' => 'solde (dû)', 'credit_limit' => 'plafond de crédit', 'status' => 'statut', 'number' => 'numéro de document', 'created_at' => 'date de création', 'updated_at' => 'date de modification',
        'user_id' => 'utilisateur (auteur)', 'store_id' => 'boutique', 'warehouse_id' => 'magasin', 'register_id' => 'caisse', 'customer_id' => 'client', 'supplier_id' => 'fournisseur',
        'product_id' => 'article', 'amount' => 'montant', 'method' => 'mode de paiement', 'expected_amount' => 'espèces théoriques', 'counted_amount' => 'espèces comptées', 'difference' => 'écart de caisse',
        'line_total' => 'total de la ligne TTC', 'unit_price' => 'prix unitaire', 'unit_cost' => 'coût unitaire', 'tax_rate' => 'taux de TVA', 'base_quantity' => 'quantité en unité de base',
    ];

    /** Description d'une colonne (rédigée ou déduite du nom). */
    public static function column(string $name, string $type): string
    {
        if (isset(self::COLUMNS[$name])) {
            return self::COLUMNS[$name];
        }
        if ($name === 'id') {
            return 'identifiant';
        }
        if (str_ends_with($name, '_id')) {
            return 'lien vers ' . substr($name, 0, -3);
        }
        if (str_ends_with($name, '_at') || str_contains($type, 'date')) {
            return 'date (' . str_replace('_', ' ', preg_replace('/_at$/', '', $name)) . ')';
        }
        if (str_starts_with($name, 'is_') || $type === 'tinyint') {
            return 'oui / non (' . str_replace('_', ' ', $name) . ')';
        }
        return str_replace('_', ' ', $name);
    }

    /** Inventaire du schéma réel : tables, colonnes, types, clés primaires et étrangères. */
    public static function schema(): array
    {
        $tables = [];
        foreach (DB::all('SELECT TABLE_NAME t, COLUMN_NAME c, DATA_TYPE d, COLUMN_KEY k FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $r) {
            $tables[$r['t']]['columns'][] = ['name' => $r['c'], 'type' => $r['d'], 'key' => $r['k'], 'label' => self::column($r['c'], $r['d'])];
        }
        foreach (DB::all('SELECT TABLE_NAME t, COLUMN_NAME c, REFERENCED_TABLE_NAME rt, REFERENCED_COLUMN_NAME rc FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL') as $r) {
            $tables[$r['t']]['fks'][] = $r['c'] . ' → ' . $r['rt'] . '.' . $r['rc'];
        }
        foreach ($tables as $t => &$d) {
            $d['description'] = self::TABLES[$t] ?? null;
            $d['fks'] ??= [];
        }
        return $tables;
    }
}
