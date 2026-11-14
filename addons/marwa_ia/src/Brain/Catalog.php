<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use App\Core\Addons;
use App\Core\BusinessException;
use App\Core\DB;
use Addons\MarwaIa\Text;
use App\Services\CashService;

/**
 * MARWA DATABASE INTELLIGENCE ENGINE — catalogue des outils de données.
 *
 * Chaque outil = une intention métier, ses droits, ses tables sources et une fonction qui exécute des requêtes
 * PRÉPARÉES sur des tables et colonnes connues (aucun SQL libre), avec le périmètre de l'utilisateur (UserContext)
 * injecté côté serveur, des résultats limités et un délai maximal. Les montants suivent les règles de POS Marwa :
 *  - chiffre d'affaires = ventes non annulées (TTC) ; net = CA − retours ; marge = net − coût des ventes ;
 *  - bénéfice = marge − dépenses + recettes diverses ; valeur du stock = quantité × coût moyen pondéré (CMP).
 * Un outil renvoie des faits ; le moteur de réponse les met en phrases sans jamais changer un chiffre.
 */
final class Catalog
{
    public const TOOLS = [
        // --- Stock
        'stock_value' => ['Valeur du stock (au coût d\'achat)', 'stock', ['stock.view', 'products.cost_view|reports.financial'], 'stockValue', '/stock'],
        'stock_value_sale' => ['Valeur du stock au prix de vente', 'stock', ['stock.view'], 'stockValueSale', '/stock'],
        'stock_by_location' => ['Valeur du stock par boutique et magasin', 'stock', ['stock.view', 'products.cost_view|reports.financial'], 'stockByLocation', '/stock'],
        'stock_count' => ['Articles disponibles en stock', 'stock', ['stock.view'], 'stockCount', '/stock'],
        'stock_out' => ['Articles en rupture', 'stock', ['stock.view'], 'stockOut', '/stock'],
        'stock_low' => ['Articles en stock faible', 'stock', ['stock.view'], 'stockLow', '/stock'],
        'product_stock' => ['Stock d\'un article', 'stock', ['stock.view'], 'productStock', '/stock'],
        'dormant' => ['Articles non vendus depuis longtemps', 'stock', ['stock.view', 'reports.view'], 'dormant', '/stock'],
        'reorder' => ['Articles à recommander', 'stock', ['stock.view'], 'reorder', '/purchases/create'],
        'expiring' => ['Produits qui périment bientôt', 'stock', ['stock.view'], 'expiring', '/stock/expiry'],
        'transfers' => ['Transferts de stock', 'stock', ['stock.view'], 'transfers', '/stock/transfers'],
        'adjustments' => ['Ajustements et pertes de stock', 'stock', ['stock.view'], 'adjustments', '/stock/movements'],
        'inventories' => ['Inventaires', 'stock', ['stock.inventory|stock.view'], 'inventories', '/inventories'],
        // --- Ventes
        'sales_summary' => ['Ventes de la période', 'ventes', ['sales.view'], 'salesSummary', '/sales'],
        'sales_compare' => ['Comparaison des ventes entre deux périodes', 'ventes', ['sales.view'], 'salesCompare', '/reports'],
        'revenue' => ['Chiffre d\'affaires', 'finances', ['reports.view|sales.view'], 'revenue', '/reports'],
        'best_day' => ['Meilleure journée de vente', 'ventes', ['reports.view|sales.view'], 'bestDay', '/reports'],
        'top_products' => ['Articles les plus vendus', 'ventes', ['reports.view'], 'topProducts', '/reports/products'],
        'most_profitable' => ['Articles les plus rentables', 'ventes', ['reports.financial'], 'mostProfitable', '/reports/products'],
        'product_growth' => ['Articles dont les ventes augmentent', 'ventes', ['reports.view'], 'productGrowth', '/reports/products'],
        'sales_by_user' => ['Ventes par utilisateur', 'ventes', ['reports.view', 'sales.view_all'], 'salesByUser', '/reports'],
        'top_cashier' => ['Meilleur vendeur', 'ventes', ['reports.view', 'sales.view_all'], 'topCashier', '/reports'],
        'sales_by_store' => ['Ventes par boutique', 'ventes', ['sales.view_all', 'reports.view'], 'salesByStore', '/reports'],
        'sales_by_method' => ['Ventes par mode de paiement', 'ventes', ['reports.view'], 'salesByMethod', '/reports'],
        'sales_by_hour' => ['Affluence par heure', 'ventes', ['reports.view'], 'salesByHour', '/reports'],
        'sales_by_category' => ['Ventes par catégorie', 'ventes', ['reports.view'], 'salesByCategory', '/reports'],
        'last_sale' => ['Dernière vente (dernier ticket)', 'ventes', ['sales.view'], 'lastSale', '/sales'],
        'last_sales' => ['Dernières ventes', 'ventes', ['sales.view'], 'lastSales', '/sales'],
        'cancelled_sales' => ['Ventes annulées', 'ventes', ['sales.view'], 'cancelledSales', '/sales'],
        'returns' => ['Retours et remboursements clients', 'ventes', ['sales.view'], 'returns', '/sales/returns'],
        'discounts_tax' => ['Remises et TVA', 'finances', ['reports.view'], 'discountsTax', '/reports'],
        'sales_decline' => ['Explication d\'une baisse des ventes', 'ventes', ['reports.view'], 'salesDecline', '/reports'],
        'compare_stores' => ['Comparaison des boutiques', 'ventes', ['sales.view_all', 'reports.view'], 'compareStores', '/reports'],
        // --- Caisse
        'cash_balance' => ['Solde actuel de la caisse', 'caisse', ['cash.view_all|cash.open_close'], 'cashBalance', '/cash'],
        'cash_in' => ['Argent entré en caisse', 'caisse', ['cash.view_all|cash.open_close'], 'cashIn', '/cash'],
        'cash_out' => ['Argent sorti de la caisse', 'caisse', ['cash.view_all|cash.open_close'], 'cashOut', '/cash'],
        'cash_difference' => ['Écarts de caisse (théorique / compté)', 'caisse', ['cash.view_all|cash.open_close'], 'cashDifference', '/cash/sessions'],
        'last_cash_out' => ['Dernière sortie de caisse', 'caisse', ['cash.view_all|cash.open_close'], 'lastCashOut', '/cash/sessions'],
        // --- Clients, fournisseurs
        'customers_count' => ['Nombre de clients', 'clients', ['customers.view'], 'customersCount', '/customers'],
        'customer_debts' => ['Clients qui ont des dettes', 'clients', ['customers.view'], 'customerDebts', '/customers'],
        'top_customer' => ['Meilleurs clients', 'clients', ['reports.view', 'sales.view_all'], 'topCustomer', '/customers'],
        'supplier_payables' => ['Fournisseurs à payer', 'fournisseurs', ['purchases.view'], 'supplierPayables', '/suppliers'],
        'purchases_total' => ['Montant des achats (réceptions)', 'achats', ['purchases.view'], 'purchasesTotal', '/receptions'],
        'purchase_orders' => ['Commandes fournisseurs en cours', 'achats', ['purchases.view'], 'purchaseOrders', '/purchases'],
        // --- Finances
        'profit' => ['Bénéfice estimé', 'finances', ['reports.financial'], 'profit', '/reports'],
        'expenses_total' => ['Dépenses de la période', 'finances', ['expenses.manage|reports.financial'], 'expensesTotal', '/expenses'],
        'expenses_trend' => ['Analyse des dépenses sur plusieurs mois', 'finances', ['expenses.manage|reports.financial'], 'expensesTrend', '/expenses'],
        'income_vs_expenses' => ['Recettes comparées aux dépenses', 'finances', ['reports.financial'], 'incomeVsExpenses', '/reports'],
        // --- Synthèses, contrôle
        'daily_report' => ['Bilan de la journée', 'synthese', [], 'dailyReport', '/'],
        'activity_summary' => ['Résumé de l\'activité', 'synthese', [], 'activitySummary', '/reports'],
        'anomalies' => ['Anomalies à vérifier', 'controle', ['reports.view'], 'anomalies', '/reports'],
        'last_transactions' => ['Dernières opérations', 'synthese', [], 'lastTransactions', '/'],
        // --- Modules
        'epay_summary' => ['Paiements électroniques (Marwa Pay)', 'paiements', ['pay.view'], 'epaySummary', '/pay'],
        'fiscal_summary' => ['Certifications fiscales (Fiscal Connect)', 'fiscal', ['fiscal.view'], 'fiscalSummary', '/fiscal'],
        'users_count' => ['Utilisateurs du logiciel', 'utilisateurs', ['users.manage'], 'usersCount', '/users'],
        'whoami' => ['Mon profil et mes droits', 'utilisateurs', [], 'whoami', '/account/password'],
        'wifi_tickets' => ['Tickets Wi-Fi (Marwa Connect)', 'tickets', [], 'wifiTickets', null],
        // Outils du moteur « Business Expert » (classe Expert)
        'last_stock_in' => ['Dernière entrée en stock', 'stock', ['stock.view'], 'Expert::lastStockIn', '/stock/movements'],
        'stock_movements' => ['Mouvements de stock', 'stock', ['stock.view'], 'Expert::stockMovements', '/stock/movements'],
        'stock_in_total' => ['Entrées en stock de la période', 'stock', ['stock.view'], 'Expert::stockInTotal', '/stock/movements'],
        'product_info' => ['Prix et fiche d\'un article', 'stock', ['products.view|pos.sell'], 'Expert::productInfo', '/products'],
        'product_sales' => ['Ventes d\'un article', 'ventes', ['sales.view'], 'Expert::productSales', '/reports/sales'],
        'items_sold' => ['Nombre d\'articles vendus', 'ventes', ['sales.view'], 'Expert::itemsSold', '/reports/sales'],
        'customer_info' => ['Fiche et solde d\'un client', 'clients', ['customers.view'], 'Expert::customerInfo', '/customers'],
        'supplier_info' => ['Fiche et solde d\'un fournisseur', 'fournisseurs', ['purchases.view'], 'Expert::supplierInfo', '/suppliers'],
        'customer_payments' => ['Règlements des dettes clients', 'clients', ['customers.view'], 'Expert::customerPayments', '/customers'],
        'expenses_by_category' => ['Dépenses par catégorie', 'finances', ['expenses.manage|reports.financial'], 'Expert::expensesByCategory', '/expenses'],
        'margin_rate' => ['Marge brute et taux de marge', 'finances', ['reports.financial'], 'Expert::marginRate', '/reports/profit'],
        'cash_sessions_open' => ['Caisses ouvertes', 'caisse', ['cash.view_all|cash.open_close'], 'Expert::cashSessionsOpen', '/cash/sessions'],
        'cash_diagnostic' => ['Diagnostic de la caisse', 'caisse', ['cash.view_all|cash.open_close'], 'Expert::cashDiagnostic', '/cash'],
        'whats_wrong' => ['Alertes et points à surveiller', 'synthèses', [], 'Expert::whatsWrong', null],
        'advice' => ['Conseils de gestion calculés sur vos données', 'synthèses', [], 'Expert::advice', null],
    ];

    /** Délai maximal d'une requête de consultation (ms). */
    public const MAX_MS = 5000;

    // ------------------------------------------------------------------ exécution

    /** Exécute un outil dans une transaction en lecture seule, avec délai maximal. */
    public static function run(string $code, UserContext $u, array $slots): array
    {
        $def = self::TOOLS[$code] ?? null;
        if (!$def) {
            throw new BusinessException('Outil inconnu.');
        }
        self::limitTime();
        $pdo = DB::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->exec('START TRANSACTION READ ONLY'); // aucune écriture possible pendant la consultation
        }
        try {
            $r = str_contains($def[3], '::') ? ('Addons\\MarwaIa\\Brain\\' . $def[3])($u, $slots) : self::{$def[3]}($u, $slots);
        } finally {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $r += ['ok' => true, 'cards' => [], 'table' => null, 'chart' => null, 'facts' => [], 'sources' => [], 'empty' => false, 'followups' => []];
        if (!isset($r['link']) && $def[4]) {
            $r['link'] = [$def[0], $def[4]];
        }
        return $r;
    }

    /** Délai maximal par requête (MariaDB : max_statement_time en secondes ; MySQL : max_execution_time en ms). */
    private static function limitTime(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $v = (string) DB::value('SELECT VERSION()');
            if (stripos($v, 'mariadb') !== false) {
                DB::connection()->exec('SET SESSION max_statement_time = ' . (self::MAX_MS / 1000));
            } else {
                DB::connection()->exec('SET SESSION max_execution_time = ' . self::MAX_MS);
            }
        } catch (\Throwable) {
        }
    }

    // ------------------------------------------------------------------ utilitaires

    public static function money(float $v): string
    {
        return money(round($v, 2));
    }

    /** « 15,45 millions F » pour les grands montants (variante de phrase, même valeur). */
    public static function short(float $v): string
    {
        $sym = (string) (currency()['symbol'] ?? 'F');
        if (abs($v) >= 1_000_000) {
            return rtrim(rtrim(number_format($v / 1_000_000, 2, ',', ' '), '0'), ',') . ' millions ' . $sym;
        }
        return self::money($v);
    }

    public static function n(float|int $v): string
    {
        $t = number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2, ',', ' ');
        return str_contains($t, ',') ? rtrim(rtrim($t, '0'), ',') : $t; // 94,8 et non 94,80
    }

    /** Période demandée ou période par défaut de l'outil. */
    public static function period(array $s, string $default): array
    {
        if (!empty($s['period'])) {
            return $s['period'];
        }
        $t = date('Y-m-d');
        return match ($default) {
            'today' => ['from' => $t, 'to' => $t, 'label' => 'aujourd\'hui'],
            'week' => ['from' => date('Y-m-d', strtotime('monday this week')), 'to' => $t, 'label' => 'cette semaine'],
            'month' => ['from' => date('Y-m-01'), 'to' => $t, 'label' => 'ce mois-ci'],
            '30d' => ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => $t, 'label' => 'les 30 derniers jours'],
            '90d' => ['from' => date('Y-m-d', strtotime('-89 days')), 'to' => $t, 'label' => 'les 90 derniers jours'],
            'year' => ['from' => date('Y-m-d', strtotime('-364 days')), 'to' => $t, 'label' => 'les 12 derniers mois'],
            default => ['from' => $t, 'to' => $t, 'label' => 'aujourd\'hui'],
        };
    }

    /** Période précédente de même durée (pour les comparaisons). */
    public static function previous(array $p): array
    {
        $a = strtotime($p['from']);
        $b = strtotime($p['to']);
        $days = (int) round(($b - $a) / 86400) + 1;
        $label = match ($p['label']) {
            'aujourd\'hui' => 'hier', 'cette semaine' => 'la semaine dernière', 'ce mois-ci', 'ce mois' => 'le mois dernier', default => 'la période précédente',
        };
        if (in_array($p['label'], ['ce mois-ci', 'ce mois'], true)) {
            // Mois précédent, même nombre de jours écoulés
            $from = date('Y-m-01', strtotime('first day of last month', $a));
            return ['from' => $from, 'to' => date('Y-m-d', min(strtotime($from . ' +' . ($days - 1) . ' days'), strtotime(date('Y-m-t', strtotime($from))))), 'label' => $label . ' (même nombre de jours)'];
        }
        return ['from' => date('Y-m-d', $a - $days * 86400), 'to' => date('Y-m-d', $a - 86400), 'label' => $label];
    }

    private static function between(array $p): array
    {
        return [$p['from'] . ' 00:00:00', $p['to'] . ' 23:59:59'];
    }

    /** Filtre d'emplacement (boutique ou magasin nommé dans la question), limité au périmètre. */
    private static function locationWhere(UserContext $u, array $s, string $whCol, string $storeCol = 'w.store_id'): array
    {
        [$w, $p] = $u->warehouseWhere($whCol);
        $label = $u->global ? 'tous magasins confondus' : 'votre boutique';
        $loc = $s['location'] ?? null;
        if ($loc) {
            if ($loc['type'] === 'warehouse') {
                $w .= " AND $whCol = ?";
                $p[] = $loc['id'];
                $label = 'le magasin « ' . $loc['name'] . ' »';
            } else {
                $w .= " AND $storeCol = ?";
                $p[] = $loc['id'];
                $label = 'la boutique « ' . $loc['name'] . ' »';
            }
        }
        $ex = $s['exclude'] ?? null;
        if ($ex) {
            $w .= $ex['type'] === 'warehouse' ? " AND $whCol <> ?" : " AND ($storeCol IS NULL OR $storeCol <> ?)";
            $p[] = $ex['id'];
            $label .= ', sans ' . ($ex['type'] === 'warehouse' ? 'le magasin' : 'la boutique') . ' « ' . $ex['name'] . ' »';
        }
        return [$w, $p, $label];
    }

    private static function salesLocation(UserContext $u, array $s, string $a = 's'): array
    {
        [$w, $p] = $u->salesWhere($a);
        $label = '';
        $loc = $s['location'] ?? null;
        if ($loc && $loc['type'] === 'store') {
            $w .= " AND $a.store_id = ?";
            $p[] = $loc['id'];
            $label = ' (boutique « ' . $loc['name'] . ' »)';
        } elseif ($loc && $loc['type'] === 'warehouse') {
            $w .= " AND $a.warehouse_id = ?";
            $p[] = $loc['id'];
            $label = ' (magasin « ' . $loc['name'] . ' »)';
        }
        if (!empty($s['user']) && $u->seesAllSales()) {
            $w .= " AND $a.user_id = ?";
            $p[] = $s['user']['id'];
            $label .= ' de ' . $s['user']['name'];
        }
        return [$w, $p, $label];
    }

    private static function whose(UserContext $u): string
    {
        return $u->seesAllSales() ? '' : ' (vos ventes)';
    }

    private static function limit(array $s, int $def = 10): int
    {
        return max(1, min(50, (int) ($s['limit'] ?? $def)));
    }

    private static function tableExists(string $t): bool
    {
        return (int) DB::value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) === 1;
    }

    // ------------------------------------------------------------------ STOCK

    private static function stockValue(UserContext $u, array $s): array
    {
        [$w, $p, $label] = self::locationWhere($u, $s, 'l.warehouse_id');
        $r = DB::one("SELECT COALESCE(SUM(l.quantity * p.cost_price),0) v, COUNT(DISTINCT p.id) n, COALESCE(SUM(l.quantity),0) q
            FROM stock_levels l JOIN products p ON p.id = l.product_id JOIN warehouses w ON w.id = l.warehouse_id
            WHERE p.track_stock = 1 AND l.quantity > 0 $w", $p);
        $v = (float) $r['v'];
        $res = ['facts' => [$v, (int) $r['n'], (float) $r['q']], 'sources' => ['stock_levels', 'products', 'warehouses'],
            'variants' => [
                "La valeur actuelle du stock ($label) est de " . self::money($v) . '.',
                "Le stock ($label) représente " . self::short($v) . ', pour ' . self::n((int) $r['n']) . ' articles différents en stock.',
                "Après analyse des quantités disponibles et des coûts d'achat moyens, le stock ($label) est évalué à " . self::money($v) . '.',
            ],
            'cards' => [['Valeur au coût', self::money($v)], ['Articles en stock', self::n((int) $r['n'])], ['Unités', self::n((float) $r['q'])]],
            'note' => 'Valeur au coût moyen pondéré des quantités positives.', 'empty' => (int) $r['n'] === 0];
        if (empty($s['location'])) {
            $by = DB::all("SELECT COALESCE(st.name, 'Sans boutique') store, w.name wh, COALESCE(SUM(l.quantity * p.cost_price),0) v FROM stock_levels l JOIN products p ON p.id = l.product_id
                JOIN warehouses w ON w.id = l.warehouse_id LEFT JOIN stores st ON st.id = w.store_id WHERE p.track_stock = 1 AND l.quantity > 0 $w GROUP BY w.id ORDER BY v DESC LIMIT 20", $p);
            if (count($by) > 1) {
                $res['table'] = ['headers' => ['Boutique', 'Magasin', 'Valeur'], 'rows' => array_map(fn($x) => [$x['store'], $x['wh'], self::money((float) $x['v'])], $by)];
                $res['chart'] = ['type' => 'bar', 'title' => 'Valeur du stock par magasin', 'labels' => array_column($by, 'wh'), 'values' => array_map(fn($x) => round((float) $x['v']), $by)];
                $res['followups'] = ['Et celui de ' . $by[0]['wh'] . ' ?'];
            }
        }
        return $res;
    }

    private static function stockValueSale(UserContext $u, array $s): array
    {
        [$w, $p, $label] = self::locationWhere($u, $s, 'l.warehouse_id');
        $v = (float) DB::value("SELECT COALESCE(SUM(l.quantity * p.sale_price),0) FROM stock_levels l JOIN products p ON p.id = l.product_id JOIN warehouses w ON w.id = l.warehouse_id
            WHERE p.track_stock = 1 AND l.quantity > 0 $w", $p);
        $res = ['facts' => [$v], 'sources' => ['stock_levels', 'products'], 'variants' => [
            "Au prix de vente, le stock ($label) vaut " . self::money($v) . '.', "Si tout le stock ($label) était vendu au prix affiché, il rapporterait " . self::money($v) . '.'],
            'cards' => [['Valeur au prix de vente', self::money($v)]]];
        if ($u->seesCosts()) {
            $c = (float) DB::value("SELECT COALESCE(SUM(l.quantity * p.cost_price),0) FROM stock_levels l JOIN products p ON p.id = l.product_id JOIN warehouses w ON w.id = l.warehouse_id
                WHERE p.track_stock = 1 AND l.quantity > 0 $w", $p);
            $res['cards'][] = ['Valeur au coût', self::money($c)];
            $res['cards'][] = ['Marge potentielle', self::money($v - $c)];
            $res['facts'][] = $c;
            $res['facts'][] = $v - $c;
        }
        return $res;
    }

    private static function stockByLocation(UserContext $u, array $s): array
    {
        [$w, $p] = $u->warehouseWhere('l.warehouse_id');
        $rows = DB::all("SELECT COALESCE(st.name, 'Sans boutique') store, w.name wh, COALESCE(SUM(l.quantity * p.cost_price),0) v, COUNT(DISTINCT p.id) n
            FROM stock_levels l JOIN products p ON p.id = l.product_id JOIN warehouses w ON w.id = l.warehouse_id LEFT JOIN stores st ON st.id = w.store_id
            WHERE p.track_stock = 1 AND l.quantity > 0 $w GROUP BY w.id ORDER BY store, v DESC LIMIT 50", $p);
        $byStore = [];
        foreach ($rows as $r) {
            $byStore[$r['store']] = ($byStore[$r['store']] ?? 0) + (float) $r['v'];
        }
        arsort($byStore);
        $total = array_sum($byStore);
        $parts = array_map(fn($k, $v) => "$k : " . self::money($v), array_keys($byStore), $byStore);
        return ['facts' => array_merge([$total], array_values($byStore), array_map(fn($r) => (float) $r['v'], $rows)), 'sources' => ['stock_levels', 'products', 'warehouses', 'stores'],
            'variants' => ['Valeur du stock par boutique — ' . implode(' ; ', $parts) . '. Total : ' . self::money($total) . '.',
                'Voici la répartition de la valeur du stock (' . self::money($total) . ' au total) : ' . implode(', ', $parts) . '.'],
            'table' => ['headers' => ['Boutique', 'Magasin', 'Articles', 'Valeur'], 'rows' => array_map(fn($r) => [$r['store'], $r['wh'], self::n((int) $r['n']), self::money((float) $r['v'])], $rows)],
            'chart' => ['type' => 'bar', 'title' => 'Valeur du stock par boutique', 'labels' => array_keys($byStore), 'values' => array_map('round', array_values($byStore))], 'empty' => !$rows];
    }

    private static function stockCount(UserContext $u, array $s): array
    {
        [$w, $p, $label] = self::locationWhere($u, $s, 'l.warehouse_id');
        $r = DB::one("SELECT COUNT(DISTINCT p.id) n, COALESCE(SUM(l.quantity),0) q FROM stock_levels l JOIN products p ON p.id = l.product_id JOIN warehouses w ON w.id = l.warehouse_id
            WHERE p.track_stock = 1 AND p.status = 'active' AND l.quantity > 0 $w", $p);
        $total = (int) DB::value("SELECT COUNT(*) FROM products WHERE status = 'active' AND sellable = 1");
        return ['facts' => [(int) $r['n'], (float) $r['q'], $total], 'sources' => ['stock_levels', 'products'],
            'variants' => [self::n((int) $r['n']) . " articles sont disponibles ($label), pour " . self::n((float) $r['q']) . ' unités au total.',
                "Vous avez " . self::n((int) $r['n']) . " articles en stock ($label) sur " . self::n($total) . ' articles actifs au catalogue.'],
            'cards' => [['Articles disponibles', self::n((int) $r['n'])], ['Unités en stock', self::n((float) $r['q'])], ['Articles au catalogue', self::n($total)]]];
    }

    /** Quantité totale par article dans le périmètre (sous-requête réutilisée). */
    private static function qtySql(UserContext $u, array $s): array
    {
        [$w, $p, $label] = self::locationWhere($u, $s, 'l.warehouse_id');
        return ["(SELECT l.product_id, SUM(l.quantity) q FROM stock_levels l JOIN warehouses w ON w.id = l.warehouse_id WHERE 1 = 1 $w GROUP BY l.product_id)", $p, $label];
    }

    private static function stockOut(UserContext $u, array $s): array
    {
        [$sub, $p, $label] = self::qtySql($u, $s);
        $rows = DB::all("SELECT p.name, p.reference, COALESCE(x.q,0) q FROM products p LEFT JOIN $sub x ON x.product_id = p.id
            WHERE p.status = 'active' AND p.track_stock = 1 AND p.sellable = 1 AND COALESCE(x.q,0) <= 0 ORDER BY p.name LIMIT 200", $p);
        $n = count($rows);
        $names = implode(', ', array_slice(array_column($rows, 'name'), 0, 5));
        return ['facts' => [$n], 'sources' => ['products', 'stock_levels'], 'empty' => $n === 0,
            'variants' => $n === 0 ? ["Aucun article n'est en rupture ($label)."] : [
                "$n article" . ($n > 1 ? 's sont' : ' est') . " en rupture ($label)" . ($names ? ' : ' . $names . ($n > 5 ? '…' : '') : '') . '.',
                "Attention, $n article" . ($n > 1 ? 's n\'ont' : ' n\'a') . " plus de stock ($label). Les premiers : $names."],
            'table' => $n ? ['headers' => ['Article', 'Référence', 'Quantité'], 'rows' => array_map(fn($r) => [$r['name'], $r['reference'], self::n((float) $r['q'])], array_slice($rows, 0, 50)), 'more' => max(0, $n - 50)] : null,
            'followups' => $n ? ['Quels produits dois-je recommander ?'] : []];
    }

    private static function stockLow(UserContext $u, array $s): array
    {
        [$sub, $p, $label] = self::qtySql($u, $s);
        $rows = DB::all("SELECT p.name, p.min_stock, x.q FROM products p JOIN $sub x ON x.product_id = p.id
            WHERE p.status = 'active' AND p.track_stock = 1 AND p.min_stock > 0 AND x.q > 0 AND x.q <= p.min_stock ORDER BY x.q / p.min_stock LIMIT 200", $p);
        $n = count($rows);
        return ['facts' => [$n], 'sources' => ['products', 'stock_levels'], 'empty' => $n === 0,
            'variants' => $n === 0 ? ["Aucun article n'est sous son seuil d'alerte ($label)."] : [
                "$n article" . ($n > 1 ? 's ont' : ' a') . " un stock faible ($label), sous leur seuil d'alerte. Le plus juste : " . $rows[0]['name'] . ' (' . self::n((float) $rows[0]['q']) . ' pour un seuil de ' . self::n((float) $rows[0]['min_stock']) . ').',
                "Stock faible ($label) : $n article" . ($n > 1 ? 's' : '') . ' à surveiller, dont ' . implode(', ', array_slice(array_column($rows, 'name'), 0, 4)) . '.'],
            'table' => $n ? ['headers' => ['Article', 'Stock', 'Seuil'], 'rows' => array_map(fn($r) => [$r['name'], self::n((float) $r['q']), self::n((float) $r['min_stock'])], array_slice($rows, 0, 50)), 'more' => max(0, $n - 50)] : null,
            'followups' => $n ? ['Quels produits dois-je recommander ?'] : []];
    }

    private static function productStock(UserContext $u, array $s): array
    {
        $prod = $s['product'] ?? null;
        if (!$prod) {
            return ['clarify' => 'De quel article voulez-vous connaître le stock ? Donnez son nom.', 'facts' => []];
        }
        [$w, $p] = $u->warehouseWhere('l.warehouse_id');
        $rows = DB::all("SELECT w.name, l.quantity FROM stock_levels l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.product_id = ? $w ORDER BY w.name", array_merge([$prod['id']], $p));
        $total = array_sum(array_map(fn($r) => (float) $r['quantity'], $rows));
        $parts = array_map(fn($r) => $r['name'] . ' : ' . self::n((float) $r['quantity']), $rows);
        return ['facts' => array_merge([$total], array_map(fn($r) => (float) $r['quantity'], $rows)), 'sources' => ['stock_levels', 'products', 'warehouses'],
            'variants' => ["Il reste " . self::n($total) . ' unité(s) de « ' . $prod['name'] . ' »' . ($parts ? ' (' . implode(', ', $parts) . ')' : '') . '.',
                "« {$prod['name']} » : " . self::n($total) . ' en stock' . (count($parts) > 1 ? ', réparti ainsi : ' . implode(', ', $parts) : '') . '.'],
            'cards' => [['Stock total', self::n($total)]], 'link' => ['Fiche de l\'article', '/products/' . $prod['id']]];
    }

    private static function dormant(UserContext $u, array $s): array
    {
        $days = (int) ($s['days'] ?? 30);
        [$sub, $p, $label] = self::qtySql($u, $s);
        $rows = DB::all("SELECT p.name, x.q, p.cost_price FROM products p JOIN $sub x ON x.product_id = p.id
            WHERE p.status = 'active' AND x.q > 0 AND NOT EXISTS (SELECT 1 FROM sale_items si JOIN sales sa ON sa.id = si.sale_id WHERE si.product_id = p.id AND sa.status <> 'cancelled' AND sa.created_at >= ?)
            ORDER BY x.q * p.cost_price DESC LIMIT 200", array_merge($p, [date('Y-m-d', strtotime("-$days days"))]));
        $n = count($rows);
        $val = array_sum(array_map(fn($r) => (float) $r['q'] * (float) $r['cost_price'], $rows));
        $res = ['facts' => [$n, $days], 'sources' => ['products', 'stock_levels', 'sales', 'sale_items'], 'empty' => $n === 0,
            'variants' => $n === 0 ? ["Tous les articles en stock ($label) se sont vendus au moins une fois ces $days derniers jours."] : [
                "$n article" . ($n > 1 ? 's' : '') . " en stock ($label) n'" . ($n > 1 ? 'ont' : 'a') . " pas été vendu" . ($n > 1 ? 's' : '') . " depuis $days jours, dont " . implode(', ', array_slice(array_column($rows, 'name'), 0, 4)) . '.',
                "Depuis $days jours, $n article" . ($n > 1 ? 's dorment' : ' dort') . " en stock sans aucune vente ($label)."],
            'table' => $n ? ['headers' => ['Article', 'Stock'], 'rows' => array_map(fn($r) => [$r['name'], self::n((float) $r['q'])], array_slice($rows, 0, 50)), 'more' => max(0, $n - 50)] : null];
        if ($n && $u->seesCosts()) {
            $res['facts'][] = $val;
            $res['cards'] = [['Articles sans vente', self::n($n)], ['Valeur immobilisée', self::money($val)]];
        }
        return $res;
    }

    private static function reorder(UserContext $u, array $s): array
    {
        [$sub, $p, $label] = self::qtySql($u, $s);
        $since = date('Y-m-d', strtotime('-30 days'));
        [$sw, $sp] = $u->storeWhere('sa.store_id');
        $rows = DB::all("SELECT p.id, p.name, p.min_stock, COALESCE(x.q,0) q, COALESCE(v.sold,0) sold, p.cost_price, sup.name supplier
            FROM products p LEFT JOIN $sub x ON x.product_id = p.id
            LEFT JOIN (SELECT si.product_id, SUM(si.base_quantity) sold FROM sale_items si JOIN sales sa ON sa.id = si.sale_id WHERE sa.status <> 'cancelled' AND sa.created_at >= ? $sw GROUP BY si.product_id) v ON v.product_id = p.id
            LEFT JOIN suppliers sup ON sup.id = p.supplier_id
            WHERE p.status = 'active' AND p.track_stock = 1 AND p.sellable = 1 AND (COALESCE(x.q,0) <= p.min_stock OR (COALESCE(v.sold,0) > 0 AND COALESCE(x.q,0) < COALESCE(v.sold,0) / 30 * 7))
            ORDER BY COALESCE(v.sold,0) DESC LIMIT 100", array_merge($p, [$since], $sp));
        $items = [];
        foreach ($rows as $r) {
            $need = max((float) $r['min_stock'] * 2, (float) $r['sold']) - (float) $r['q'];
            if ($need > 0) {
                $items[] = $r + ['suggest' => ceil($need)];
            }
        }
        $n = count($items);
        return ['facts' => [$n], 'sources' => ['products', 'stock_levels', 'sale_items', 'sales', 'suppliers'], 'empty' => $n === 0, 'reorder_items' => $items,
            'variants' => $n === 0 ? ["Aucun réapprovisionnement urgent ($label) : les stocks couvrent les ventes des 30 derniers jours et les seuils."] : [
                "Je vous conseille de recommander $n article" . ($n > 1 ? 's' : '') . " ($label). En priorité : " . implode(', ', array_map(fn($r) => $r['name'] . ' (' . self::n((float) $r['suggest']) . ')', array_slice($items, 0, 4))) . '.',
                "$n article" . ($n > 1 ? 's sont' : ' est') . ' sous le seuil ou couvre' . ($n > 1 ? 'nt' : '') . ' moins d\'une semaine de ventes. Quantités conseillées : ' . implode(', ', array_map(fn($r) => $r['name'] . ' ' . self::n((float) $r['suggest']), array_slice($items, 0, 4))) . '.'],
            'note' => 'Quantité conseillée = ventes des 30 derniers jours (ou deux fois le seuil) moins le stock actuel.',
            'table' => $n ? ['headers' => ['Article', 'Stock', 'Vendu 30 j', 'À commander', 'Fournisseur'], 'rows' => array_map(fn($r) => [$r['name'], self::n((float) $r['q']), self::n((float) $r['sold']), self::n((float) $r['suggest']), $r['supplier'] ?? '—'], array_slice($items, 0, 50))] : null,
            'followups' => $n ? ['Prépare un brouillon de commande fournisseur'] : []];
    }

    private static function expiring(UserContext $u, array $s): array
    {
        $days = (int) ($s['days'] ?? 60);
        [$w, $p] = $u->warehouseWhere('lo.warehouse_id');
        $rows = DB::all("SELECT p.name, lo.lot_number, lo.expiry_date, lo.quantity FROM stock_lots lo JOIN products p ON p.id = lo.product_id
            WHERE lo.quantity > 0 AND lo.expiry_date IS NOT NULL AND lo.expiry_date <= ? $w ORDER BY lo.expiry_date LIMIT 100", array_merge([date('Y-m-d', strtotime("+$days days"))], $p));
        $n = count($rows);
        $past = count(array_filter($rows, fn($r) => $r['expiry_date'] < date('Y-m-d')));
        return ['facts' => [$n, $past, $days], 'sources' => ['stock_lots', 'products'], 'empty' => $n === 0,
            'variants' => $n === 0 ? ["Aucun lot en stock ne périme dans les $days prochains jours."] : ["$n lot" . ($n > 1 ? 's' : '') . " périme" . ($n > 1 ? 'nt' : '') . " dans les $days jours" . ($past ? ", dont $past déjà périmé" . ($past > 1 ? 's' : '') : '') . '. Le premier : ' . $rows[0]['name'] . ' le ' . fdate($rows[0]['expiry_date'], false) . '.'],
            'table' => $n ? ['headers' => ['Article', 'Lot', 'Péremption', 'Quantité'], 'rows' => array_map(fn($r) => [$r['name'], $r['lot_number'], fdate($r['expiry_date'], false), self::n((float) $r['quantity'])], array_slice($rows, 0, 50))] : null];
    }

    private static function transfers(UserContext $u, array $s): array
    {
        $per = self::period($s, '30d');
        [$w, $p] = $u->warehouseWhere('t.from_warehouse_id');
        $rows = DB::all("SELECT t.number, t.created_at, f.name src, d.name dst, u.full_name, (SELECT COUNT(*) FROM stock_transfer_items i WHERE i.transfer_id = t.id) n
            FROM stock_transfers t JOIN warehouses f ON f.id = t.from_warehouse_id JOIN warehouses d ON d.id = t.to_warehouse_id JOIN users u ON u.id = t.user_id
            WHERE t.created_at BETWEEN ? AND ? $w ORDER BY t.id DESC LIMIT 50", array_merge(self::between($per), $p));
        $n = count($rows);
        return ['facts' => [$n], 'sources' => ['stock_transfers', 'stock_transfer_items'], 'empty' => $n === 0, 'period' => $per['label'],
            'variants' => [$n === 0 ? "Aucun transfert de stock {$per['label']}." : "$n transfert" . ($n > 1 ? 's' : '') . " de stock {$per['label']} ; le dernier ({$rows[0]['number']}) de {$rows[0]['src']} vers {$rows[0]['dst']}, le " . fdate($rows[0]['created_at']) . '.'],
            'table' => $n ? ['headers' => ['N°', 'Date', 'De', 'Vers', 'Articles', 'Par'], 'rows' => array_map(fn($r) => [$r['number'], fdate($r['created_at']), $r['src'], $r['dst'], (int) $r['n'], $r['full_name']], $rows)] : null];
    }

    private static function adjustments(UserContext $u, array $s): array
    {
        $per = self::period($s, '30d');
        [$w, $p] = $u->warehouseWhere('m.warehouse_id');
        $rows = DB::all("SELECT m.created_at, p.name, m.quantity, m.unit_cost, m.reason, u.full_name FROM stock_movements m JOIN products p ON p.id = m.product_id LEFT JOIN users u ON u.id = m.user_id
            WHERE m.type IN ('adjustment','loss','inventory') AND m.created_at BETWEEN ? AND ? $w ORDER BY m.id DESC LIMIT 200", array_merge(self::between($per), $p));
        $n = count($rows);
        $loss = array_sum(array_map(fn($r) => (float) $r['quantity'] < 0 ? -(float) $r['quantity'] * (float) $r['unit_cost'] : 0, $rows));
        $res = ['facts' => [$n], 'sources' => ['stock_movements', 'products'], 'empty' => $n === 0, 'period' => $per['label'],
            'variants' => [$n === 0 ? "Aucun ajustement ni perte de stock {$per['label']}." : "$n ajustement" . ($n > 1 ? 's' : '') . " de stock (corrections, pertes, inventaires) {$per['label']}."],
            'table' => $n ? ['headers' => ['Date', 'Article', 'Quantité', 'Motif', 'Par'], 'rows' => array_map(fn($r) => [fdate($r['created_at']), $r['name'], self::n((float) $r['quantity']), $r['reason'] ?? '', $r['full_name'] ?? ''], array_slice($rows, 0, 50))] : null];
        if ($n && $u->seesCosts()) {
            $res['facts'][] = $loss;
            $res['cards'] = [['Ajustements', self::n($n)], ['Valeur des sorties', self::money($loss)]];
        }
        return $res;
    }

    private static function inventories(UserContext $u, array $s): array
    {
        [$w, $p] = $u->warehouseWhere('i.warehouse_id');
        $rows = DB::all("SELECT i.number, i.status, i.created_at, i.validated_at, w.name FROM inventories i JOIN warehouses w ON w.id = i.warehouse_id WHERE 1 = 1 $w ORDER BY i.id DESC LIMIT 20", $p);
        $draft = count(array_filter($rows, fn($r) => $r['status'] === 'draft'));
        $last = array_values(array_filter($rows, fn($r) => $r['status'] === 'validated'))[0] ?? null;
        return ['facts' => [$draft], 'sources' => ['inventories'], 'empty' => !$rows,
            'variants' => [($draft ? "$draft inventaire" . ($draft > 1 ? 's sont' : ' est') . ' en cours (non validé' . ($draft > 1 ? 's' : '') . '). ' : 'Aucun inventaire en cours. ') . ($last ? 'Le dernier inventaire validé (' . $last['number'] . ', ' . $last['name'] . ') date du ' . fdate($last['validated_at']) . '.' : 'Aucun inventaire n\'a encore été validé.')],
            'table' => $rows ? ['headers' => ['N°', 'Magasin', 'État', 'Créé le'], 'rows' => array_map(fn($r) => [$r['number'], $r['name'], ['draft' => 'En cours', 'validated' => 'Validé', 'cancelled' => 'Annulé'][$r['status']], fdate($r['created_at'])], $rows)] : null];
    }

    // ------------------------------------------------------------------ VENTES

    public static function salesFacts(UserContext $u, array $s, array $per): array
    {
        [$w, $p, $lab] = self::salesLocation($u, $s);
        $r = DB::one("SELECT COUNT(*) n, COALESCE(SUM(s.total),0) t FROM sales s WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w", array_merge(self::between($per), $p));
        return ['n' => (int) $r['n'], 't' => (float) $r['t'], 'label' => $lab];
    }

    private static function salesSummary(UserContext $u, array $s): array
    {
        $per = self::period($s, 'today');
        $f = self::salesFacts($u, $s, $per);
        $avg = $f['n'] ? $f['t'] / $f['n'] : 0;
        $who = self::whose($u) . $f['label'];
        return ['facts' => [$f['n'], $f['t'], round($avg)], 'sources' => ['sales'], 'period' => $per['label'], 'metric' => 'sales', 'empty' => $f['n'] === 0,
            'variants' => $f['n'] === 0 ? ["Aucune vente enregistrée {$per['label']}$who."] : [
                ucfirst($per['label']) . ", {$f['n']} vente" . ($f['n'] > 1 ? 's ont été réalisées' : ' a été réalisée') . "$who pour un total de " . self::money($f['t']) . '.',
                "Ventes {$per['label']}$who : {$f['n']} ticket" . ($f['n'] > 1 ? 's' : '') . ', ' . self::money($f['t']) . ' encaissés, soit un panier moyen de ' . self::money($avg) . '.',
                "Vous avez vendu pour " . self::money($f['t']) . " {$per['label']}$who, en {$f['n']} vente" . ($f['n'] > 1 ? 's' : '') . '.'],
            'cards' => [['Ventes', self::n($f['n'])], ['Montant TTC', self::money($f['t'])], ['Panier moyen', self::money($avg)]],
            'followups' => ['Et hier ?', 'Compare avec la semaine dernière', 'Quels sont les produits les plus vendus ?']];
    }

    private static function salesCompare(UserContext $u, array $s): array
    {
        $a = self::period($s, 'week');
        $b = $s['period2'] ?? self::previous($a);
        $fa = self::salesFacts($u, $s, $a);
        $fb = self::salesFacts($u, $s, $b);
        $d = $fa['t'] - $fb['t'];
        $pct = $fb['t'] > 0 ? $d / $fb['t'] * 100 : null;
        $dn = $fa['n'] - $fb['n'];
        $pctN = $fb['n'] > 0 ? $dn / $fb['n'] * 100 : null;
        $trend = $d > 0 ? 'en hausse' : ($d < 0 ? 'en baisse' : 'stables');
        $pctTxt = $pct !== null ? ' (' . ($pct >= 0 ? '+' : '') . self::n(round($pct, 1)) . ' %)' : '';
        return ['facts' => [$fa['n'], $fa['t'], $fb['n'], $fb['t'], $d, abs($d), $dn, abs($dn), $pct !== null ? round($pct, 1) : 0, $pct !== null ? abs(round($pct, 1)) : 0, $pctN !== null ? abs(round($pctN, 1)) : 0],
            'sources' => ['sales'], 'metric' => 'sales_compare', 'period' => $a['label'] . ' / ' . $b['label'],
            'variants' => [
                "Les ventes sont $trend : " . self::money($fa['t']) . " ({$fa['n']} ventes) {$a['label']} contre " . self::money($fb['t']) . " ({$fb['n']} ventes) {$b['label']}, soit " . ($d >= 0 ? '+' : '−') . self::money(abs($d)) . $pctTxt . '.',
                ucfirst($a['label']) . ' : ' . self::money($fa['t']) . ' ; ' . $b['label'] . ' : ' . self::money($fb['t']) . '. Écart : ' . ($d >= 0 ? '+' : '−') . self::money(abs($d)) . $pctTxt . ', et ' . ($dn >= 0 ? '+' : '−') . abs($dn) . ' vente' . (abs($dn) > 1 ? 's' : '') . ($pctN !== null ? ' (' . ($dn >= 0 ? '+' : '−') . self::n(abs(round($pctN, 1))) . ' %)' : '') . '.'],
            'table' => ['headers' => ['Période', 'Du', 'Au', 'Ventes', 'Montant'], 'rows' => [[$a['label'], fdate($a['from'], false), fdate($a['to'], false), $fa['n'], self::money($fa['t'])], [$b['label'], fdate($b['from'], false), fdate($b['to'], false), $fb['n'], self::money($fb['t'])]]],
            'chart' => ['type' => 'bar', 'title' => 'Comparaison', 'labels' => [$a['label'], $b['label']], 'values' => [round($fa['t']), round($fb['t'])]],
            'followups' => $d < 0 ? ['Pourquoi mes ventes ont-elles diminué ?'] : ['Quels produits ont augmenté en vente ?']];
    }

    private static function revenue(UserContext $u, array $s): array
    {
        $per = self::period($s, 'month');
        [$w, $p, $lab] = self::salesLocation($u, $s);
        $r = DB::one("SELECT COUNT(*) n, COALESCE(SUM(s.total),0) t, COALESCE(SUM(s.tax_total),0) tax, COALESCE(SUM(s.discount_total),0) disc FROM sales s
            WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w", array_merge(self::between($per), $p));
        $ret = (float) DB::value("SELECT COALESCE(SUM(r.total),0) FROM sale_returns r JOIN sales s ON s.id = r.sale_id WHERE r.created_at BETWEEN ? AND ? $w", array_merge(self::between($per), $p));
        $t = (float) $r['t'];
        $net = $t - $ret;
        $who = self::whose($u) . $lab;
        return ['facts' => [$t, $ret, $net, (float) $r['tax'], $net - (float) $r['tax'], (int) $r['n']], 'sources' => ['sales', 'sale_returns'], 'period' => $per['label'], 'metric' => 'revenue',
            'variants' => ["Le chiffre d'affaires {$per['label']}$who est de " . self::money($t) . ' TTC' . ($ret > 0 ? ', soit ' . self::money($net) . ' net après ' . self::money($ret) . ' de retours' : '') . '.',
                ucfirst($per['label']) . "$who : " . self::money($t) . ' de ventes TTC (' . self::n((int) $r['n']) . ' ventes)' . ($ret > 0 ? ', ' . self::money($ret) . ' de retours, net ' . self::money($net) : '') . '.'],
            'cards' => [['CA TTC', self::money($t)], ['Retours', self::money($ret)], ['CA net TTC', self::money($net)], ['dont TVA', self::money((float) $r['tax'])], ['CA net HT', self::money($net - (float) $r['tax'])]],
            'note' => 'TTC = toutes taxes comprises ; net = après retours ; HT = net moins la TVA collectée.'];
    }

    private static function bestDay(UserContext $u, array $s): array
    {
        $per = self::period($s, 'year');
        [$w, $p, $lab] = self::salesLocation($u, $s);
        $rows = DB::all("SELECT DATE(s.created_at) d, COUNT(*) n, SUM(s.total) t FROM sales s WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w GROUP BY DATE(s.created_at) ORDER BY t DESC LIMIT 5", array_merge(self::between($per), $p));
        if (!$rows) {
            return ['facts' => [], 'empty' => true, 'sources' => ['sales'], 'variants' => ["Aucune vente sur {$per['label']}."]];
        }
        $b = $rows[0];
        return ['facts' => [(float) $b['t'], (int) $b['n']], 'sources' => ['sales'], 'period' => $per['label'],
            'variants' => ['Votre meilleure journée sur ' . $per['label'] . ' est le ' . fdate($b['d'], false) . ' avec ' . self::money((float) $b['t']) . ' (' . (int) $b['n'] . ' ventes).',
                'Record sur ' . $per['label'] . ' : le ' . fdate($b['d'], false) . ', ' . self::money((float) $b['t']) . ' encaissés en ' . (int) $b['n'] . ' ventes.'],
            'table' => ['headers' => ['Jour', 'Ventes', 'Montant'], 'rows' => array_map(fn($r) => [fdate($r['d'], false), (int) $r['n'], self::money((float) $r['t'])], $rows)]];
    }

    private static function topProducts(UserContext $u, array $s): array
    {
        $per = self::period($s, 'month');
        [$w, $p, $lab] = self::salesLocation($u, $s);
        $lim = self::limit($s, 5);
        $order = ($s['by'] ?? 'qty') === 'revenue' ? 'rev' : 'qty';
        $rows = DB::all("SELECT si.product_name name, SUM(si.base_quantity) qty, SUM(si.line_total) rev FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w GROUP BY si.product_id, si.product_name ORDER BY $order DESC LIMIT $lim", array_merge(self::between($per), $p));
        if (!$rows) {
            return ['facts' => [], 'empty' => true, 'sources' => ['sale_items', 'sales'], 'variants' => ["Aucune vente {$per['label']}, donc pas de classement."]];
        }
        $list = implode(', ', array_map(fn($i, $r) => ($i + 1) . '. ' . $r['name'] . ' (' . self::n((float) $r['qty']) . ')', array_keys($rows), $rows));
        return ['facts' => array_merge(array_map(fn($r) => (float) $r['qty'], $rows), array_map(fn($r) => (float) $r['rev'], $rows), [count($rows)]), 'sources' => ['sale_items', 'sales'], 'period' => $per['label'],
            'variants' => ['Les ' . count($rows) . " articles les plus vendus {$per['label']}" . self::whose($u) . " : $list.", 'Classement des ventes ' . $per['label'] . ' : ' . $list . '. En tête : ' . $rows[0]['name'] . '.'],
            'table' => ['headers' => ['Article', 'Quantité', 'Montant'], 'rows' => array_map(fn($r) => [$r['name'], self::n((float) $r['qty']), self::money((float) $r['rev'])], $rows)],
            'chart' => ['type' => 'bar', 'title' => 'Articles les plus vendus', 'labels' => array_column($rows, 'name'), 'values' => array_map(fn($r) => (float) $r['qty'], $rows)]];
    }

    private static function mostProfitable(UserContext $u, array $s): array
    {
        $per = self::period($s, '90d');
        [$w, $p] = self::salesLocation($u, $s);
        $lim = self::limit($s, 5);
        $rows = DB::all("SELECT si.product_name name, SUM(si.line_total) rev, SUM(si.line_total - si.unit_cost * si.base_quantity) m FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w GROUP BY si.product_id, si.product_name ORDER BY m DESC LIMIT $lim", array_merge(self::between($per), $p));
        if (!$rows) {
            return ['facts' => [], 'empty' => true, 'sources' => ['sale_items', 'sales'], 'variants' => ["Aucune vente sur {$per['label']}."]];
        }
        return ['facts' => array_merge(array_map(fn($r) => (float) $r['m'], $rows), array_map(fn($r) => (float) $r['rev'], $rows)), 'sources' => ['sale_items', 'sales'], 'period' => $per['label'],
            'variants' => ["Sur {$per['label']}, les articles qui rapportent le plus de marge sont : " . implode(', ', array_map(fn($r) => $r['name'] . ' (' . self::money((float) $r['m']) . ')', $rows)) . '.'],
            'note' => 'Marge = ventes TTC − coût d\'achat enregistré à la vente.',
            'table' => ['headers' => ['Article', 'Ventes TTC', 'Marge', 'Taux'], 'rows' => array_map(fn($r) => [$r['name'], self::money((float) $r['rev']), self::money((float) $r['m']), (float) $r['rev'] > 0 ? self::n(round((float) $r['m'] / (float) $r['rev'] * 100, 1)) . ' %' : '—'], $rows)]];
    }

    private static function productGrowth(UserContext $u, array $s): array
    {
        $a = self::period($s, 'week');
        $b = self::previous($a);
        [$w, $p] = self::salesLocation($u, $s);
        $q = fn(array $per) => array_column(DB::all("SELECT si.product_name name, SUM(si.base_quantity) q FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w GROUP BY si.product_id, si.product_name", array_merge(self::between($per), $p)), 'q', 'name');
        $now = $q($a);
        $before = $q($b);
        $up = [];
        foreach ($now as $name => $qty) {
            $d = (float) $qty - (float) ($before[$name] ?? 0);
            if ($d > 0) {
                $up[] = ['name' => $name, 'now' => (float) $qty, 'before' => (float) ($before[$name] ?? 0), 'd' => $d];
            }
        }
        usort($up, fn($x, $y) => $y['d'] <=> $x['d']);
        $up = array_slice($up, 0, self::limit($s, 5));
        return ['facts' => array_merge(array_column($up, 'd'), array_column($up, 'now'), array_column($up, 'before')), 'sources' => ['sale_items', 'sales'], 'empty' => !$up, 'period' => $a['label'],
            'variants' => [$up ? "Par rapport à {$b['label']}, les articles en plus forte hausse {$a['label']} sont : " . implode(', ', array_map(fn($x) => $x['name'] . ' (+' . self::n($x['d']) . ')', $up)) . '.' : "Aucun article n'a augmenté ses ventes {$a['label']} par rapport à {$b['label']}."],
            'table' => $up ? ['headers' => ['Article', $b['label'], $a['label'], 'Hausse'], 'rows' => array_map(fn($x) => [$x['name'], self::n($x['before']), self::n($x['now']), '+' . self::n($x['d'])], $up)] : null];
    }

    private static function byGroup(UserContext $u, array $s, string $expr, string $join, string $default = 'month'): array
    {
        $per = self::period($s, $default);
        [$w, $p] = self::salesLocation($u, $s);
        return [$per, DB::all("SELECT $expr k, COUNT(DISTINCT s.id) n, SUM(s.total) t FROM sales s $join WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w GROUP BY k ORDER BY t DESC LIMIT 50", array_merge(self::between($per), $p))];
    }

    private static function groupResult(string $title, string $col, array $per, array $rows, string $sentence): array
    {
        $total = array_sum(array_map(fn($r) => (float) $r['t'], $rows));
        return ['facts' => array_merge([$total], array_map(fn($r) => (float) $r['t'], $rows), array_map(fn($r) => (int) $r['n'], $rows)), 'period' => $per['label'], 'empty' => !$rows,
            'variants' => [$rows ? sprintf($sentence, $per['label']) . ' ' . implode(' ; ', array_map(fn($r) => ($r['k'] ?? '—') . ' : ' . self::money((float) $r['t']) . ' (' . (int) $r['n'] . ')', array_slice($rows, 0, 6))) . '.' : "Aucune vente {$per['label']}."],
            'table' => $rows ? ['headers' => [$col, 'Ventes', 'Montant', 'Part'], 'rows' => array_map(fn($r) => [$r['k'] ?? '—', (int) $r['n'], self::money((float) $r['t']), $total > 0 ? self::n(round((float) $r['t'] / $total * 100, 1)) . ' %' : '—'], $rows)] : null,
            'chart' => $rows ? ['type' => 'bar', 'title' => $title, 'labels' => array_map(fn($r) => (string) ($r['k'] ?? '—'), $rows), 'values' => array_map(fn($r) => round((float) $r['t']), $rows)] : null];
    }

    private static function salesByUser(UserContext $u, array $s): array
    {
        [$per, $rows] = self::byGroup($u, $s, 'u.full_name', 'JOIN users u ON u.id = s.user_id');
        return self::groupResult('Ventes par utilisateur', 'Utilisateur', $per, $rows, 'Ventes par utilisateur %s :') + ['sources' => ['sales', 'users']];
    }

    private static function topCashier(UserContext $u, array $s): array
    {
        [$per, $rows] = self::byGroup($u, $s, 'u.full_name', 'JOIN users u ON u.id = s.user_id');
        $r = self::groupResult('Ventes par vendeur', 'Vendeur', $per, $rows, 'Classement des vendeurs %s :') + ['sources' => ['sales', 'users']];
        if ($rows) {
            array_unshift($r['variants'], $rows[0]['k'] . ' est le meilleur vendeur ' . $per['label'] . ' avec ' . self::money((float) $rows[0]['t']) . ' en ' . (int) $rows[0]['n'] . ' ventes.');
            $r['variants'] = array_slice($r['variants'], 0, 1);
        }
        return $r;
    }

    private static function salesByStore(UserContext $u, array $s): array
    {
        [$per, $rows] = self::byGroup($u, $s, 'st.name', 'JOIN stores st ON st.id = s.store_id');
        return self::groupResult('Ventes par boutique', 'Boutique', $per, $rows, 'Ventes par boutique %s :') + ['sources' => ['sales', 'stores']];
    }

    private static function compareStores(UserContext $u, array $s): array
    {
        $r = self::salesByStore($u, $s);
        if (!empty($r['table']['rows']) && count($r['table']['rows']) > 1) {
            $first = $r['table']['rows'][0];
            $r['variants'] = ['La boutique la plus performante ' . $r['period'] . ' est ' . $first[0] . ' avec ' . $first[2] . ' (' . $first[3] . ' du total). ' . $r['variants'][0]];
        } elseif (!empty($r['table']['rows'])) {
            $r['variants'] = ['Une seule boutique a des ventes ' . $r['period'] . ' dans votre périmètre : ' . $r['table']['rows'][0][0] . ' (' . $r['table']['rows'][0][2] . ').'];
        }
        return $r;
    }

    private static function salesByMethod(UserContext $u, array $s): array
    {
        $per = self::period($s, 'month');
        [$w, $p] = self::salesLocation($u, $s);
        $rows = DB::all("SELECT sp.method k, COUNT(DISTINCT s.id) n, SUM(sp.amount) t FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
            WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w GROUP BY sp.method ORDER BY t DESC", array_merge(self::between($per), $p));
        foreach ($rows as &$r) {
            $r['k'] = method_label($r['k']);
        }
        unset($r);
        return self::groupResult('Ventes par mode de paiement', 'Mode', $per, $rows, 'Encaissements par mode de paiement %s :') + ['sources' => ['sale_payments', 'sales']];
    }

    private static function salesByHour(UserContext $u, array $s): array
    {
        $per = self::period($s, '30d');
        [$w, $p] = self::salesLocation($u, $s);
        $rows = DB::all("SELECT HOUR(s.created_at) h, COUNT(*) n, SUM(s.total) t FROM sales s WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w GROUP BY h ORDER BY h", array_merge(self::between($per), $p));
        if (!$rows) {
            return ['facts' => [], 'empty' => true, 'sources' => ['sales'], 'variants' => ["Aucune vente sur {$per['label']}."]];
        }
        $best = $rows;
        usort($best, fn($a, $b) => (int) $b['n'] <=> (int) $a['n']);
        return ['facts' => array_map(fn($r) => (int) $r['n'], $rows), 'sources' => ['sales'], 'period' => $per['label'],
            'variants' => ["L'heure de plus forte affluence sur {$per['label']} est " . $best[0]['h'] . ' h – ' . ($best[0]['h'] + 1) . ' h (' . $best[0]['n'] . ' ventes).'],
            'chart' => ['type' => 'bar', 'title' => 'Ventes par heure', 'labels' => array_map(fn($r) => $r['h'] . ' h', $rows), 'values' => array_map(fn($r) => (int) $r['n'], $rows)]];
    }

    private static function salesByCategory(UserContext $u, array $s): array
    {
        $per = self::period($s, 'month');
        [$w, $p] = self::salesLocation($u, $s);
        $rows = DB::all("SELECT COALESCE(c.name, 'Sans catégorie') k, COUNT(DISTINCT s.id) n, SUM(si.line_total) t FROM sale_items si JOIN sales s ON s.id = si.sale_id
            JOIN products pr ON pr.id = si.product_id LEFT JOIN categories c ON c.id = pr.category_id WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w GROUP BY k ORDER BY t DESC LIMIT 30", array_merge(self::between($per), $p));
        return self::groupResult('Ventes par catégorie', 'Catégorie', $per, $rows, 'Ventes par catégorie %s :') + ['sources' => ['sale_items', 'sales', 'products', 'categories']];
    }

    private static function lastSale(UserContext $u, array $s): array
    {
        [$w, $p] = self::salesLocation($u, $s);
        $r = DB::one("SELECT s.id, s.number, s.created_at, s.total, s.status, u.full_name FROM sales s JOIN users u ON u.id = s.user_id WHERE 1 = 1 $w ORDER BY s.id DESC LIMIT 1", $p);
        if (!$r) {
            return ['facts' => [], 'empty' => true, 'sources' => ['sales'], 'variants' => ['Aucune vente n\'est encore enregistrée' . self::whose($u) . '.']];
        }
        return ['facts' => [(float) $r['total']], 'sources' => ['sales', 'users'],
            'variants' => ['Le dernier ticket' . self::whose($u) . " est le {$r['number']}, créé le " . fdate($r['created_at']) . " par {$r['full_name']} pour " . self::money((float) $r['total']) . ($r['status'] === 'cancelled' ? ' (vente annulée)' : '') . '.',
                "Dernière vente : {$r['number']} — " . fdate($r['created_at']) . ", {$r['full_name']}, " . self::money((float) $r['total']) . '.'],
            'link' => ['Ouvrir la vente', '/sales/' . $r['id']]];
    }

    private static function lastSales(UserContext $u, array $s): array
    {
        [$w, $p] = self::salesLocation($u, $s);
        $lim = self::limit($s, 10);
        $rows = DB::all("SELECT s.number, s.created_at, s.total, s.status, u.full_name, c.name customer FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id WHERE 1 = 1 $w ORDER BY s.id DESC LIMIT $lim", $p);
        return ['facts' => array_map(fn($r) => (float) $r['total'], $rows), 'sources' => ['sales', 'users', 'customers'], 'empty' => !$rows,
            'variants' => [$rows ? 'Voici les ' . count($rows) . ' dernières ventes' . self::whose($u) . ' ; la plus récente : ' . $rows[0]['number'] . ' (' . fdate($rows[0]['created_at']) . ', ' . self::money((float) $rows[0]['total']) . ').' : 'Aucune vente.'],
            'table' => $rows ? ['headers' => ['N°', 'Date', 'Vendeur', 'Client', 'Montant', 'État'], 'rows' => array_map(fn($r) => [$r['number'], fdate($r['created_at']), $r['full_name'], $r['customer'] ?? 'comptoir', self::money((float) $r['total']), $r['status'] === 'cancelled' ? 'annulée' : 'ok'], $rows)] : null];
    }

    private static function cancelledSales(UserContext $u, array $s): array
    {
        $per = self::period($s, '30d');
        [$w, $p] = self::salesLocation($u, $s);
        $rows = DB::all("SELECT s.number, s.cancelled_at, s.total, s.cancel_reason, u.full_name, cb.full_name canceller FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN users cb ON cb.id = s.cancelled_by
            WHERE s.status = 'cancelled' AND s.created_at BETWEEN ? AND ? $w ORDER BY s.id DESC LIMIT 100", array_merge(self::between($per), $p));
        $n = count($rows);
        $t = array_sum(array_map(fn($r) => (float) $r['total'], $rows));
        return ['facts' => [$n, $t], 'sources' => ['sales', 'users'], 'period' => $per['label'], 'empty' => $n === 0,
            'variants' => [$n ? "$n vente" . ($n > 1 ? 's ont été annulées' : ' a été annulée') . " {$per['label']}, pour " . self::money($t) . '.' : "Aucune vente annulée {$per['label']}."],
            'table' => $n ? ['headers' => ['N°', 'Annulée le', 'Vendeur', 'Annulée par', 'Montant', 'Motif'], 'rows' => array_map(fn($r) => [$r['number'], fdate($r['cancelled_at']), $r['full_name'], $r['canceller'] ?? '', self::money((float) $r['total']), $r['cancel_reason'] ?? ''], array_slice($rows, 0, 50))] : null];
    }

    private static function returns(UserContext $u, array $s): array
    {
        $per = self::period($s, '30d');
        [$w, $p] = self::salesLocation($u, $s);
        $rows = DB::all("SELECT r.number, r.created_at, r.total, r.refund_method, r.reason, s.number sale FROM sale_returns r JOIN sales s ON s.id = r.sale_id WHERE r.created_at BETWEEN ? AND ? $w ORDER BY r.id DESC LIMIT 100", array_merge(self::between($per), $p));
        $n = count($rows);
        $t = array_sum(array_map(fn($r) => (float) $r['total'], $rows));
        return ['facts' => [$n, $t], 'sources' => ['sale_returns', 'sales'], 'period' => $per['label'], 'empty' => $n === 0,
            'variants' => [$n ? "$n retour" . ($n > 1 ? 's' : '') . " client {$per['label']}, pour " . self::money($t) . ' remboursés ou portés en avoir.' : "Aucun retour client {$per['label']}."],
            'table' => $n ? ['headers' => ['Retour', 'Vente', 'Date', 'Montant', 'Remboursement', 'Motif'], 'rows' => array_map(fn($r) => [$r['number'], $r['sale'], fdate($r['created_at']), self::money((float) $r['total']), method_label($r['refund_method']), $r['reason']], array_slice($rows, 0, 50))] : null];
    }

    private static function discountsTax(UserContext $u, array $s): array
    {
        $per = self::period($s, 'month');
        [$w, $p] = self::salesLocation($u, $s);
        $r = DB::one("SELECT COALESCE(SUM(s.subtotal),0) gross, COALESCE(SUM(s.discount_total),0) disc, COALESCE(SUM(s.tax_total),0) tax, COALESCE(SUM(s.total),0) t FROM sales s
            WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w", array_merge(self::between($per), $p));
        return ['facts' => [(float) $r['gross'], (float) $r['disc'], (float) $r['tax'], (float) $r['t'], (float) $r['t'] - (float) $r['tax']], 'sources' => ['sales'], 'period' => $per['label'],
            'variants' => [ucfirst($per['label']) . ' : montant brut ' . self::money((float) $r['gross']) . ', remises ' . self::money((float) $r['disc']) . ', total TTC ' . self::money((float) $r['t']) . ' dont ' . self::money((float) $r['tax']) . ' de TVA.'],
            'cards' => [['Brut (avant remises)', self::money((float) $r['gross'])], ['Remises', self::money((float) $r['disc'])], ['Total TTC', self::money((float) $r['t'])], ['TVA collectée', self::money((float) $r['tax'])], ['Total HT', self::money((float) $r['t'] - (float) $r['tax'])]]];
    }

    private static function salesDecline(UserContext $u, array $s): array
    {
        return Analytics::explainDecline($u, $s);
    }

    // ------------------------------------------------------------------ CAISSE

    /** Sessions de caisse visibles : toutes (cash.view_all, périmètre boutique) ou celles de l'utilisateur. */
    private static function sessionsWhere(UserContext $u, string $a = 'cs'): array
    {
        if ($u->can('cash.view_all')) {
            return $u->global ? ['', []] : [" AND $a.register_id IN (SELECT id FROM cash_registers WHERE store_id = ?)", [$u->storeId]];
        }
        return [" AND $a.user_id = ?", [$u->id()]];
    }

    private static function cashBalance(UserContext $u, array $s): array
    {
        [$w, $p] = self::sessionsWhere($u);
        $rows = DB::all("SELECT cs.id, r.name, u.full_name, cs.opened_at FROM cash_sessions cs JOIN cash_registers r ON r.id = cs.register_id JOIN users u ON u.id = cs.user_id WHERE cs.status = 'open' $w", $p);
        if (!$rows) {
            return ['facts' => [], 'empty' => true, 'sources' => ['cash_sessions'], 'variants' => ['Aucune caisse n\'est ouverte en ce moment' . ($u->can('cash.view_all') ? '' : ' à votre nom') . '.']];
        }
        $lines = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $e = CashService::expectedCash((int) $r['id']);
            $total += $e;
            $lines[] = [$r['name'], $r['full_name'], fdate($r['opened_at']), self::money($e), $e];
        }
        return ['facts' => array_merge([$total], array_column($lines, 4)), 'sources' => ['cash_sessions', 'cash_movements'],
            'variants' => [count($rows) > 1 ? 'Il y a ' . self::money($total) . ' d\'espèces théoriques dans les ' . count($rows) . ' caisses ouvertes.' : 'Le solde théorique de la caisse ' . $rows[0]['name'] . ' (ouverte par ' . $rows[0]['full_name'] . ') est de ' . self::money($total) . ' en espèces.',
                'Espèces attendues en caisse : ' . self::money($total) . ' (fond de caisse + entrées − sorties en espèces).'],
            'note' => 'Solde théorique en espèces ; le solde réel n\'est connu qu\'au comptage de la clôture.',
            'table' => ['headers' => ['Caisse', 'Ouverte par', 'Depuis', 'Espèces théoriques'], 'rows' => array_map(fn($l) => array_slice($l, 0, 4), $lines)]];
    }

    private static function cashMoves(UserContext $u, array $s, bool $in): array
    {
        $per = self::period($s, 'today');
        [$w, $p] = self::sessionsWhere($u);
        $rows = DB::all("SELECT m.type, m.method, SUM(m.amount) t, COUNT(*) n FROM cash_movements m JOIN cash_sessions cs ON cs.id = m.cash_session_id
            WHERE m.amount " . ($in ? '> 0' : '< 0') . " AND m.created_at BETWEEN ? AND ? $w GROUP BY m.type, m.method ORDER BY ABS(SUM(m.amount)) DESC", array_merge(self::between($per), $p));
        $total = abs(array_sum(array_map(fn($r) => (float) $r['t'], $rows)));
        $cash = abs(array_sum(array_map(fn($r) => $r['method'] === 'cash' ? (float) $r['t'] : 0, $rows)));
        $types = ['sale' => 'Ventes', 'refund' => 'Remboursements', 'cash_in' => 'Apport', 'cash_out' => 'Sortie', 'expense' => 'Dépense', 'income' => 'Recette', 'customer_payment' => 'Règlement client',
            'supplier_payment' => 'Paiement fournisseur', 'sale_cancel' => 'Annulation de vente', 'deposit' => 'Versement banque', 'withdrawal' => 'Retrait'];
        return ['facts' => array_merge([$total, $cash], array_map(fn($r) => abs((float) $r['t']), $rows)), 'sources' => ['cash_movements', 'cash_sessions'], 'period' => $per['label'], 'empty' => !$rows,
            'variants' => [$rows ? ($in ? 'Entrées ' : 'Sorties ') . $per['label'] . ' : ' . self::money($total) . ' au total, dont ' . self::money($cash) . ' en espèces.' : ($in ? 'Aucune entrée ' : 'Aucune sortie ') . "en caisse {$per['label']}."],
            'table' => $rows ? ['headers' => ['Type', 'Mode', 'Opérations', 'Montant'], 'rows' => array_map(fn($r) => [$types[$r['type']] ?? $r['type'], method_label($r['method']), (int) $r['n'], self::money(abs((float) $r['t']))], $rows)] : null];
    }

    private static function cashIn(UserContext $u, array $s): array
    {
        return self::cashMoves($u, $s, true);
    }

    private static function cashOut(UserContext $u, array $s): array
    {
        return self::cashMoves($u, $s, false);
    }

    private static function cashDifference(UserContext $u, array $s): array
    {
        $per = self::period($s, '30d');
        [$w, $p] = self::sessionsWhere($u);
        $rows = DB::all("SELECT cs.closed_at, r.name, u.full_name, cs.expected_amount, cs.counted_amount, cs.difference FROM cash_sessions cs JOIN cash_registers r ON r.id = cs.register_id JOIN users u ON u.id = cs.user_id
            WHERE cs.status = 'closed' AND cs.counted_amount IS NOT NULL AND cs.closed_at BETWEEN ? AND ? $w ORDER BY cs.closed_at DESC LIMIT 100", array_merge(self::between($per), $p));
        $sum = array_sum(array_map(fn($r) => (float) $r['difference'], $rows));
        $neg = count(array_filter($rows, fn($r) => (float) $r['difference'] < -0.009));
        if (!$rows) {
            return ['facts' => [], 'empty' => true, 'sources' => ['cash_sessions'], 'variants' => ["Aucune clôture de caisse comptée {$per['label']}."]];
        }
        $last = $rows[0];
        return ['facts' => array_merge([$sum, abs($sum), $neg, count($rows)], array_map(fn($r) => (float) $r['difference'], $rows), [(float) $last['expected_amount'], (float) $last['counted_amount']]), 'sources' => ['cash_sessions'], 'period' => $per['label'],
            'variants' => ['À la dernière clôture (' . $last['name'] . ', ' . fdate($last['closed_at']) . '), le solde théorique était de ' . self::money((float) $last['expected_amount']) . ' et le compté de ' . self::money((float) $last['counted_amount']) . ', soit un écart de ' . self::money((float) $last['difference']) . '. Sur ' . $per['label'] . ', ' . count($rows) . ' clôtures, écart cumulé ' . self::money($sum) . ($neg ? ", $neg avec un manque" : '') . '.'],
            'table' => ['headers' => ['Clôture', 'Caisse', 'Caissier', 'Théorique', 'Compté', 'Écart'], 'rows' => array_map(fn($r) => [fdate($r['closed_at']), $r['name'], $r['full_name'], self::money((float) $r['expected_amount']), self::money((float) $r['counted_amount']), self::money((float) $r['difference'])], array_slice($rows, 0, 50))]];
    }

    private static function lastCashOut(UserContext $u, array $s): array
    {
        [$w, $p] = self::sessionsWhere($u);
        $r = DB::one("SELECT m.created_at, m.amount, m.type, m.method, m.reason, u.full_name, rg.name FROM cash_movements m JOIN cash_sessions cs ON cs.id = m.cash_session_id
            LEFT JOIN users u ON u.id = m.user_id JOIN cash_registers rg ON rg.id = cs.register_id WHERE m.amount < 0 AND m.method = 'cash' $w ORDER BY m.id DESC LIMIT 1", $p);
        if (!$r) {
            return ['facts' => [], 'empty' => true, 'sources' => ['cash_movements'], 'variants' => ['Aucune sortie d\'espèces n\'est enregistrée dans votre périmètre.']];
        }
        $types = ['cash_out' => 'sortie de caisse', 'expense' => 'dépense', 'refund' => 'remboursement', 'supplier_payment' => 'paiement fournisseur', 'sale_cancel' => 'annulation de vente', 'deposit' => 'versement en banque', 'withdrawal' => 'retrait'];
        return ['facts' => [abs((float) $r['amount'])], 'sources' => ['cash_movements', 'users'],
            'variants' => ['La dernière sortie d\'espèces (' . ($types[$r['type']] ?? $r['type']) . ') a été faite par ' . ($r['full_name'] ?? 'un utilisateur inconnu') . ' le ' . fdate($r['created_at']) . ' sur la caisse ' . $r['name'] . ' : ' . self::money(abs((float) $r['amount'])) . ($r['reason'] ? ' — motif : « ' . $r['reason'] . ' »' : '') . '.']];
    }

    // ------------------------------------------------------------------ CLIENTS, FOURNISSEURS, ACHATS

    private static function customersCount(UserContext $u, array $s): array
    {
        $r = DB::one('SELECT COUNT(*) n, SUM(balance > 0) debt, SUM(created_at >= ?) recent FROM customers WHERE active = 1', [date('Y-m-01')]);
        return ['facts' => [(int) $r['n'], (int) $r['debt'], (int) $r['recent']], 'sources' => ['customers'],
            'variants' => [self::n((int) $r['n']) . ' clients actifs sont enregistrés, dont ' . self::n((int) $r['recent']) . ' créés ce mois-ci et ' . self::n((int) $r['debt']) . ' avec une dette en cours.']];
    }

    private static function customerDebts(UserContext $u, array $s): array
    {
        $rows = DB::all('SELECT id, name, phone, balance, credit_limit FROM customers WHERE active = 1 AND balance > 0 ORDER BY balance DESC LIMIT 200');
        $t = array_sum(array_map(fn($r) => (float) $r['balance'], $rows));
        $n = count($rows);
        return ['facts' => [$n, $t], 'sources' => ['customers'], 'empty' => $n === 0,
            'variants' => [$n ? "$n client" . ($n > 1 ? 's vous doivent' : ' vous doit') . ' au total ' . self::money($t) . '. Le plus gros encours : ' . $rows[0]['name'] . ' (' . self::money((float) $rows[0]['balance']) . ').' : 'Aucun client n\'a de dette en cours.',
                'Créances clients : ' . self::money($t) . " réparties sur $n client" . ($n > 1 ? 's' : '') . '.'],
            'cards' => [['Clients débiteurs', self::n($n)], ['Total des créances', self::money($t)]],
            'table' => $n ? ['headers' => ['Client', 'Téléphone', 'Doit', 'Plafond'], 'rows' => array_map(fn($r) => [$r['name'], $r['phone'] ?? '', self::money((float) $r['balance']), self::money((float) $r['credit_limit'])], array_slice($rows, 0, 50)), 'more' => max(0, $n - 50)] : null];
    }

    private static function topCustomer(UserContext $u, array $s): array
    {
        [$per, $rows] = self::byGroup($u, $s, 'c.name', 'JOIN customers c ON c.id = s.customer_id', 'year');
        $r = self::groupResult('Meilleurs clients', 'Client', $per, array_slice($rows, 0, self::limit($s, 10)), 'Meilleurs clients sur %s :') + ['sources' => ['sales', 'customers']];
        if ($rows) {
            $r['variants'] = [$rows[0]['k'] . ' est votre meilleur client sur ' . $per['label'] . ' : ' . self::money((float) $rows[0]['t']) . ' d\'achats en ' . (int) $rows[0]['n'] . ' passages.'];
        }
        return $r;
    }

    private static function supplierPayables(UserContext $u, array $s): array
    {
        $rows = DB::all('SELECT name, phone, balance FROM suppliers WHERE active = 1 AND balance > 0 ORDER BY balance DESC LIMIT 100');
        $t = array_sum(array_map(fn($r) => (float) $r['balance'], $rows));
        $n = count($rows);
        return ['facts' => [$n, $t], 'sources' => ['suppliers'], 'empty' => $n === 0,
            'variants' => [$n ? "Vous devez " . self::money($t) . " à $n fournisseur" . ($n > 1 ? 's' : '') . ', dont ' . $rows[0]['name'] . ' (' . self::money((float) $rows[0]['balance']) . ').' : 'Aucun fournisseur n\'est à payer.'],
            'table' => $n ? ['headers' => ['Fournisseur', 'Téléphone', 'À payer'], 'rows' => array_map(fn($r) => [$r['name'], $r['phone'] ?? '', self::money((float) $r['balance'])], $rows)] : null];
    }

    private static function purchasesTotal(UserContext $u, array $s): array
    {
        $per = self::period($s, 'month');
        [$w, $p] = $u->warehouseWhere('r.warehouse_id');
        $r = DB::one("SELECT COUNT(*) n, COALESCE(SUM(r.total),0) t FROM receptions r WHERE r.created_at BETWEEN ? AND ? $w", array_merge(self::between($per), $p));
        return ['facts' => [(int) $r['n'], (float) $r['t']], 'sources' => ['receptions'], 'period' => $per['label'], 'empty' => (int) $r['n'] === 0,
            'variants' => [(int) $r['n'] ? "Achats {$per['label']} : " . self::money((float) $r['t']) . ' en ' . (int) $r['n'] . ' réception' . ((int) $r['n'] > 1 ? 's' : '') . ' de marchandise.' : "Aucune réception de marchandise {$per['label']}."]];
    }

    private static function purchaseOrders(UserContext $u, array $s): array
    {
        [$w, $p] = $u->warehouseWhere('po.warehouse_id');
        $rows = DB::all("SELECT po.number, po.status, po.total, po.created_at, sp.name FROM purchase_orders po JOIN suppliers sp ON sp.id = po.supplier_id WHERE po.status IN ('draft','ordered','partial') $w ORDER BY po.id DESC LIMIT 50", $p);
        $n = count($rows);
        return ['facts' => [$n, array_sum(array_map(fn($r) => (float) $r['total'], $rows))], 'sources' => ['purchase_orders', 'suppliers'], 'empty' => $n === 0,
            'variants' => [$n ? "$n commande" . ($n > 1 ? 's' : '') . ' fournisseur en cours (brouillon, envoyée ou partiellement reçue), pour ' . self::money(array_sum(array_map(fn($r) => (float) $r['total'], $rows))) . '.' : 'Aucune commande fournisseur en cours.'],
            'table' => $n ? ['headers' => ['N°', 'Fournisseur', 'État', 'Montant', 'Date'], 'rows' => array_map(fn($r) => [$r['number'], $r['name'], ['draft' => 'Brouillon', 'ordered' => 'Envoyée', 'partial' => 'Partielle'][$r['status']], self::money((float) $r['total']), fdate($r['created_at'], false)], $rows)] : null];
    }

    // ------------------------------------------------------------------ FINANCES

    private static function profit(UserContext $u, array $s): array
    {
        $per = self::period($s, 'month');
        $sum = \App\Services\ReportService::summary($per['from'], $per['to'], $u->global ? (($s['location']['type'] ?? '') === 'store' ? (int) $s['location']['id'] : null) : $u->storeId, $u->seesAllSales() ? null : $u->id());
        return ['facts' => [$sum['net'], $sum['cogs'], $sum['margin'], $sum['expenses'], $sum['incomes'], $sum['profit'], round($sum['margin_rate'], 1)], 'sources' => ['sales', 'sale_returns', 'expenses'], 'period' => $per['label'],
            'variants' => ['Le bénéfice estimé ' . $per['label'] . ' est de ' . self::money($sum['profit']) . ' : marge sur ventes ' . self::money($sum['margin']) . ' (' . self::n(round($sum['margin_rate'], 1)) . ' %), moins ' . self::money($sum['expenses']) . ' de dépenses' . ($sum['incomes'] > 0 ? ', plus ' . self::money($sum['incomes']) . ' de recettes diverses' : '') . '.',
                ucfirst($per['label']) . ' : ventes nettes ' . self::money($sum['net']) . ', coût des marchandises vendues ' . self::money($sum['cogs']) . ', marge ' . self::money($sum['margin']) . ', dépenses ' . self::money($sum['expenses']) . ' → bénéfice estimé ' . self::money($sum['profit']) . '.'],
            'cards' => [['Ventes nettes', self::money($sum['net'])], ['Coût des ventes', self::money($sum['cogs'])], ['Marge', self::money($sum['margin'])], ['Dépenses', self::money($sum['expenses'])], ['Bénéfice estimé', self::money($sum['profit'])]],
            'note' => 'Estimation de gestion (TTC, coût moyen enregistré à la vente) ; elle ne remplace pas le résultat comptable.'];
    }

    private static function expensesTotal(UserContext $u, array $s): array
    {
        $per = self::period($s, 'month');
        [$w, $p] = $u->storeWhere('e.store_id', true);
        $rows = DB::all("SELECT c.name k, COUNT(*) n, SUM(e.amount) t FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.status = 'valid' AND e.type = 'expense' AND e.expense_date BETWEEN ? AND ? $w GROUP BY c.id ORDER BY t DESC", array_merge([$per['from'], $per['to']], $p));
        $t = array_sum(array_map(fn($r) => (float) $r['t'], $rows));
        $r = self::groupResult('Dépenses par catégorie', 'Catégorie', $per, $rows, 'Dépenses %s par catégorie :') + ['sources' => ['expenses', 'expense_categories']];
        $r['variants'] = [$rows ? 'Vous avez dépensé ' . self::money($t) . ' ' . $per['label'] . ', surtout en ' . $rows[0]['k'] . ' (' . self::money((float) $rows[0]['t']) . ').' : "Aucune dépense enregistrée {$per['label']}."];
        return $r;
    }

    private static function expensesTrend(UserContext $u, array $s): array
    {
        [$w, $p] = $u->storeWhere('e.store_id', true);
        $from = date('Y-m-01', strtotime('-2 months'));
        $rows = DB::all("SELECT DATE_FORMAT(e.expense_date, '%Y-%m') m, SUM(e.amount) t, COUNT(*) n FROM expenses e WHERE e.status = 'valid' AND e.type = 'expense' AND e.expense_date >= ? $w GROUP BY m ORDER BY m", array_merge([$from], $p));
        $top = DB::all("SELECT c.name, SUM(e.amount) t FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.status = 'valid' AND e.type = 'expense' AND e.expense_date >= ? $w GROUP BY c.id ORDER BY t DESC LIMIT 3", array_merge([$from], $p));
        $total = array_sum(array_map(fn($r) => (float) $r['t'], $rows));
        return ['facts' => array_merge([$total], array_map(fn($r) => (float) $r['t'], $rows), array_map(fn($r) => (float) $r['t'], $top)), 'sources' => ['expenses', 'expense_categories'], 'empty' => !$rows, 'period' => 'les 3 derniers mois',
            'variants' => [$rows ? 'Sur les 3 derniers mois, les dépenses s\'élèvent à ' . self::money($total) . ' (' . implode(', ', array_map(fn($r) => Text::monthLabelOf($r['m']) . ' : ' . self::money((float) $r['t']), $rows)) . '). Principaux postes : ' . implode(', ', array_map(fn($r) => $r['name'] . ' ' . self::money((float) $r['t']), $top)) . '.' : 'Aucune dépense sur les 3 derniers mois.'],
            'chart' => $rows ? ['type' => 'bar', 'title' => 'Dépenses par mois', 'labels' => array_map(fn($r) => Text::monthLabelOf($r['m']), $rows), 'values' => array_map(fn($r) => round((float) $r['t']), $rows)] : null];
    }

    private static function incomeVsExpenses(UserContext $u, array $s): array
    {
        $per = self::period($s, 'month');
        $sum = \App\Services\ReportService::summary($per['from'], $per['to'], $u->global ? null : $u->storeId, $u->seesAllSales() ? null : $u->id());
        [$w, $p] = $u->warehouseWhere('r.warehouse_id');
        $purch = (float) DB::value("SELECT COALESCE(SUM(r.total),0) FROM receptions r WHERE r.created_at BETWEEN ? AND ? $w", array_merge(self::between($per), $p));
        $in = $sum['net'] + $sum['incomes'];
        $out = $sum['expenses'];
        return ['facts' => [$in, $out, $in - $out, $sum['net'], $sum['incomes'], $purch], 'sources' => ['sales', 'sale_returns', 'expenses', 'receptions'], 'period' => $per['label'],
            'variants' => [ucfirst($per['label']) . ' : recettes ' . self::money($in) . ' (ventes nettes ' . self::money($sum['net']) . ($sum['incomes'] > 0 ? ' + recettes diverses ' . self::money($sum['incomes']) : '') . ') contre ' . self::money($out) . ' de dépenses, soit ' . self::money($in - $out) . ' d\'écart. Les achats de marchandises (' . self::money($purch) . ') sont suivis à part, car ils deviennent du stock.'],
            'chart' => ['type' => 'bar', 'title' => 'Recettes et dépenses', 'labels' => ['Recettes', 'Dépenses', 'Achats'], 'values' => [round($in), round($out), round($purch)]]];
    }

    // ------------------------------------------------------------------ SYNTHÈSES

    private static function dailyReport(UserContext $u, array $s): array
    {
        return Analytics::report($u, self::period($s, 'today'));
    }

    private static function activitySummary(UserContext $u, array $s): array
    {
        return Analytics::report($u, self::period($s, 'month'));
    }

    private static function anomalies(UserContext $u, array $s): array
    {
        return Analytics::anomalies($u, self::period($s, '30d'));
    }

    private static function lastTransactions(UserContext $u, array $s): array
    {
        $lim = self::limit($s, 10);
        $items = [];
        if ($u->can('sales.view')) {
            [$w, $p] = $u->salesWhere('s');
            foreach (DB::all("SELECT s.created_at d, s.number ref, s.total a, s.status FROM sales s WHERE 1 = 1 $w ORDER BY s.id DESC LIMIT $lim", $p) as $r) {
                $items[] = [$r['d'], $r['status'] === 'cancelled' ? 'Vente annulée' : 'Vente', $r['ref'], (float) $r['a']];
            }
            foreach (DB::all("SELECT r.created_at d, r.number ref, r.total a FROM sale_returns r JOIN sales s ON s.id = r.sale_id WHERE 1 = 1 $w ORDER BY r.id DESC LIMIT $lim", $p) as $r) {
                $items[] = [$r['d'], 'Retour client', $r['ref'], -(float) $r['a']];
            }
        }
        if ($u->can('expenses.manage')) {
            [$w, $p] = $u->storeWhere('e.store_id', true);
            foreach (DB::all("SELECT e.created_at d, e.number ref, e.amount a, e.type FROM expenses e WHERE e.status = 'valid' $w ORDER BY e.id DESC LIMIT $lim", $p) as $r) {
                $items[] = [$r['d'], $r['type'] === 'income' ? 'Recette' : 'Dépense', $r['ref'], $r['type'] === 'income' ? (float) $r['a'] : -(float) $r['a']];
            }
        }
        if ($u->can('stock.view')) {
            [$w, $p] = $u->warehouseWhere('t.from_warehouse_id');
            foreach (DB::all("SELECT t.created_at d, t.number ref FROM stock_transfers t WHERE 1 = 1 $w ORDER BY t.id DESC LIMIT $lim", $p) as $r) {
                $items[] = [$r['d'], 'Transfert de stock', $r['ref'], 0.0];
            }
        }
        usort($items, fn($a, $b) => strcmp($b[0], $a[0]));
        $items = array_slice($items, 0, $lim);
        return ['facts' => array_map(fn($i) => abs($i[3]), $items), 'sources' => ['sales', 'sale_returns', 'expenses', 'stock_transfers'], 'empty' => !$items,
            'variants' => [$items ? 'Voici les ' . count($items) . ' dernières opérations de votre périmètre ; la plus récente : ' . $items[0][1] . ' ' . $items[0][2] . ' le ' . fdate($items[0][0]) . '.' : 'Aucune opération visible avec vos droits.'],
            'table' => $items ? ['headers' => ['Date', 'Type', 'Référence', 'Montant'], 'rows' => array_map(fn($i) => [fdate($i[0]), $i[1], $i[2], $i[3] == 0.0 ? '—' : self::money($i[3])], $items)] : null];
    }

    // ------------------------------------------------------------------ MODULES

    private static function epaySummary(UserContext $u, array $s): array
    {
        if (!Addons::isActive('pay') || !self::tableExists('payment_transactions')) {
            return ['facts' => [], 'empty' => true, 'variants' => ['Le module Marwa Pay Connect n\'est pas actif : aucun paiement électronique à analyser.']];
        }
        $per = self::period($s, 'month');
        $r = DB::one("SELECT COUNT(*) n, SUM(status IN ('succeeded','refunded','partially_refunded')) ok, SUM(status = 'declined') ko, SUM(status IN ('pending','processing','to_verify')) wait,
            COALESCE(SUM(CASE WHEN status IN ('succeeded','refunded','partially_refunded') THEN amount END),0) t, COALESCE(SUM(refunded_amount),0) rf FROM payment_transactions WHERE is_test = 0 AND created_at BETWEEN ? AND ?", self::between($per));
        return ['facts' => [(int) $r['n'], (int) $r['ok'], (int) $r['ko'], (int) $r['wait'], (float) $r['t'], (float) $r['rf']], 'sources' => ['payment_transactions'], 'period' => $per['label'],
            'variants' => ['Paiements électroniques réels ' . $per['label'] . ' : ' . (int) $r['n'] . ' transactions, ' . (int) $r['ok'] . ' réussies pour ' . self::money((float) $r['t']) . ', ' . (int) $r['ko'] . ' refusées, ' . (int) $r['wait'] . ' en attente ou à vérifier ; ' . self::money((float) $r['rf']) . ' remboursés (transactions TEST exclues).']];
    }

    private static function fiscalSummary(UserContext $u, array $s): array
    {
        if (!Addons::isActive('fiscal') || !self::tableExists('fiscal_certifications')) {
            return ['facts' => [], 'empty' => true, 'variants' => ['Le module Fiscal Connect n\'est pas actif : aucune certification fiscale.']];
        }
        $per = self::period($s, 'month');
        $r = DB::one("SELECT SUM(status = 'certified' AND is_simulation = 0 AND environment = 'prod') prod, SUM(status = 'certified' AND (is_simulation = 1 OR environment = 'test')) test,
            SUM(status IN ('pending','sending')) wait, SUM(status IN ('rejected','failed')) ko FROM fiscal_certifications WHERE created_at BETWEEN ? AND ?", self::between($per));
        return ['facts' => [(int) $r['prod'], (int) $r['test'], (int) $r['wait'], (int) $r['ko']], 'sources' => ['fiscal_certifications'], 'period' => $per['label'],
            'variants' => ['Certifications fiscales ' . $per['label'] . ' : ' . (int) $r['prod'] . ' certifiées en production, ' . (int) $r['test'] . ' en test ou simulation (sans valeur fiscale), ' . (int) $r['wait'] . ' en attente, ' . (int) $r['ko'] . ' refusées ou en échec.']];
    }

    private static function usersCount(UserContext $u, array $s): array
    {
        $rows = DB::all('SELECT r.name k, COUNT(*) n FROM users us JOIN roles r ON r.id = us.role_id WHERE us.active = 1 GROUP BY r.id ORDER BY n DESC');
        $t = array_sum(array_map(fn($r) => (int) $r['n'], $rows));
        return ['facts' => array_merge([$t], array_map(fn($r) => (int) $r['n'], $rows)), 'sources' => ['users', 'roles'],
            'variants' => [$t . ' utilisateurs actifs : ' . implode(', ', array_map(fn($r) => $r['k'] . ' ' . $r['n'], $rows)) . '.'],
            'table' => ['headers' => ['Rôle', 'Utilisateurs'], 'rows' => array_map(fn($r) => [$r['k'], (int) $r['n']], $rows)]];
    }

    private static function whoami(UserContext $u, array $s): array
    {
        $d = $u->describe();
        return ['facts' => [$d['permissions']], 'sources' => ['users', 'roles', 'role_permissions'],
            'variants' => ['Vous êtes connecté en tant que ' . $d['name'] . ' (' . $d['role'] . ')' . ($d['store'] ? ', boutique ' . $d['store'] : '') . ($d['register'] ? ', caisse ' . $d['register'] : '') . '. Je consulte pour vous ' . $d['scope'] . ' et ' . $d['sales'] . ', avec vos ' . $d['permissions'] . ' droits.']];
    }

    private static function wifiTickets(UserContext $u, array $s): array
    {
        $wifi = array_filter(array_keys(Addons::active()), fn($c) => preg_match('/wifi|hotspot|connect_ticket|voucher/', $c));
        return ['facts' => [], 'empty' => true, 'sources' => [],
            'variants' => [$wifi ? 'Un module de tickets Wi-Fi est installé (' . implode(', ', $wifi) . '), mais je ne connais pas encore sa structure : je ne peux pas donner de chiffres fiables.'
                : 'POS Marwa ne comporte pas de module de tickets Wi-Fi (Marwa Connect) : il n\'y a donc aucun ticket Wi-Fi généré, utilisé ou expiré à compter. Si vous parlez des tickets de caisse, demandez par exemple « combien de ventes aujourd\'hui ? ».']];
    }
}
