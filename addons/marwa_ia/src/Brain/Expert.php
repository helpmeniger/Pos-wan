<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use App\Core\DB;

/**
 * MARWA BUSINESS EXPERT ENGINE — outils de lecture complémentaires, diagnostic et conseils de gestion.
 *
 * Chaque outil est appelé par Catalog::run (transaction en lecture seule, droits déjà vérifiés) et renvoie le même
 * format que les autres outils. Les recommandations distinguent toujours :
 *  - ce qui est CALCULÉ à partir des données de l'entreprise (avec le chiffre et sa source) ;
 *  - les CONSEILS GÉNÉRAUX de bonne gestion, présentés comme tels.
 */
final class Expert
{
    /** Exemples d'entraînement du classifieur pour les intentions de ce moteur. */
    public const EXAMPLES = [
        'advice' => ['conseille moi pour ameliorer mon magasin', 'quels conseils peux tu me donner pour ameliorer ma boutique', 'tu me conseilles quoi', 'comment je peux ameliorer ca', 'que dois je faire pour gagner plus',
            'comment augmenter mon benefice', 'donne moi des recommandations', 'qu est ce que je devrais changer', 'aide moi a mieux gerer', 'quelles decisions prendre', 'comment faire progresser mon commerce'],
        'whats_wrong' => ['je veux voir ce qui ne va pas', 'qu est ce qui ne va pas', 'quels sont les problemes', 'qu est ce qui manque', 'y a t il des alertes', 'les points a surveiller', 'qu est ce qui cloche',
            'montre moi les problemes du jour', 'quelles sont les urgences', 'il y a quoi a regler'],
        'cash_diagnostic' => ['c est quoi le probleme avec ma caisse', 'pourquoi ma caisse ne tombe pas juste', 'ma caisse a un probleme', 'verifie ma caisse', 'pourquoi il manque de l argent dans la caisse',
            'explique l ecart de ma caisse', 'controle de ma caisse', 'ma caisse est elle correcte'],
        'cash_sessions_open' => ['quelles caisses sont ouvertes', 'qui a une caisse ouverte', 'caisses ouvertes en ce moment', 'est ce que ma caisse est ouverte', 'sessions de caisse en cours'],
        'last_stock_in' => ['quelle est la derniere entree en stock', 'derniere reception de marchandise', 'dernier ajout de stock', 'qui a fait la derniere entree de stock', 'quand a t on recu du stock pour la derniere fois'],
        'stock_movements' => ['derniers mouvements de stock', 'historique du stock', 'entrees et sorties de stock', 'mouvements de stock du jour', 'qu est ce qui a bouge dans le stock'],
        'stock_in_total' => ['combien d entrees de stock ce mois', 'total des entrees en stock', 'quantite recue cette semaine', 'combien on a recu de marchandise'],
        'product_info' => ['quel est le prix du riz', 'prix de vente de l article', 'combien coute le savon', 'c est combien le sucre', 'reference de l article', 'fiche de l article', 'a combien on vend l huile'],
        'product_sales' => ['combien de savon vendu ce mois', 'ventes de l article riz', 'combien on a vendu de sucre', 'quantite vendue du produit', 'chiffre de l article cette semaine', 'est ce que le riz se vend bien'],
        'items_sold' => ['combien d articles avons nous vendus aujourd hui', 'nombre d articles vendus', 'quantite d articles vendus ce mois', 'combien de pieces vendues', 'combien d unites sorties en vente'],
        'customer_info' => ['cherche le client amadou', 'fiche du client', 'combien doit le client moussa', 'solde du client', 'trouve moi le client', 'numero de telephone du client', 'infos sur le client'],
        'supplier_info' => ['cherche le fournisseur', 'combien je dois au fournisseur', 'fiche du fournisseur', 'solde du fournisseur', 'contact du fournisseur', 'infos sur le fournisseur'],
        'expenses_by_category' => ['depenses par categorie', 'repartition des depenses', 'ou part mon argent', 'quelles sont mes plus grosses depenses', 'detail des charges du mois'],
        'margin_rate' => ['quel est mon taux de marge', 'marge du mois', 'combien je marge', 'ma marge brute', 'pourcentage de marge'],
        'customer_payments' => ['reglements des clients ce mois', 'combien les clients ont rembourse', 'paiements recus des clients', 'remboursements de dettes clients', 'qui a paye sa dette'],
    ];

    private static function between(array $p): array
    {
        return [$p['from'] . ' 00:00:00', $p['to'] . ' 23:59:59'];
    }

    /** Libellé lisible d'un type de mouvement de stock. */
    public static function movementLabel(string $type): string
    {
        return ['reception' => 'réception fournisseur', 'quick_entry' => 'ajout de stock rapide', 'import' => 'import de stock', 'initial' => 'stock initial', 'adjustment' => 'ajustement', 'transfer_in' => 'transfert reçu',
            'transfer_out' => 'transfert envoyé', 'sale' => 'vente', 'return' => 'retour client', 'sale_cancel' => 'annulation de vente', 'supplier_return' => 'retour fournisseur', 'loss' => 'perte / casse',
            'inventory' => 'inventaire', 'kitchen_waste' => 'perte cuisine'][$type] ?? $type;
    }

    private static function summary(UserContext $u, array $per): array
    {
        return \App\Services\ReportService::summary($per['from'], $per['to'], $u->global ? null : $u->storeId, $u->seesAllSales() ? null : $u->id());
    }

    // ------------------------------------------------------------------ stock

    public static function lastStockIn(UserContext $u, array $s): array
    {
        [$w, $p] = $u->warehouseWhere('m.warehouse_id');
        $r = DB::one("SELECT m.created_at, m.type, m.quantity, m.reason, p.name, wh.name wh, us.full_name FROM stock_movements m JOIN products p ON p.id = m.product_id JOIN warehouses wh ON wh.id = m.warehouse_id
            LEFT JOIN users us ON us.id = m.user_id WHERE m.quantity > 0 AND m.type IN ('reception','quick_entry','import','initial','adjustment','transfer_in') $w ORDER BY m.id DESC LIMIT 1", $p);
        if (!$r) {
            return ['empty' => true, 'sources' => ['stock_movements'], 'variants' => ['Aucune entrée en stock n\'est enregistrée dans votre périmètre.']];
        }
        $t = self::movementLabel($r['type']);
        return ['facts' => [(float) $r['quantity']], 'sources' => ['stock_movements'],
            'variants' => ['La dernière entrée en stock date du ' . fdate($r['created_at']) . ' : ' . Catalog::n((float) $r['quantity']) . ' × ' . $r['name'] . ' dans ' . $r['wh'] . ' (' . $t . ')' . ($r['full_name'] ? ', par ' . $r['full_name'] : '') . '.',
                'Dernière entrée : ' . $r['name'] . ', ' . Catalog::n((float) $r['quantity']) . ' unité(s), le ' . fdate($r['created_at']) . ' (' . $t . ', ' . $r['wh'] . ($r['full_name'] ? ', ' . $r['full_name'] : '') . ').'],
            'followups' => ['Derniers mouvements de stock', 'Quels produits sont en rupture ?']];
    }

    public static function stockMovements(UserContext $u, array $s): array
    {
        [$w, $p] = $u->warehouseWhere('m.warehouse_id');
        $lim = max(1, min(50, (int) ($s['limit'] ?? 10)));
        $extra = '';
        if (!empty($s['period'])) {
            $extra = ' AND m.created_at BETWEEN ? AND ?';
            $p = array_merge($p, self::between($s['period']));
        }
        if (!empty($s['product'])) {
            $extra .= ' AND m.product_id = ?';
            $p[] = $s['product']['id'];
        }
        $rows = DB::all("SELECT m.created_at, m.type, m.quantity, p.name, wh.name wh, us.full_name FROM stock_movements m JOIN products p ON p.id = m.product_id JOIN warehouses wh ON wh.id = m.warehouse_id
            LEFT JOIN users us ON us.id = m.user_id WHERE 1 = 1 $w $extra ORDER BY m.id DESC LIMIT $lim", $p);
        if (!$rows) {
            return ['empty' => true, 'sources' => ['stock_movements'], 'variants' => ['Aucun mouvement de stock' . (!empty($s['period']) ? ' ' . $s['period']['label'] : '') . '.']];
        }
        return ['facts' => array_map(fn($r) => abs((float) $r['quantity']), $rows), 'sources' => ['stock_movements'], 'link' => ['Mouvements de stock', '/stock/movements'],
            'variants' => ['Voici les ' . count($rows) . ' derniers mouvements de stock' . (!empty($s['product']) ? ' de ' . $s['product']['name'] : '') . ' ; le plus récent : ' . self::movementLabel($rows[0]['type']) . ' de ' . $rows[0]['name'] . ' le ' . fdate($rows[0]['created_at']) . '.'],
            'table' => ['headers' => ['Date', 'Type', 'Article', 'Quantité', 'Magasin', 'Par'], 'rows' => array_map(fn($r) => [fdate($r['created_at']), self::movementLabel($r['type']), $r['name'], ((float) $r['quantity'] > 0 ? '+' : '') . Catalog::n((float) $r['quantity']), $r['wh'], $r['full_name'] ?? '—'], $rows), 'more' => 0]];
    }

    public static function stockInTotal(UserContext $u, array $s): array
    {
        $per = Catalog::period($s, 'month');
        [$w, $p] = $u->warehouseWhere('m.warehouse_id');
        $rows = DB::all("SELECT m.type, COUNT(*) n, SUM(m.quantity) q FROM stock_movements m WHERE m.quantity > 0 AND m.type IN ('reception','quick_entry','import','initial','adjustment') AND m.created_at BETWEEN ? AND ? $w GROUP BY m.type ORDER BY q DESC",
            array_merge(self::between($per), $p));
        $n = array_sum(array_map(fn($r) => (int) $r['n'], $rows));
        $q = array_sum(array_map(fn($r) => (float) $r['q'], $rows));
        if (!$rows) {
            return ['empty' => true, 'sources' => ['stock_movements'], 'period' => $per['label'], 'variants' => ["Aucune entrée en stock {$per['label']}."]];
        }
        return ['facts' => array_merge([$n, $q], array_map(fn($r) => (float) $r['q'], $rows), array_map(fn($r) => (int) $r['n'], $rows)), 'sources' => ['stock_movements'], 'period' => $per['label'],
            'variants' => [ucfirst($per['label']) . ', ' . Catalog::n($q) . ' unité(s) sont entrées en stock en ' . $n . ' opération(s).', 'Entrées en stock ' . $per['label'] . ' : ' . Catalog::n($q) . ' unité(s), ' . $n . ' mouvement(s).'],
            'table' => ['headers' => ['Type d\'entrée', 'Opérations', 'Quantité'], 'rows' => array_map(fn($r) => [self::movementLabel($r['type']), (int) $r['n'], Catalog::n((float) $r['q'])], $rows), 'more' => 0]];
    }

    public static function productInfo(UserContext $u, array $s): array
    {
        $pr = $s['product'] ?? null;
        if (!$pr) {
            return ['clarify' => 'De quel article voulez-vous le prix ? Donnez son nom ou sa référence.'];
        }
        $r = DB::one('SELECT p.name, p.reference, p.barcode, p.sale_price, p.wholesale_price, p.wholesale_min_qty, p.cost_price, p.min_stock, p.track_stock, c.name cat FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?', [$pr['id']]);
        [$w, $p] = $u->warehouseWhere('l.warehouse_id');
        $q = (float) DB::value("SELECT COALESCE(SUM(l.quantity),0) FROM stock_levels l WHERE l.product_id = ? $w", array_merge([$pr['id']], $p));
        $facts = [(float) $r['sale_price'], $q];
        $txt = $r['name'] . ' se vend ' . Catalog::money((float) $r['sale_price']);
        if ((float) $r['wholesale_price'] > 0) {
            $txt .= ' (prix de gros ' . Catalog::money((float) $r['wholesale_price']) . ' à partir de ' . Catalog::n((float) $r['wholesale_min_qty']) . ')';
            $facts[] = (float) $r['wholesale_price'];
            $facts[] = (float) $r['wholesale_min_qty'];
        }
        $cards = [['Prix de vente', Catalog::money((float) $r['sale_price'])], ['Stock', (int) $r['track_stock'] ? Catalog::n($q) : 'non suivi']];
        if ($u->seesCosts()) {
            $cards[] = ['Prix d\'achat (CMP)', Catalog::money((float) $r['cost_price'])];
            $facts[] = (float) $r['cost_price'];
        }
        return ['facts' => $facts, 'sources' => ['products', 'stock_levels'], 'cards' => $cards, 'link' => ['Articles', '/products'],
            'variants' => [$txt . '. Référence ' . $r['reference'] . ((int) $r['track_stock'] ? ', ' . Catalog::n($q) . ' en stock' : '') . '.', $txt . ((int) $r['track_stock'] ? ' ; il en reste ' . Catalog::n($q) : '') . ' (réf. ' . $r['reference'] . ').'],
            'followups' => ['Combien en a-t-on vendu ce mois ?']];
    }

    public static function productSales(UserContext $u, array $s): array
    {
        $pr = $s['product'] ?? null;
        if (!$pr) {
            return ['clarify' => 'De quel article voulez-vous les ventes ? Donnez son nom.'];
        }
        $per = Catalog::period($s, 'month');
        [$w, $p] = $u->salesWhere('s');
        $r = DB::one("SELECT COALESCE(SUM(i.base_quantity),0) q, COALESCE(SUM(i.line_total),0) t, COUNT(DISTINCT s.id) n FROM sale_items i JOIN sales s ON s.id = i.sale_id
            WHERE s.status <> 'cancelled' AND i.product_id = ? AND s.created_at BETWEEN ? AND ? $w", array_merge([$pr['id']], self::between($per), $p));
        if ((float) $r['q'] <= 0) {
            return ['empty' => true, 'sources' => ['sales', 'sale_items'], 'period' => $per['label'], 'variants' => ['Aucune vente de ' . $pr['name'] . ' ' . $per['label'] . '.']];
        }
        return ['facts' => [(float) $r['q'], (float) $r['t'], (int) $r['n']], 'sources' => ['sales', 'sale_items'], 'period' => $per['label'],
            'cards' => [['Quantité vendue', Catalog::n((float) $r['q'])], ['Montant', Catalog::money((float) $r['t'])], ['Ventes', (int) $r['n']]],
            'variants' => [ucfirst($per['label']) . ', ' . Catalog::n((float) $r['q']) . ' ' . $pr['name'] . ' ont été vendus pour ' . Catalog::money((float) $r['t']) . ' (' . (int) $r['n'] . ' ventes).',
                $pr['name'] . ' : ' . Catalog::n((float) $r['q']) . ' vendu(s) ' . $per['label'] . ', soit ' . Catalog::money((float) $r['t']) . '.']];
    }

    public static function itemsSold(UserContext $u, array $s): array
    {
        $per = Catalog::period($s, 'today');
        [$w, $p] = $u->salesWhere('s');
        $r = DB::one("SELECT COALESCE(SUM(i.base_quantity),0) q, COUNT(DISTINCT i.product_id) refs, COUNT(DISTINCT s.id) n FROM sale_items i JOIN sales s ON s.id = i.sale_id
            WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w", array_merge(self::between($per), $p));
        if ((float) $r['q'] <= 0) {
            return ['empty' => true, 'sources' => ['sales', 'sale_items'], 'period' => $per['label'], 'variants' => ["Aucun article vendu {$per['label']}."]];
        }
        $own = $u->seesAllSales() ? '' : ' (vos ventes)';
        return ['facts' => [(float) $r['q'], (int) $r['refs'], (int) $r['n']], 'sources' => ['sales', 'sale_items'], 'period' => $per['label'],
            'variants' => [Catalog::n((float) $r['q']) . ' articles ont été vendus ' . $per['label'] . $own . ', sur ' . (int) $r['refs'] . ' références différentes, en ' . (int) $r['n'] . ' ventes.',
                ucfirst($per['label']) . $own . ' : ' . Catalog::n((float) $r['q']) . ' unités vendues (' . (int) $r['refs'] . ' articles différents, ' . (int) $r['n'] . ' tickets).'],
            'followups' => ['Et hier ?', 'Les 5 meilleurs ?']];
    }

    // ------------------------------------------------------------------ tiers

    public static function customerInfo(UserContext $u, array $s): array
    {
        $c = $s['customer'] ?? null;
        if (!$c) {
            return ['clarify' => 'Quel client cherchez-vous ? Donnez son nom ou son numéro de téléphone.'];
        }
        if (!empty($c['ambiguous'])) {
            return ['clarify' => 'Plusieurs clients correspondent : ' . implode(', ', $c['ambiguous']) . '. Lequel ?'];
        }
        $r = DB::one('SELECT id, name, phone, balance, credit_limit, loyalty_points FROM customers WHERE id = ?', [$c['id']]);
        [$w, $p] = $u->salesWhere('s');
        $last = DB::one("SELECT s.number, s.created_at, s.total FROM sales s WHERE s.customer_id = ? AND s.status <> 'cancelled' $w ORDER BY s.id DESC LIMIT 1", array_merge([$r['id']], $p));
        $facts = [(float) $r['balance'], (float) $r['credit_limit']];
        $txt = $r['name'] . ($r['phone'] ? ' (' . $r['phone'] . ')' : '') . ((float) $r['balance'] > 0 ? ' doit ' . Catalog::money((float) $r['balance']) : ' n\'a aucune dette');
        if ((float) $r['credit_limit'] > 0) {
            $txt .= ', plafond de crédit ' . Catalog::money((float) $r['credit_limit']);
        }
        if ($last) {
            $txt .= '. Dernier achat : ' . $last['number'] . ' le ' . fdate($last['created_at']) . ' (' . Catalog::money((float) $last['total']) . ')';
            $facts[] = (float) $last['total'];
        }
        return ['facts' => $facts, 'sources' => ['customers', 'sales'], 'link' => ['Fiche du client', '/customers/' . $r['id']], 'variants' => [$txt . '.'],
            'cards' => [['Dette', Catalog::money((float) $r['balance'])], ['Plafond', Catalog::money((float) $r['credit_limit'])]], 'followups' => ['Rapport du client ' . $r['name'] . ' en PDF']];
    }

    public static function supplierInfo(UserContext $u, array $s): array
    {
        $c = $s['supplier'] ?? null;
        if (!$c) {
            return ['clarify' => 'Quel fournisseur cherchez-vous ? Donnez son nom.'];
        }
        $r = DB::one('SELECT id, name, phone, contact_name, balance FROM suppliers WHERE id = ?', [$c['id']]);
        $last = DB::one('SELECT number, created_at, total FROM receptions WHERE supplier_id = ? ORDER BY id DESC LIMIT 1', [$r['id']]);
        $facts = [(float) $r['balance']];
        $txt = $r['name'] . ($r['phone'] ? ' (' . $r['phone'] . ')' : '') . ((float) $r['balance'] > 0 ? ' : vous lui devez ' . Catalog::money((float) $r['balance']) : ' : aucune dette en cours');
        if ($last) {
            $txt .= '. Dernière réception : ' . $last['number'] . ' le ' . fdate($last['created_at']) . ' (' . Catalog::money((float) $last['total']) . ')';
            $facts[] = (float) $last['total'];
        }
        return ['facts' => $facts, 'sources' => ['suppliers', 'receptions'], 'link' => ['Fournisseurs', '/suppliers'], 'variants' => [$txt . '.']];
    }

    public static function customerPayments(UserContext $u, array $s): array
    {
        $per = Catalog::period($s, 'month');
        $rows = DB::all("SELECT m.created_at, m.amount, m.method, m.reason FROM cash_movements m WHERE m.type = 'customer_payment' AND m.created_at BETWEEN ? AND ? ORDER BY m.id DESC LIMIT 50", self::between($per));
        $t = array_sum(array_map(fn($r) => (float) $r['amount'], $rows));
        if (!$rows) {
            return ['empty' => true, 'sources' => ['cash_movements'], 'period' => $per['label'], 'variants' => ["Aucun règlement de dette client {$per['label']}."]];
        }
        return ['facts' => array_merge([$t, count($rows)], array_map(fn($r) => (float) $r['amount'], $rows)), 'sources' => ['cash_movements'], 'period' => $per['label'],
            'variants' => [ucfirst($per['label']) . ', les clients ont réglé ' . Catalog::money($t) . ' de dettes en ' . count($rows) . ' paiement(s).'],
            'table' => ['headers' => ['Date', 'Montant', 'Mode', 'Détail'], 'rows' => array_map(fn($r) => [fdate($r['created_at']), Catalog::money((float) $r['amount']), method_label((string) $r['method']), (string) $r['reason']], $rows), 'more' => 0]];
    }

    // ------------------------------------------------------------------ finances

    public static function expensesByCategory(UserContext $u, array $s): array
    {
        $per = Catalog::period($s, 'month');
        [$w, $p] = $u->storeWhere('e.store_id', true);
        $rows = DB::all("SELECT COALESCE(c.name, 'Sans catégorie') k, COUNT(*) n, SUM(e.amount) t FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id
            WHERE e.type = 'expense' AND e.status <> 'reversed' AND e.expense_date BETWEEN ? AND ? $w GROUP BY k ORDER BY t DESC LIMIT 30", array_merge([$per['from'], $per['to']], $p));
        $t = array_sum(array_map(fn($r) => (float) $r['t'], $rows));
        if (!$rows) {
            return ['empty' => true, 'sources' => ['expenses'], 'period' => $per['label'], 'variants' => ["Aucune dépense enregistrée {$per['label']}."]];
        }
        return ['facts' => array_merge([$t], array_map(fn($r) => (float) $r['t'], $rows), array_map(fn($r) => (int) $r['n'], $rows)), 'sources' => ['expenses'], 'period' => $per['label'], 'link' => ['Dépenses & recettes', '/expenses'],
            'variants' => ['Dépenses ' . $per['label'] . ' : ' . Catalog::money($t) . ' au total ; premier poste : ' . $rows[0]['k'] . ' (' . Catalog::money((float) $rows[0]['t']) . ').'],
            'table' => ['headers' => ['Catégorie', 'Nombre', 'Montant', 'Part'], 'rows' => array_map(fn($r) => [$r['k'], (int) $r['n'], Catalog::money((float) $r['t']), $t > 0 ? Catalog::n(round((float) $r['t'] / $t * 100, 1)) . ' %' : '—'], $rows), 'more' => 0],
            'chart' => ['type' => 'bar', 'title' => 'Dépenses par catégorie', 'labels' => array_column($rows, 'k'), 'values' => array_map(fn($r) => (float) $r['t'], $rows)]];
    }

    public static function marginRate(UserContext $u, array $s): array
    {
        $per = Catalog::period($s, 'month');
        $x = self::summary($u, $per);
        $f = ['net' => (float) $x['net'], 'cost' => (float) $x['cogs'], 'margin' => (float) $x['margin']];
        if ($f['net'] <= 0) {
            return ['empty' => true, 'sources' => ['sales', 'sale_items'], 'period' => $per['label'], 'variants' => ["Pas de vente {$per['label']} : la marge ne peut pas être calculée."]];
        }
        $rate = round($f['margin'] / $f['net'] * 100, 1);
        return ['facts' => [$f['net'], $f['cost'], $f['margin'], $rate], 'sources' => ['sales', 'sale_items'], 'period' => $per['label'],
            'cards' => [['Ventes nettes', Catalog::money($f['net'])], ['Coût des ventes', Catalog::money($f['cost'])], ['Marge brute', Catalog::money($f['margin'])], ['Taux de marge', Catalog::n($rate) . ' %']],
            'variants' => ['Votre marge brute ' . $per['label'] . ' est de ' . Catalog::money($f['margin']) . ', soit ' . Catalog::n($rate) . ' % des ventes nettes (' . Catalog::money($f['net']) . ').',
                ucfirst($per['label']) . ' : ' . Catalog::money($f['net']) . ' de ventes nettes pour ' . Catalog::money($f['cost']) . ' de coût, donc ' . Catalog::money($f['margin']) . ' de marge (' . Catalog::n($rate) . ' %).'],
            'note' => 'Marge calculée au coût moyen pondéré, avant dépenses.'];
    }

    // ------------------------------------------------------------------ caisse

    private static function sessionsWhere(UserContext $u, string $a = 'cs'): array
    {
        if ($u->can('cash.view_all')) {
            return $u->global ? ['', []] : [" AND $a.register_id IN (SELECT id FROM cash_registers WHERE store_id = ?)", [$u->storeId]];
        }
        return [" AND $a.user_id = ?", [$u->id()]];
    }

    public static function cashSessionsOpen(UserContext $u, array $s): array
    {
        [$w, $p] = self::sessionsWhere($u);
        $rows = DB::all("SELECT cs.id, r.name, us.full_name, cs.opened_at, cs.opening_amount FROM cash_sessions cs JOIN cash_registers r ON r.id = cs.register_id JOIN users us ON us.id = cs.user_id WHERE cs.status = 'open' $w ORDER BY cs.opened_at", $p);
        if (!$rows) {
            return ['empty' => true, 'sources' => ['cash_sessions'], 'variants' => ['Aucune caisse n\'est ouverte en ce moment' . ($u->can('cash.view_all') ? '' : ' à votre nom') . '.']];
        }
        return ['facts' => array_merge([count($rows)], array_map(fn($r) => (float) $r['opening_amount'], $rows)), 'sources' => ['cash_sessions'], 'link' => ['Sessions de caisse', '/cash/sessions'],
            'variants' => [count($rows) . ' caisse(s) ouverte(s) : ' . implode(' ; ', array_map(fn($r) => $r['name'] . ' par ' . $r['full_name'] . ' depuis le ' . fdate($r['opened_at']), array_slice($rows, 0, 5))) . '.'],
            'table' => ['headers' => ['Caisse', 'Caissier', 'Ouverte le', 'Fond'], 'rows' => array_map(fn($r) => [$r['name'], $r['full_name'], fdate($r['opened_at']), Catalog::money((float) $r['opening_amount'])], $rows), 'more' => 0]];
    }

    /** Diagnostic de caisse : ce qui peut expliquer un problème, à partir des faits enregistrés. */
    public static function cashDiagnostic(UserContext $u, array $s): array
    {
        [$w, $p] = self::sessionsWhere($u);
        $points = [];
        $facts = [];
        $open = DB::all("SELECT cs.id, r.name, us.full_name, cs.opened_at, cs.opening_amount FROM cash_sessions cs JOIN cash_registers r ON r.id = cs.register_id JOIN users us ON us.id = cs.user_id WHERE cs.status = 'open' $w ORDER BY cs.opened_at", $p);
        if (!$open) {
            $points[] = ['Caisse fermée', 'Aucune caisse n\'est ouverte' . ($u->can('cash.view_all') ? '' : ' à votre nom') . ' : la vente est impossible tant que la caisse n\'est pas ouverte (Ma caisse → Ouvrir).', 'info'];
        }
        foreach ($open as $o) {
            $hours = (int) floor((time() - strtotime((string) $o['opened_at'])) / 3600);
            $exp = \App\Services\CashService::expectedCash((int) $o['id']);
            $facts[] = $exp;
            $facts[] = $hours;
            $points[] = [$o['name'], 'Ouverte par ' . $o['full_name'] . ' depuis ' . $hours . ' h ; espèces attendues dans le tiroir : ' . Catalog::money($exp) . '.', 'info'];
            if ($hours >= 24) {
                $points[] = ['Caisse ouverte depuis plus d\'un jour', $o['name'] . ' n\'a pas été clôturée depuis le ' . fdate($o['opened_at']) . ' : une clôture comptée chaque jour permet de repérer un écart tout de suite.', 'warn'];
            }
            if ($exp < 0) {
                $points[] = ['Espèces théoriques négatives', 'Plus d\'argent est sorti de ' . $o['name'] . ' qu\'il n\'en est entré : une entrée n\'a probablement pas été saisie.', 'warn'];
            }
            $outs = DB::one("SELECT COUNT(*) n, COALESCE(SUM(ABS(amount)),0) t FROM cash_movements WHERE cash_session_id = ? AND type IN ('cash_out','expense','refund','supplier_payment') AND method = 'cash'", [$o['id']]);
            if ((int) $outs['n'] > 0) {
                $facts[] = (int) $outs['n'];
                $facts[] = (float) $outs['t'];
                $points[] = ['Sorties d\'espèces de la session', (int) $outs['n'] . ' sortie(s) pour ' . Catalog::money((float) $outs['t']) . ' : vérifiez qu\'aucune n\'a été oubliée ou saisie deux fois.', 'info'];
            }
            $cancel = (int) DB::value("SELECT COUNT(*) FROM cash_movements WHERE cash_session_id = ? AND type = 'sale_cancel'", [$o['id']]);
            if ($cancel > 0) {
                $facts[] = $cancel;
                $points[] = ['Ventes annulées', $cancel . ' annulation(s) pendant cette session : l\'argent a-t-il bien été rendu au client ?', 'info'];
            }
        }
        $closed = DB::all("SELECT cs.closed_at, r.name, us.full_name, cs.difference FROM cash_sessions cs JOIN cash_registers r ON r.id = cs.register_id JOIN users us ON us.id = cs.user_id
            WHERE cs.status = 'closed' AND cs.counted_amount IS NOT NULL AND cs.closed_at >= ? $w ORDER BY cs.closed_at DESC LIMIT 10", array_merge([date('Y-m-d H:i:s', strtotime('-30 days'))], $p));
        $bad = array_values(array_filter($closed, fn($c) => abs((float) $c['difference']) >= 1));
        foreach (array_slice($bad, 0, 3) as $c) {
            $facts[] = abs((float) $c['difference']);
            $points[] = [((float) $c['difference'] < 0 ? 'Manque' : 'Excédent') . ' à la clôture', $c['name'] . ', ' . fdate($c['closed_at']) . ' (' . $c['full_name'] . ') : écart de ' . Catalog::money((float) $c['difference']) . ' entre le théorique et le compté.', 'warn'];
        }
        $facts[] = count($closed);
        $facts[] = count($bad);
        $warn = count(array_filter($points, fn($x) => $x[2] === 'warn'));
        $head = $warn ? "J'ai relevé $warn point(s) à vérifier sur la caisse." : ($open ? 'Je ne vois rien d\'anormal dans les enregistrements de la caisse.' : 'La caisse est fermée ; je ne vois pas d\'écart récent.');
        if ($closed && !$bad) {
            $head .= ' Les ' . count($closed) . ' dernières clôtures tombent juste.';
        }
        return ['facts' => $facts, 'sources' => ['cash_sessions', 'cash_movements'], 'link' => ['Ma caisse', '/cash'],
            'variants' => [$head . ' Si le problème est un écart au moment de compter, les causes habituelles sont : une sortie ou une dépense non saisie, une erreur de monnaie rendue, une vente encaissée sans être enregistrée, ou un paiement saisi en espèces alors qu\'il était en mobile money.'],
            'table' => ['headers' => ['Point', 'Constat'], 'rows' => array_map(fn($x) => [$x[0], $x[1]], $points), 'more' => 0],
            'note' => 'Ces constats viennent des enregistrements ; ils n\'accusent personne. Un écart se vérifie en recomptant et en relisant les mouvements de la session.',
            'followups' => ['Écarts de caisse du mois', 'Sorties de caisse aujourd\'hui']];
    }

    // ------------------------------------------------------------------ alertes et conseils

    /** Constats chiffrés du périmètre de l'utilisateur, selon ses droits. @return array[] [code, titre, constat, conseil, faits[]] */
    public static function findings(UserContext $u): array
    {
        $out = [];
        $stock = $u->can('stock.view');
        if ($stock) {
            [$w, $p] = $u->warehouseWhere('l.warehouse_id');
            $sub = "(SELECT l.product_id, SUM(l.quantity) q FROM stock_levels l WHERE 1 = 1 $w GROUP BY l.product_id)";
            $rupt = DB::all("SELECT p.name FROM products p LEFT JOIN $sub x ON x.product_id = p.id WHERE p.status = 'active' AND p.track_stock = 1 AND p.sellable = 1 AND COALESCE(x.q,0) <= 0 ORDER BY p.name LIMIT 200", $p);
            if ($rupt) {
                $out[] = ['rupture', 'Ruptures', count($rupt) . ' article(s) en rupture, dont ' . implode(', ', array_slice(array_column($rupt, 'name'), 0, 3)) . '.',
                    'Recommandez d\'abord ceux qui se vendaient le mieux : « que dois-je commander ? » donne les quantités.', [count($rupt)]];
            }
            $low = (int) DB::value("SELECT COUNT(*) FROM products p JOIN $sub x ON x.product_id = p.id WHERE p.status = 'active' AND p.track_stock = 1 AND p.min_stock > 0 AND x.q > 0 AND x.q <= p.min_stock", $p);
            if ($low) {
                $out[] = ['low', 'Stock faible', $low . ' article(s) sous leur seuil d\'alerte.', 'Passez commande avant la rupture, en tenant compte du délai du fournisseur.', [$low]];
            }
            $dorm = DB::one("SELECT COUNT(*) n, COALESCE(SUM(x.q * p.cost_price),0) v FROM products p JOIN $sub x ON x.product_id = p.id WHERE p.status = 'active' AND p.track_stock = 1 AND x.q > 0
                AND NOT EXISTS (SELECT 1 FROM sale_items i JOIN sales s ON s.id = i.sale_id WHERE i.product_id = p.id AND s.status <> 'cancelled' AND s.created_at >= ?)", array_merge($p, [date('Y-m-d H:i:s', strtotime('-30 days'))]));
            if ((int) $dorm['n'] > 0) {
                $val = $u->seesCosts() ? ' (' . Catalog::money((float) $dorm['v']) . ' immobilisés)' : '';
                $out[] = ['dormant', 'Stock dormant', (int) $dorm['n'] . ' article(s) en stock sans aucune vente depuis 30 jours' . $val . '.',
                    'Mettez-les en avant, vendez-les en lot ou en promotion, et ne les recommandez pas.', $u->seesCosts() ? [(int) $dorm['n'], (float) $dorm['v']] : [(int) $dorm['n']]];
            }
            if (DB::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_lots'")) {
                $exp = (int) DB::value('SELECT COUNT(*) FROM stock_lots WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()');
                if ($exp) {
                    $out[] = ['expired', 'Lots périmés', $exp . ' lot(s) périmé(s) encore en stock.', 'Retirez-les de la vente et enregistrez la perte (Ajustement / pertes) pour que le stock reste juste.', [$exp]];
                }
            }
        }
        if ($u->can('customers.view')) {
            $d = DB::one('SELECT COUNT(*) n, COALESCE(SUM(balance),0) t FROM customers WHERE active = 1 AND balance > 0');
            if ((int) $d['n'] > 0) {
                $out[] = ['debts', 'Créances clients', (int) $d['n'] . ' client(s) vous doivent ' . Catalog::money((float) $d['t']) . '.', 'Relancez les plus anciens et fixez un plafond de crédit par client.', [(int) $d['n'], (float) $d['t']]];
            }
        }
        if ($u->can('reports.view') && $u->seesAllSales()) {
            $cur = Catalog::period([], 'week');
            $prev = Catalog::previous($cur);
            $a = Catalog::salesFacts($u, [], $cur);
            $b = Catalog::salesFacts($u, [], $prev);
            if ($b['t'] > 0 && $a['t'] < $b['t'] * 0.85) {
                $pct = round(($a['t'] - $b['t']) / $b['t'] * 100, 1);
                $out[] = ['decline', 'Ventes en baisse', 'Ventes de ' . Catalog::money($a['t']) . ' cette semaine contre ' . Catalog::money($b['t']) . ' la semaine dernière (' . Catalog::n($pct) . ' %).',
                    'Demandez « pourquoi mes ventes ont-elles diminué ? » pour voir les articles et les jours concernés.', [$a['t'], $b['t'], $pct, abs($pct)]];
            }
            if ($u->seesCosts()) {
                $x = self::summary($u, Catalog::period([], 'month'));
                $m = ['net' => (float) $x['net'], 'margin' => (float) $x['margin']];
                if ($m['net'] > 0) {
                    $rate = round($m['margin'] / $m['net'] * 100, 1);
                    if ($rate < 15) {
                        $out[] = ['margin', 'Marge faible', 'Marge brute de ' . Catalog::n($rate) . ' % ce mois-ci (' . Catalog::money($m['margin']) . ' sur ' . Catalog::money($m['net']) . ').',
                            'Vérifiez les prix d\'achat saisis, les remises accordées et les articles vendus à perte (« produits les plus rentables »).', [$rate, $m['margin'], $m['net']]];
                    }
                }
            }
        }
        if ($u->can('cash.view_all') || $u->can('cash.open_close')) {
            [$w, $p] = self::sessionsWhere($u);
            $diff = DB::one("SELECT COUNT(*) n, COALESCE(SUM(cs.difference),0) t FROM cash_sessions cs WHERE cs.status = 'closed' AND cs.counted_amount IS NOT NULL AND ABS(cs.difference) >= 1 AND cs.closed_at >= ? $w",
                array_merge([date('Y-m-d H:i:s', strtotime('-30 days'))], $p));
            if ((int) $diff['n'] > 0) {
                $out[] = ['cash', 'Écarts de caisse', (int) $diff['n'] . ' clôture(s) avec écart sur 30 jours, cumul ' . Catalog::money((float) $diff['t']) . '.', 'Comptez la caisse à chaque changement de caissier et saisissez les sorties au moment où elles ont lieu.',
                    [(int) $diff['n'], (float) $diff['t'], abs((float) $diff['t'])]];
            }
        }
        return $out;
    }

    public static function whatsWrong(UserContext $u, array $s): array
    {
        $f = self::findings($u);
        if (!$f) {
            return ['empty' => true, 'sources' => ['products', 'stock_levels', 'sales', 'customers', 'cash_sessions'],
                'variants' => ['Je n\'ai relevé aucune alerte dans les données auxquelles votre rôle donne accès : pas de rupture, pas de dette client, pas d\'écart de caisse récent.']];
        }
        return ['facts' => array_merge([count($f)], ...array_map(fn($x) => $x[4], $f)), 'sources' => ['products', 'stock_levels', 'sales', 'customers', 'cash_sessions'],
            'variants' => ['Voici ce qui demande votre attention : ' . count($f) . ' point(s), tous tirés de vos données.', count($f) . ' point(s) à regarder aujourd\'hui, d\'après vos données :'],
            'table' => ['headers' => ['Sujet', 'Constat'], 'rows' => array_map(fn($x) => [$x[1], $x[2]], $f), 'more' => 0],
            'followups' => ['Tu me conseilles quoi ?', 'Que dois-je commander ?']];
    }

    public static function advice(UserContext $u, array $s): array
    {
        $f = self::findings($u);
        $general = ['Clôturez et comptez la caisse chaque jour.', 'Faites un inventaire tournant, un rayon par semaine.', 'Séparez l\'argent de la boutique de l\'argent personnel.', 'Regardez vos chiffres à heure fixe : « mon point du jour ».'];
        if (!$f) {
            return ['facts' => [], 'sources' => ['products', 'stock_levels', 'sales', 'customers', 'cash_sessions'],
                'variants' => ['Vos données ne montrent pas de point faible évident dans ce que votre rôle me laisse consulter. Voici donc des conseils généraux de bonne gestion (ils ne sont pas calculés sur vos chiffres) : ' . implode(' ', $general)]];
        }
        return ['facts' => array_merge([count($f)], ...array_map(fn($x) => $x[4], $f)), 'sources' => ['products', 'stock_levels', 'sales', 'customers', 'cash_sessions'],
            'variants' => ['J\'ai ' . count($f) . ' recommandation(s) calculée(s) sur vos données. Je les classe par sujet, avec le constat chiffré puis l\'action conseillée.',
                'D\'après vos données, ' . count($f) . ' action(s) peuvent améliorer la situation. Le constat est chiffré, le conseil est en face.'],
            'table' => ['title' => 'Recommandations calculées sur vos données', 'headers' => ['Sujet', 'Constat (vos données)', 'Ce que je conseille'], 'rows' => array_map(fn($x) => [$x[1], $x[2], $x[3]], $f), 'more' => 0],
            'note' => 'Conseils généraux (non calculés sur vos chiffres) : ' . implode(' ', $general),
            'followups' => ['Que dois-je commander ?', 'Articles qui ne se vendent pas', 'Qui me doit de l\'argent ?']];
    }
}
