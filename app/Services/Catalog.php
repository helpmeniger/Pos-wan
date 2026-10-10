<?php
declare(strict_types=1);

namespace App\Services;

/** Catalogue des permissions, rôles par défaut et types de commerce. */
final class Catalog
{
    public const PERMISSIONS = [
        // module => [code => libellé]
        'Tableau de bord' => ['dashboard.view' => 'Voir le tableau de bord'],
        'Caisse' => [
            'pos.sell' => 'Vendre à la caisse',
            'pos.discount' => 'Accorder une remise (jusqu\'au seuil)',
            'pos.discount_high' => 'Autoriser une remise au-delà du seuil',
            'pos.price_override' => 'Modifier le prix de vente à la caisse',
            'pos.credit_sale' => 'Autoriser une vente à crédit',
            'pos.cancel_sale' => 'Annuler une vente validée',
            'pos.reprint' => 'Réimprimer un ticket',
            'pos.wholesale' => 'Vendre au prix de gros',
        ],
        'Ventes' => [
            'sales.view' => 'Voir ses ventes',
            'sales.view_all' => 'Voir toutes les ventes',
            'returns.create' => 'Enregistrer un retour / remboursement',
        ],
        'Articles' => [
            'products.view' => 'Voir les articles',
            'products.manage' => 'Créer / modifier / archiver les articles',
            'products.cost_view' => 'Voir les prix d\'achat et marges',
            'products.price_edit' => 'Modifier les prix de vente',
            'products.import' => 'Importer / exporter les articles',
            'categories.manage' => 'Gérer les catégories, marques, unités',
        ],
        'Stocks' => [
            'stock.view' => 'Voir les stocks et mouvements',
            'stock.adjust' => 'Ajuster le stock (pertes, casse, correction)',
            'stock.transfer' => 'Transférer entre entrepôts',
            'stock.inventory' => 'Réaliser les inventaires',
            'stock.inventory_validate' => 'Valider un inventaire',
        ],
        'Achats' => [
            'purchases.view' => 'Voir les achats',
            'purchases.manage' => 'Créer commandes et réceptions',
            'suppliers.manage' => 'Gérer les fournisseurs',
            'suppliers.pay' => 'Payer les fournisseurs',
        ],
        'Clients' => [
            'customers.view' => 'Voir les clients',
            'customers.manage' => 'Créer / modifier les clients',
            'customers.payments' => 'Encaisser les règlements clients',
        ],
        'Finances' => [
            'cash.open_close' => 'Ouvrir / clôturer sa caisse',
            'cash.view_all' => 'Voir toutes les sessions de caisse',
            'cash.movements' => 'Entrées / sorties de caisse',
            'expenses.manage' => 'Gérer dépenses et recettes',
            'expenses.reverse' => 'Contrepasser une dépense',
        ],
        'Rapports' => [
            'reports.view' => 'Voir les rapports de ventes',
            'reports.financial' => 'Voir les rapports financiers (marges, bénéfices)',
            'reports.export' => 'Exporter les rapports',
        ],
        'Pharmacie' => [
            'pharmacy.dispense' => 'Délivrer les médicaments sur ordonnance',
            'pharmacy.manage' => 'Gérer lots, rappels et péremptions',
        ],
        'Administration' => [
            'users.manage' => 'Gérer les utilisateurs',
            'roles.manage' => 'Gérer les rôles et permissions',
            'settings.manage' => 'Paramètres de l\'entreprise',
            'stores.manage' => 'Boutiques, entrepôts et caisses',
            'audit.view' => 'Consulter le journal d\'audit',
            'backup.manage' => 'Sauvegarder / restaurer',
        ],
    ];

    public const ROLES = [
        'admin' => ['Administrateur général', 'Accès complet au système', '*'],
        'owner' => ['Propriétaire / gérant', 'Pilotage complet de l\'activité', '*except:roles.manage,backup.manage'],
        'store_manager' => ['Responsable de magasin', 'Gestion quotidienne de la boutique', [
            'dashboard.view', 'pos.sell', 'pos.discount', 'pos.discount_high', 'pos.price_override', 'pos.credit_sale', 'pos.cancel_sale', 'pos.reprint', 'pos.wholesale',
            'sales.view', 'sales.view_all', 'returns.create', 'products.view', 'products.manage', 'products.cost_view', 'products.price_edit', 'products.import', 'categories.manage',
            'stock.view', 'stock.adjust', 'stock.transfer', 'stock.inventory', 'stock.inventory_validate', 'purchases.view', 'purchases.manage', 'suppliers.manage',
            'customers.view', 'customers.manage', 'customers.payments', 'cash.open_close', 'cash.view_all', 'cash.movements', 'expenses.manage', 'reports.view', 'reports.financial', 'reports.export',
        ]],
        'cashier' => ['Caissier', 'Ventes à la caisse', ['pos.sell', 'pos.discount', 'pos.reprint', 'sales.view', 'products.view', 'customers.view', 'customers.manage', 'customers.payments', 'cash.open_close']],
        'stock_manager' => ['Gestionnaire de stock', 'Stocks, réceptions, inventaires', ['dashboard.view', 'products.view', 'products.manage', 'products.import', 'categories.manage', 'stock.view', 'stock.adjust', 'stock.transfer', 'stock.inventory', 'purchases.view', 'pharmacy.manage']],
        'buyer' => ['Acheteur', 'Commandes et fournisseurs', ['dashboard.view', 'products.view', 'products.cost_view', 'stock.view', 'purchases.view', 'purchases.manage', 'suppliers.manage']],
        'accountant' => ['Comptable', 'Finances et rapports', ['dashboard.view', 'sales.view', 'sales.view_all', 'products.view', 'products.cost_view', 'stock.view', 'purchases.view', 'suppliers.pay', 'customers.view', 'customers.payments', 'cash.view_all', 'expenses.manage', 'expenses.reverse', 'reports.view', 'reports.financial', 'reports.export']],
        'pharmacist' => ['Pharmacien responsable', 'Délivrance et traçabilité pharmaceutique', ['dashboard.view', 'pos.sell', 'pos.discount', 'pos.reprint', 'sales.view', 'sales.view_all', 'returns.create', 'products.view', 'products.manage', 'stock.view', 'stock.adjust', 'stock.inventory', 'purchases.view', 'purchases.manage', 'customers.view', 'customers.manage', 'cash.open_close', 'pharmacy.dispense', 'pharmacy.manage', 'reports.view']],
        'auditor' => ['Auditeur (lecture seule)', 'Consultation sans modification', ['dashboard.view', 'sales.view', 'sales.view_all', 'products.view', 'products.cost_view', 'stock.view', 'purchases.view', 'customers.view', 'cash.view_all', 'reports.view', 'reports.financial', 'audit.view']],
    ];

    /** Types de commerce => fonctionnalités activées par défaut. */
    public const BUSINESS_TYPES = [
        'alimentation' => ['Alimentation / supermarché', ['lots', 'packaging', 'weight', 'wholesale', 'promotions']],
        'boutique' => ['Boutique générale (vêtements, accessoires...)', ['variants', 'wholesale', 'promotions']],
        'pharmacie' => ['Pharmacie', ['lots', 'pharmacy', 'packaging']],
        'quincaillerie' => ['Quincaillerie', ['packaging', 'wholesale', 'weight']],
        'boissons' => ['Dépôt de boissons', ['packaging', 'wholesale', 'lots']],
        'cosmetique' => ['Cosmétique', ['lots', 'variants', 'promotions']],
        'informatique' => ['Matériel informatique', ['variants', 'wholesale']],
        'papeterie' => ['Papeterie', ['packaging', 'wholesale']],
        'pieces' => ['Pièces détachées', ['variants', 'wholesale']],
    ];

    public const FEATURES = [
        'lots' => 'Lots et dates de péremption (FEFO)',
        'variants' => 'Variantes (tailles, couleurs)',
        'packaging' => 'Conditionnements (paquet, carton...)',
        'weight' => 'Vente au poids / quantités décimales',
        'wholesale' => 'Prix de gros',
        'promotions' => 'Prix promotionnels',
        'pharmacy' => 'Module pharmacie (DCI, ordonnances, rappels de lots)',
        'loyalty' => 'Programme de fidélité',
    ];

    public const STOCK_MOVEMENT_TYPES = [
        'initial' => 'Stock initial', 'sale' => 'Vente', 'sale_cancel' => 'Annulation vente', 'return' => 'Retour client',
        'reception' => 'Réception', 'adjustment' => 'Ajustement', 'loss' => 'Perte / casse', 'expired' => 'Périmé',
        'transfer_in' => 'Transfert entrant', 'transfer_out' => 'Transfert sortant', 'inventory' => 'Inventaire',
        'supplier_return' => 'Retour fournisseur', 'recall' => 'Rappel de lot',
    ];
}
