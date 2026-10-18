<?php
declare(strict_types=1);

namespace App\Services;

/** Structure de navigation (menu latéral + écran « Menu de gestion »). */
final class Menu
{
    public static function groups(): array
    {
        return [
            'Gestion des ventes' => [
                ['Caisse', 'bi-cart3', '/pos', 'pos.sell', '#27ae60'],
                ['Historique des ventes', 'bi-receipt', '/sales', 'sales.view', '#e84393'],
                ['Retours & avoirs', 'bi-arrow-counterclockwise', '/returns', 'sales.view', '#00897b'],
                ['Devis', 'bi-file-earmark-ruled', '/quotes', 'quotes.manage', '#3949ab'],
                ['Vérifier un QR code', 'bi-qr-code-scan', '/verify', 'sales.view', '#455a64'],
                ['Ma caisse (ouverture / clôture)', 'bi-safe2', '/cash', 'cash.open_close', '#5dade2'],
                ['Sessions de caisse', 'bi-clock-history', '/cash/sessions', 'cash.view_all', '#8e44ad'],
                ['Clients & crédits', 'bi-people', '/customers', 'customers.view', '#f39c12'],
            ],
            'Restaurant' => [
                ['Plan de salle', 'bi-grid-3x3', '/restaurant', 'restaurant.order', '#e67e22', 'restaurant'],
                ['Écran cuisine / bar', 'bi-fire', '/kitchen', 'restaurant.kitchen', '#c0392b', 'restaurant'],
                ['Commandes restaurant', 'bi-card-checklist', '/restaurant/orders', 'restaurant.order', '#2e86c1', 'restaurant'],
                ['Fiches techniques', 'bi-journal-text', '/restaurant/recipes', 'restaurant.manage', '#16a085', 'restaurant'],
                ['Salles & tables', 'bi-house-gear', '/restaurant/setup', 'restaurant.manage', '#8e44ad', 'restaurant'],
                ['Rapport restaurant', 'bi-bar-chart-steps', '/restaurant/report', 'reports.view', '#d35400', 'restaurant'],
            ],
            'Gestion des articles' => [
                ['Articles', 'bi-box-seam', '/products', 'products.view', '#e67e22'],
                ['Catégories, marques, unités', 'bi-tags', '/categories', 'categories.manage', '#7f8c8d'],
                ['État des stocks', 'bi-boxes', '/stock', 'stock.view', '#1e3a8a'],
                ['Mouvements de stock', 'bi-arrow-left-right', '/stock/movements', 'stock.view', '#2d6a4f'],
                ['Ajustement / pertes', 'bi-sliders', '/stock/adjust', 'stock.adjust', '#c0392b'],
                ['Inventaires', 'bi-clipboard-check', '/inventories', 'stock.inventory', '#d4ac0d'],
                ['Transferts', 'bi-truck', '/stock/transfers', 'stock.view', '#2874a6'],
                ['Lots & péremptions', 'bi-calendar-x', '/stock/expiry', 'stock.view', '#e74c3c', 'lots'],
                ['Étiquettes code-barres', 'bi-upc-scan', '/products/labels', 'products.view', '#117a65'],
                ['Import / export', 'bi-file-earmark-spreadsheet', '/products/import', 'products.import', '#1d8348'],
            ],
            'Achats & fournisseurs' => [
                ['Fournisseurs', 'bi-building', '/suppliers', 'purchases.view', '#9b59b6'],
                ['Commandes fournisseurs', 'bi-cart-plus', '/purchases', 'purchases.view', '#2e86c1'],
                ['Réceptions', 'bi-box-arrow-in-down', '/receptions', 'purchases.view', '#16a085'],
                ['Retours fournisseurs', 'bi-box-arrow-up', '/supplier-returns', 'purchases.view', '#a04000'],
            ],
            'Finances & rapports' => [
                ['Tableau de bord', 'bi-speedometer2', '/dashboard', 'dashboard.view', '#0d6efd'],
                ['Dépenses & recettes', 'bi-wallet2', '/expenses', 'expenses.manage', '#d35400'],
                ['Rapports des ventes', 'bi-bar-chart-line', '/reports/sales', 'reports.view', '#e91e63'],
                ['Bénéfices & marges', 'bi-graph-up-arrow', '/reports/profit', 'reports.financial', '#c2185b'],
                ['Rapport TVA', 'bi-percent', '/reports/vat', 'reports.financial', '#5e35b1'],
                ['Valeur du stock', 'bi-cash-stack', '/reports/stock', 'reports.financial', '#455a64'],
                ['Créances & dettes', 'bi-journal-text', '/reports/debts', 'reports.financial', '#6d4c41'],
                ['Registre des ordonnances', 'bi-file-medical', '/reports/prescriptions', 'reports.view', '#00838f', 'pharmacy'],
            ],
            'Configuration' => [
                ['Utilisateurs', 'bi-person-badge', '/users', 'users.manage', '#5dade2'],
                ['Rôles & permissions', 'bi-shield-lock', '/roles', 'roles.manage', '#8e44ad'],
                ['Paramètres entreprise', 'bi-gear', '/settings', 'settings.manage', '#566573'],
                ['Imprimantes', 'bi-printer', '/printers', 'settings.manage', '#2c3e50'],
                ['Modules complémentaires', 'bi-puzzle', '/addons', 'addons.manage', '#6c3483'],
                ['Mise à jour du logiciel', 'bi-cloud-arrow-up', '/update', 'system.update', '#1f618d'],
                ['Accès mobile (QR code)', 'bi-qr-code', '/mobile', 'settings.manage', '#117a65'],
                ['Appareils connectés', 'bi-phone', '/devices', 'users.manage', '#7d3c98'],
                ['Licence et activation', 'bi-key-fill', '/licence', 'licence.manage', '#b7950b'],
                ['Boutiques, entrepôts, caisses', 'bi-shop', '/stores', 'stores.manage', '#28b463'],
                ['Sauvegardes', 'bi-hdd-stack', '/backups', 'backup.manage', '#b03a2e'],
                ['Journal d\'audit', 'bi-journal-check', '/audit', 'audit.view', '#34495e'],
                ['Mon mot de passe / PIN', 'bi-key', '/account/password', null, '#f1c40f'],
            ],
        ];
    }

    /** Menu filtré selon les permissions et fonctionnalités actives (modules complémentaires inclus). */
    public static function visible(): array
    {
        $out = [];
        $groups = self::groups();
        foreach (\App\Core\Addons::menu() as $g => $items) {
            $groups[$g] = array_merge($groups[$g] ?? [], $items);
        }
        foreach ($groups as $g => $items) {
            $f = array_values(array_filter($items, fn($i) => ($i[3] === null || can($i[3])) && (!isset($i[5]) || feature($i[5])) && !BusinessMode::hides($i[2])));
            if ($f) {
                $out[$g] = $f;
            }
        }
        // Mode Établissement : le groupe de l'établissement en tête, aucun intitulé commercial
        if (BusinessMode::is('ecole') && isset($out['Établissement'])) {
            $out = ['Établissement' => $out['Établissement']] + $out;
            $noms = ['Gestion des ventes' => 'Caisse', 'Finances & rapports' => 'Finances'];
            $out = array_combine(array_map(fn($g) => $noms[$g] ?? $g, array_keys($out)), array_values($out));
        }
        return $out;
    }
}
