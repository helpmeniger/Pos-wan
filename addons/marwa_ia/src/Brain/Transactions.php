<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Text;

/**
 * MARWA TRANSACTION INTELLIGENCE : types de mouvements de POS Marwa (table, sens, effet sur le stock, la caisse,
 * les comptes) et définition des montants. Sert à expliquer et à ne jamais confondre deux notions.
 */
final class Transactions
{
    /** code => [libellé, table(s) et filtre, effet stock, effet caisse, effet compte, synonymes] */
    public const TYPES = [
        'vente' => ['Vente', 'sales (status ≠ cancelled), sale_items, sale_payments', 'sortie de stock', 'entrée (selon le mode de paiement)', 'dette client si vente à crédit', 'vente ticket facture encaissement'],
        'retour_client' => ['Retour client', 'sale_returns, sale_return_items', 'réentrée en stock (si « remettre en stock »)', 'sortie (remboursement) ou avoir', 'avoir client possible', 'retour rembourse avoir'],
        'annulation' => ['Annulation de vente', 'sales.status = cancelled', 'réintégration du stock', 'contre-passation des encaissements', 'annulation de la dette éventuelle', 'annuler annulee annulation'],
        'retour_fournisseur' => ['Retour fournisseur', 'supplier_returns, supplier_return_items', 'sortie de stock', 'aucun', 'diminue la dette fournisseur', 'retour fournisseur'],
        'achat' => ['Achat (réception de marchandise)', 'receptions, reception_items', 'entrée en stock', 'aucun (sauf paiement)', 'dette fournisseur', 'achat reception livraison'],
        'commande_fournisseur' => ['Commande fournisseur', 'purchase_orders', 'aucun tant que non reçue', 'aucun', 'aucun', 'bon de commande'],
        'depense' => ['Dépense', 'expenses (type = expense)', 'aucun', 'sortie si payée par la caisse', 'charge', 'depense charge frais'],
        'recette' => ['Recette diverse', 'expenses (type = income)', 'aucun', 'entrée si encaissée en caisse', 'produit', 'recette'],
        'entree_caisse' => ['Entrée de caisse (apport)', 'cash_movements type = cash_in', 'aucun', 'entrée d\'espèces', 'aucun', 'apport entree'],
        'sortie_caisse' => ['Sortie de caisse (retrait)', 'cash_movements type = cash_out', 'aucun', 'sortie d\'espèces', 'aucun', 'sortie retrait'],
        'transfert' => ['Transfert de stock', 'stock_transfers (+ mouvements transfer_out / transfer_in)', 'sortie d\'un magasin, entrée dans l\'autre', 'aucun', 'aucun', 'transfert'],
        'ajustement' => ['Ajustement / perte de stock', 'stock_movements type = adjustment / loss', 'correction (+ ou −)', 'aucun', 'perte de valeur si sortie', 'ajustement perte casse'],
        'inventaire' => ['Inventaire', 'inventories, inventory_items (écarts → stock_movements type = inventory)', 'corrige le stock aux quantités comptées', 'aucun', 'aucun', 'inventaire comptage'],
        'reglement_client' => ['Paiement d\'un client (règlement de dette)', 'customer_transactions, cash_movements type = customer_payment', 'aucun', 'entrée', 'diminue la dette du client', 'reglement paiement client'],
        'reglement_fournisseur' => ['Règlement fournisseur', 'supplier_transactions, cash_movements type = supplier_payment', 'aucun', 'sortie si payé par la caisse', 'diminue la dette envers le fournisseur', 'payer fournisseur'],
        'credit' => ['Vente à crédit', 'sales.credit_amount, sale_payments method = credit', 'sortie de stock', 'aucune entrée pour la part à crédit', 'dette client (créance)', 'credit'],
        'acompte' => ['Acompte', 'sale_payments partiel + crédit du reste', 'sortie de stock', 'entrée pour l\'acompte', 'dette pour le reste', 'acompte avance'],
        'paiement_electronique' => ['Paiement électronique', 'payment_transactions (module Marwa Pay)', 'aucun', 'entrée en carte / mobile money / virement après confirmation du prestataire', 'aucun', 'paiement electronique carte'],
        'certification_fiscale' => ['Certification fiscale', 'fiscal_certifications (module Fiscal Connect)', 'aucun', 'aucun', 'aucun', 'certification mcf'],
    ];

    /** Lexique des montants : terme => définition (règles réelles de POS Marwa). */
    public const TERMS = [
        'brut' => ['Montant brut', 'Somme des lignes au prix de vente avant remise (sales.subtotal).'],
        'remise' => ['Remise', 'Réduction accordée sur une ligne ou sur le ticket (sales.discount_total).'],
        'ttc' => ['Montant TTC', 'Montant payé par le client, toutes taxes comprises (sales.total) : les prix de POS Marwa sont TTC.'],
        'tva' => ['TVA', 'Taxe comprise dans le prix TTC (sales.tax_total, détail par taux dans sale_items).'],
        'ht' => ['Montant HT', 'Montant hors taxe = TTC − TVA.'],
        'net' => ['Montant net (chiffre d\'affaires net)', 'Ventes TTC non annulées − retours clients.'],
        'chiffre' => ['Chiffre d\'affaires', 'Total TTC des ventes non annulées sur la période.'],
        'marge' => ['Marge', 'Ventes nettes − coût d\'achat des articles vendus (coût moyen enregistré à chaque vente).'],
        'benefice' => ['Bénéfice estimé', 'Marge − dépenses + recettes diverses (estimation de gestion, pas un résultat comptable).'],
        'prix achat' => ['Prix d\'achat (coût)', 'Coût moyen pondéré de l\'article (products.cost_price), recalculé à chaque réception.'],
        'prix vente' => ['Prix de vente', 'Prix TTC affiché en caisse (products.sale_price), ou prix promotionnel / de gros si applicable.'],
        'solde' => ['Solde', 'Pour un client : ce qu\'il doit (customers.balance) ; pour un fournisseur : ce que vous lui devez (suppliers.balance) ; pour la caisse : espèces théoriques.'],
        'encaissement' => ['Encaissement', 'Argent reçu (vente, règlement de client, recette, apport) : mouvement de caisse positif.'],
        'decaissement' => ['Décaissement', 'Argent sorti (dépense, remboursement, paiement fournisseur, retrait) : mouvement de caisse négatif.'],
        'creance' => ['Créance', 'Somme qu\'un client vous doit (vente à crédit non réglée).'],
        'dette' => ['Dette', 'Somme que vous devez à un fournisseur (ou qu\'un client vous doit, selon le point de vue).'],
        'valeur stock' => ['Valeur du stock', 'Quantités en stock × coût moyen pondéré ; au prix de vente, c\'est le chiffre potentiel.'],
    ];

    /** Explication d'un type ou d'un terme cité dans la question (ou d'une différence entre deux termes). */
    public static function explain(string $q): ?array
    {
        $n = Text::norm($q);
        $found = [];
        foreach (self::TERMS as $k => [$label, $def]) {
            if (preg_match('/\b' . str_replace(' ', '\s+(d\s+)?', $k) . '\w*\b/', $n)) {
                $found[] = "<b>$label</b> : " . e($def);
            }
        }
        foreach (self::TYPES as [$label, $table, $stock, $cash, $account, $syn]) {
            if (preg_match('/\b(' . str_replace(' ', '|', $syn) . ')\b/', $n) && preg_match('/\b(type|types|transaction|transactions|mouvement|mouvements|difference|c est quoi|que veut dire|signifie|definition)\b/', $n)) {
                $found[] = '<b>' . e($label) . '</b> — données : <code>' . e($table) . '</code> ; stock : ' . e($stock) . ' ; caisse : ' . e($cash) . ' ; comptes : ' . e($account) . '.';
            }
        }
        $found = array_values(array_unique($found));
        return $found ? ['html' => '<ul><li>' . implode('</li><li>', array_slice($found, 0, 6)) . '</li></ul>', 'count' => count($found)] : null;
    }

    /** Liste complète, pour « quels types de transactions existent ? ». */
    public static function listing(): array
    {
        return ['headers' => ['Type', 'Données', 'Stock', 'Caisse', 'Comptes'], 'rows' => array_map(fn($t) => array_slice($t, 0, 5), array_values(self::TYPES))];
    }
}
