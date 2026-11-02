<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Services\ReportService;

/**
 * Rapports et analyses de MARWA IA. Chaque rapport vérifie le droit de l'utilisateur et
 * renvoie : titre, sous-titre, colonnes, lignes (pour l'aperçu et le PDF / CSV), chiffres
 * clés, phrase de synthèse et liens utiles. Aucune donnée ne sort du serveur.
 *
 * Les montants des ventes sont TTC, nets des ventes annulées.
 */
final class Reports
{
    /** Rapports disponibles : droit requis et description courte (aide et suggestions). */
    public const KINDS = [
        'client' => ['customers.view', 'Relevé d\'un client'],
        'fournisseur' => ['purchases.view', 'Relevé d\'un fournisseur'],
        'ventes' => ['sales.view', 'Liste des ventes (transactions)'],
        'article' => ['reports.view', 'Ventes et stock d\'un article'],
        'top' => ['reports.view', 'Meilleures ventes'],
        'dormants' => ['reports.view', 'Articles qui ne se vendent pas'],
        'stock' => ['stock.view', 'État du stock'],
        'rupture' => ['stock.view', 'Articles sous le seuil'],
        'peremption' => ['stock.view', 'Produits qui périment bientôt'],
        'creances' => ['reports.financial', 'Créances clients et dettes fournisseurs'],
        'meilleurs_clients' => ['reports.view', 'Meilleurs clients'],
        'benefice' => ['reports.financial', 'Chiffre d\'affaires, marge et bénéfice'],
        'depenses' => ['expenses.manage', 'Dépenses et recettes'],
        'caisse' => ['cash.view_all', 'Clôtures et écarts de caisse'],
        'vendeurs' => ['reports.view', 'Ventes par vendeur'],
        'heures' => ['reports.view', 'Affluence par heure'],
        'paiements' => ['reports.view', 'Ventes par mode de paiement'],
        'categories' => ['reports.view', 'Ventes par catégorie'],
        'bilan' => ['reports.view', 'Bilan et conseils'],
        'commande' => ['stock.view', 'Que commander (réapprovisionnement)'],
        'prevision' => ['reports.view', 'Prévision des ventes du mois'],
        'vente' => ['sales.view', 'Détail d\'une vente'],
    ];

    public const METHODS = ['cash' => 'Espèces', 'card' => 'Carte', 'mobile_money' => 'Mobile money', 'transfer' => 'Virement', 'cheque' => 'Chèque', 'credit' => 'Crédit'];

    private static function guard(string $kind): void
    {
        $perm = self::KINDS[$kind][0] ?? 'reports.view';
        if (!Auth::can($perm)) {
            throw new BusinessException('Votre rôle ne permet pas de consulter ce rapport (' . self::KINDS[$kind][1] . '). Demandez à un responsable.');
        }
    }

    private static function range(?array $p, string $default = 'month'): array
    {
        if ($p) {
            return $p;
        }
        return match ($default) {
            'today' => ['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'label' => 'aujourd\'hui'],
            'all' => ['from' => '2000-01-01', 'to' => date('Y-m-d'), 'label' => 'depuis le début'],
            'year' => ['from' => date('Y-01-01'), 'to' => date('Y-m-d'), 'label' => 'cette année'],
            default => ['from' => date('Y-m-01'), 'to' => date('Y-m-d'), 'label' => 'ce mois-ci'],
        };
    }

    private static function between(array $p): array
    {
        return [$p['from'] . ' 00:00:00', $p['to'] . ' 23:59:59'];
    }

    private static function sub(array $p): string
    {
        return $p['from'] === '2000-01-01' ? 'Toute la période enregistrée' : ($p['from'] === $p['to'] ? 'Journée du ' . date('d/m/Y', strtotime($p['from'])) : 'Du ' . date('d/m/Y', strtotime($p['from'])) . ' au ' . date('d/m/Y', strtotime($p['to'])));
    }

    /** Nombre à la française : 21,7 */
    private static function fr(float $v, int $dec = 1): string
    {
        return number_format($v, $dec, ',', ' ');
    }

    private static function pct(float $now, float $before): ?float
    {
        return $before > 0.009 ? round(($now - $before) / $before * 100, 1) : null;
    }

    private static function trend(?float $pct): string
    {
        if ($pct === null) {
            return '';
        }
        return ($pct >= 0 ? ' (<b class="up">+' : ' (<b class="down">') . str_replace('.', ',', (string) $pct) . ' %</b> par rapport à la période précédente)';
    }

    private static function out(string $title, array $p, array $headers, array $rows, array $extra = []): array
    {
        return $extra + ['title' => $title, 'subtitle' => self::sub($p), 'headers' => $headers, 'rows' => $rows, 'period' => $p];
    }

    /** Ventes visibles : toutes avec « sales.view_all », sinon seulement les siennes. */
    private static function ownSales(): array
    {
        return Auth::can('sales.view_all') ? ['', []] : [' AND s.user_id = ?', [Auth::id()]];
    }

    // ================================================================== rapports
    public static function client(int $id, ?array $p): array
    {
        self::guard('client');
        $c = DB::one('SELECT * FROM customers WHERE id = ?', [$id]) ?? throw new BusinessException('Client introuvable.');
        $p = self::range($p, 'all');
        [$a, $b] = self::between($p);
        $sales = DB::all("SELECT s.id, s.number, s.created_at, s.total, s.paid_total, s.credit_amount, s.status, (SELECT GROUP_CONCAT(DISTINCT sp.method) FROM sale_payments sp WHERE sp.sale_id = s.id) AS methods
                FROM sales s WHERE s.customer_id = ? AND s.created_at BETWEEN ? AND ? ORDER BY s.created_at DESC", [$id, $a, $b]);
        $ledger = DB::all('SELECT * FROM customer_transactions WHERE customer_id = ? AND created_at BETWEEN ? AND ? ORDER BY id DESC', [$id, $a, $b]);
        $types = ['credit_sale' => 'Vente à crédit', 'payment' => 'Règlement', 'deposit' => 'Acompte', 'refund' => 'Avoir', 'adjustment' => 'Ajustement'];
        $rows = [];
        foreach ($sales as $s) {
            $rows[] = [substr($s['created_at'], 0, 16), 'Vente ' . $s['number'], self::methods((string) $s['methods']), (float) $s['total'], (float) $s['credit_amount'], $s['status'] === 'cancelled' ? 'Annulée' : 'Validée'];
        }
        foreach ($ledger as $t) {
            if ($t['type'] === 'credit_sale') {
                continue; // déjà dans la ligne de vente
            }
            $rows[] = [substr($t['created_at'], 0, 16), $types[$t['type']] ?? $t['type'], self::METHODS[$t['method'] ?? ''] ?? (string) $t['method'], -(float) $t['amount'], 0, 'Solde après : ' . money($t['balance_after'], false)];
        }
        usort($rows, fn($x, $y) => strcmp($y[0], $x[0]));
        $valid = array_filter($sales, fn($s) => $s['status'] !== 'cancelled');
        $total = array_sum(array_column($valid, 'total'));
        $paid = -array_sum(array_map(fn($t) => in_array($t['type'], ['payment', 'deposit'], true) ? (float) $t['amount'] : 0, $ledger));
        $last = $valid ? max(array_column($valid, 'created_at')) : null;
        $txt = '<b>' . e($c['name']) . '</b>' . ($c['phone'] ? ' (' . e($c['phone']) . ')' : '') . ' : ' . count($valid) . ' achat(s) pour <b>' . money($total) . '</b> ' . e($p['label']) . '.';
        $txt .= (float) $c['balance'] > 0.009 ? ' Il doit actuellement <b class="down">' . money($c['balance']) . '</b>' . ((float) $c['credit_limit'] > 0 ? ' sur un plafond de ' . money($c['credit_limit']) : '') . '.' : ' Il ne doit rien.';
        if ($last) {
            $txt .= ' Dernier achat le ' . fdate($last) . '.';
        }
        return self::out('Relevé du client ' . $c['name'], $p, ['Date', 'Opération', 'Paiement', 'Montant', 'À crédit', 'Détail'], $rows, [
            'text' => $txt, 'file' => 'releve_client_' . $id,
            'kpis' => [['Achats', money($total)], ['Nombre d\'achats', (string) count($valid)], ['Règlements', money($paid)], ['Solde dû', money($c['balance'])]],
            'footer' => ['TOTAL', '', '', $total, array_sum(array_column($valid, 'credit_amount')), ''],
            'links' => [['Fiche du client', url('/customers/' . $id)]],
        ]);
    }

    private static function methods(string $csv): string
    {
        return implode(' + ', array_map(fn($m) => self::METHODS[$m] ?? $m, array_filter(explode(',', $csv))));
    }

    public static function supplier(int $id, ?array $p): array
    {
        self::guard('fournisseur');
        $f = DB::one('SELECT * FROM suppliers WHERE id = ?', [$id]) ?? throw new BusinessException('Fournisseur introuvable.');
        $p = self::range($p, 'all');
        [$a, $b] = self::between($p);
        $types = ['invoice' => 'Facture / réception', 'payment' => 'Paiement', 'return' => 'Retour', 'adjustment' => 'Ajustement'];
        $tx = DB::all('SELECT * FROM supplier_transactions WHERE supplier_id = ? AND created_at BETWEEN ? AND ? ORDER BY id DESC', [$id, $a, $b]);
        $rows = array_map(fn($t) => [substr($t['created_at'], 0, 16), $types[$t['type']] ?? $t['type'], (string) ($t['note'] ?? ''), (float) $t['amount'], (float) $t['balance_after']], $tx);
        $bought = array_sum(array_map(fn($t) => $t['type'] === 'invoice' ? (float) $t['amount'] : 0, $tx));
        $paid = -array_sum(array_map(fn($t) => $t['type'] === 'payment' ? (float) $t['amount'] : 0, $tx));
        $txt = '<b>' . e($f['name']) . '</b> : achats de <b>' . money($bought) . '</b> et paiements de ' . money($paid) . ' ' . e($p['label']) . '. ';
        $txt .= (float) $f['balance'] > 0.009 ? 'Vous lui devez <b class="down">' . money($f['balance']) . '</b>.' : 'Vous ne lui devez rien.';
        return self::out('Relevé du fournisseur ' . $f['name'], $p, ['Date', 'Opération', 'Note', 'Montant', 'Solde après'], $rows, [
            'text' => $txt, 'file' => 'releve_fournisseur_' . $id,
            'kpis' => [['Achats', money($bought)], ['Paiements', money($paid)], ['Solde dû', money($f['balance'])]],
            'links' => [['Fiche du fournisseur', url('/suppliers/' . $id)]],
        ]);
    }

    /** Ventes (transactions), filtrables par client, vendeur, mode de paiement. */
    public static function sales(?array $p, array $f = []): array
    {
        self::guard('ventes');
        $p = self::range($p, 'today');
        [$a, $b] = self::between($p);
        [$own, $op] = self::ownSales();
        $w = 's.created_at BETWEEN ? AND ?' . $own;
        $prm = array_merge([$a, $b], $op);
        if (!empty($f['user_id'])) {
            $w .= ' AND s.user_id = ?';
            $prm[] = (int) $f['user_id'];
        }
        if (!empty($f['customer_id'])) {
            $w .= ' AND s.customer_id = ?';
            $prm[] = (int) $f['customer_id'];
        }
        if (!empty($f['method'])) {
            $w .= ' AND EXISTS (SELECT 1 FROM sale_payments x WHERE x.sale_id = s.id AND x.method = ?)';
            $prm[] = $f['method'];
        }
        if (!empty($f['credit'])) {
            $w .= ' AND s.credit_amount > 0';
        }
        $rows = DB::all("SELECT s.id, s.number, s.created_at, s.total, s.credit_amount, s.status, u.full_name, c.name AS customer,
                (SELECT GROUP_CONCAT(DISTINCT sp.method) FROM sale_payments sp WHERE sp.sale_id = s.id) AS methods
                FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id WHERE $w ORDER BY s.created_at DESC LIMIT 5000", $prm);
        $valid = array_filter($rows, fn($r) => $r['status'] !== 'cancelled');
        $total = array_sum(array_column($valid, 'total'));
        $n = count($valid);
        $out = array_map(fn($r) => [$r['number'], substr($r['created_at'], 0, 16), $r['full_name'], (string) ($r['customer'] ?? 'Comptoir'), self::methods((string) $r['methods']), (float) $r['total'], $r['status'] === 'cancelled' ? 'Annulée' : ((float) $r['credit_amount'] > 0 ? 'Crédit' : 'Payée')], $rows);
        $what = trim(($f['label'] ?? '') . '');
        $txt = '<b>' . $n . ' vente(s)</b>' . ($what !== '' ? ' ' . e($what) : '') . ' ' . e($p['label']) . ' pour un total de <b>' . money($total) . '</b>' . ($n ? ', soit un panier moyen de ' . money($total / $n) : '') . '.';
        $cancel = count($rows) - $n;
        if ($cancel) {
            $txt .= ' ' . $cancel . ' vente(s) annulée(s) non comptée(s).';
        }
        if (!Auth::can('sales.view_all')) {
            $txt .= ' <i>Seules vos propres ventes sont comptées (droit « toutes les ventes » absent).</i>';
        }
        return self::out('Ventes' . ($what !== '' ? ' ' . $what : ''), $p, ['N°', 'Date', 'Vendeur', 'Client', 'Paiement', 'Total', 'Statut'], $out, [
            'text' => $txt, 'file' => 'ventes_' . $p['from'] . '_' . $p['to'], 'landscape' => true,
            'kpis' => [['Ventes', (string) $n], ['Chiffre d\'affaires', money($total)], ['Panier moyen', money($n ? $total / $n : 0)]],
            'footer' => ['TOTAL', '', '', '', '', $total, ''],
            'links' => [['Historique des ventes', url('/sales', ['from' => $p['from'], 'to' => $p['to']])]],
        ]);
    }

    public static function product(int $id, ?array $p): array
    {
        self::guard('article');
        $pr = DB::one('SELECT p.*, c.name AS category FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?', [$id]) ?? throw new BusinessException('Article introuvable.');
        $p = self::range($p, 'month');
        [$a, $b] = self::between($p);
        $lines = DB::all("SELECT s.number, s.created_at, si.product_name, si.quantity, si.base_quantity, si.line_total, si.unit_cost, si.returned_qty, pk.name AS pack
                FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN product_packagings pk ON pk.id = si.packaging_id
                WHERE si.product_id = ? AND s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? ORDER BY s.created_at DESC", [$id, $a, $b]);
        $qty = array_sum(array_map(fn($l) => (float) $l['base_quantity'] * (1 - (float) $l['returned_qty'] / max(0.001, (float) $l['quantity'])), $lines));
        $rev = array_sum(array_map(fn($l) => (float) $l['line_total'] * (1 - (float) $l['returned_qty'] / max(0.001, (float) $l['quantity'])), $lines));
        $cost = array_sum(array_map(fn($l) => (float) $l['unit_cost'] * (float) $l['base_quantity'] * (1 - (float) $l['returned_qty'] / max(0.001, (float) $l['quantity'])), $lines));
        $stock = (float) DB::value('SELECT COALESCE(SUM(quantity),0) FROM stock_levels WHERE product_id = ?', [$id]);
        // Rythme de vente : 30 derniers jours, quelle que soit la période demandée
        $perDay = (float) DB::value("SELECT COALESCE(SUM(si.base_quantity - si.returned_qty * si.base_quantity / si.quantity),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                WHERE si.product_id = ? AND s.status <> 'cancelled' AND s.created_at >= NOW() - INTERVAL 30 DAY", [$id]) / 30;
        $txt = '<b>' . e($pr['name']) . '</b> : ' . self::q($qty) . ' vendu(s) ' . e($p['label']) . ' pour <b>' . money($rev) . '</b>, marge ' . money($rev - $cost) . ($rev > 0 ? ' (' . round(($rev - $cost) / $rev * 100) . ' %)' : '') . '. ';
        $txt .= 'Stock actuel : <b>' . self::q($stock) . '</b>' . ((float) $pr['min_stock'] > 0 ? ' (seuil ' . self::q((float) $pr['min_stock']) . ')' : '') . '. Prix de vente ' . money($pr['sale_price']) . ', coût moyen ' . money($pr['cost_price']) . '.';
        if ($perDay > 0 && $pr['track_stock']) {
            $txt .= ' Au rythme des 30 derniers jours (' . self::q(round($perDay, 1)) . ' par jour), le stock couvre environ <b>' . (int) floor($stock / $perDay) . ' jour(s)</b>.';
        }
        $rows = array_map(fn($l) => [$l['number'], substr($l['created_at'], 0, 16), $l['pack'] ? $l['pack'] : 'Unité', (float) $l['quantity'], (float) $l['base_quantity'], (float) $l['line_total']], $lines);
        return self::out('Article ' . $pr['name'], $p, ['Vente', 'Date', 'Conditionnement', 'Qté vendue', 'Unités', 'Montant'], $rows, [
            'text' => $txt, 'file' => 'article_' . $id,
            'kpis' => [['Unités vendues', self::q($qty)], ['Chiffre d\'affaires', money($rev)], ['Marge', money($rev - $cost)], ['Stock', self::q($stock)]],
            'footer' => ['TOTAL', '', '', '', $qty, $rev],
            'links' => [['Fiche de l\'article', url('/products/' . $id)], ['Mouvements de stock', url('/stock/movements', ['product' => $id])]],
        ]);
    }

    private static function q(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }

    public static function top(?array $p, string $order = 'revenue', int $limit = 20): array
    {
        self::guard('top');
        $p = self::range($p, 'month');
        $rows = ReportService::topProducts($p['from'], $p['to'], null, $limit, $order);
        $out = array_map(fn($r, $i) => [$i + 1, $r['name'], (string) ($r['category'] ?? ''), (float) $r['qty'], (float) $r['revenue'], (float) $r['margin']], $rows, array_keys($rows));
        $txt = $rows ? 'L\'article le plus vendu ' . e($p['label']) . ' est <b>' . e($rows[0]['name']) . '</b> (' . self::q((float) $rows[0]['qty']) . ' unités, ' . money($rows[0]['revenue']) . ').' : 'Aucune vente ' . e($p['label']) . '.';
        if (count($rows) >= 3) {
            $tot = (float) DB::value("SELECT COALESCE(SUM(total),0) FROM sales WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?", self::between($p));
            $top3 = array_sum(array_map(fn($r) => (float) $r['revenue'], array_slice($rows, 0, 3)));
            if ($tot > 0) {
                $txt .= ' Les 3 premiers font <b>' . round($top3 / $tot * 100) . ' %</b> du chiffre d\'affaires : ne les laissez jamais en rupture.';
            }
        }
        return self::out('Meilleures ventes', $p, ['Rang', 'Article', 'Catégorie', 'Quantité', 'Chiffre d\'affaires', 'Marge'], $out, ['text' => $txt, 'file' => 'meilleures_ventes', 'links' => [['Rapport par article', url('/reports/products')]]]);
    }

    public static function slow(int $days = 30): array
    {
        self::guard('dormants');
        $rows = DB::all("SELECT p.id, p.name, p.cost_price, COALESCE(s.q,0) AS qty,
                (SELECT MAX(x.created_at) FROM sale_items y JOIN sales x ON x.id = y.sale_id WHERE y.product_id = p.id AND x.status <> 'cancelled') AS last_sale
                FROM products p LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) s ON s.product_id = p.id
                WHERE p.status = 'active' AND p.track_stock = 1 AND COALESCE(s.q,0) > 0
                AND NOT EXISTS (SELECT 1 FROM sale_items y JOIN sales x ON x.id = y.sale_id WHERE y.product_id = p.id AND x.status <> 'cancelled' AND x.created_at >= NOW() - INTERVAL ? DAY)
                ORDER BY qty * p.cost_price DESC LIMIT 200", [$days]);
        $value = array_sum(array_map(fn($r) => (float) $r['qty'] * (float) $r['cost_price'], $rows));
        $out = array_map(fn($r) => [$r['name'], (float) $r['qty'], (float) $r['qty'] * (float) $r['cost_price'], $r['last_sale'] ? substr($r['last_sale'], 0, 10) : 'jamais'], $rows);
        $txt = $rows ? '<b>' . count($rows) . ' article(s)</b> en stock ne se sont pas vendus depuis ' . $days . ' jours : <b>' . money($value) . '</b> d\'argent immobilisé. Pensez à une promotion, un prix de gros ou un retour fournisseur.' : 'Bonne nouvelle : tous les articles en stock se sont vendus ces ' . $days . ' derniers jours.';
        return self::out('Articles qui ne se vendent pas', ['from' => date('Y-m-d', strtotime("-$days days")), 'to' => date('Y-m-d'), 'label' => "depuis $days jours"], ['Article', 'Stock', 'Valeur (coût)', 'Dernière vente'], $out, ['text' => $txt, 'file' => 'articles_dormants', 'kpis' => [['Articles', (string) count($rows)], ['Valeur immobilisée', money($value)]]]);
    }

    public static function stock(string $mode = 'all'): array
    {
        self::guard($mode === 'low' ? 'rupture' : 'stock');
        $w = $mode === 'low' ? ' AND COALESCE(s.q,0) <= p.min_stock' : '';
        $rows = DB::all("SELECT p.id, p.name, p.reference, p.min_stock, p.cost_price, p.sale_price, c.name AS category, COALESCE(s.q,0) AS qty FROM products p
                LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) s ON s.product_id = p.id LEFT JOIN categories c ON c.id = p.category_id
                WHERE p.status = 'active' AND p.track_stock = 1 $w ORDER BY " . ($mode === 'low' ? 'qty ASC' : 'c.name, p.name') . ' LIMIT 3000');
        $value = array_sum(array_map(fn($r) => max(0, (float) $r['qty']) * (float) $r['cost_price'], $rows));
        $out = array_map(fn($r) => [$r['reference'], $r['name'], (string) ($r['category'] ?? ''), (float) $r['qty'], (float) $r['min_stock'], (float) $r['cost_price'], max(0, (float) $r['qty']) * (float) $r['cost_price']], $rows);
        $zero = count(array_filter($rows, fn($r) => (float) $r['qty'] <= 0));
        $txt = $mode === 'low'
            ? ($rows ? '<b>' . count($rows) . ' article(s)</b> sont au seuil d\'alerte ou en dessous, dont <b>' . $zero . ' en rupture</b>. Demandez « que dois-je commander ? » pour les quantités conseillées.' : 'Aucun article sous le seuil : le stock est bien tenu.')
            : 'Valeur du stock (au coût moyen) : <b>' . money($value) . '</b> pour ' . count($rows) . ' article(s) suivis' . ($zero ? ', dont ' . $zero . ' en rupture' : '') . '.';
        $p = ['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'label' => 'aujourd\'hui'];
        return self::out($mode === 'low' ? 'Articles sous le seuil d\'alerte' : 'État du stock', $p, ['Référence', 'Article', 'Catégorie', 'Stock', 'Seuil', 'Coût moyen', 'Valeur'], $out, [
            'text' => $txt, 'file' => $mode === 'low' ? 'stock_sous_seuil' : 'etat_du_stock', 'landscape' => true,
            'kpis' => [['Articles', (string) count($rows)], ['Valeur', money($value)], ['En rupture', (string) $zero]],
            'footer' => ['TOTAL', '', '', '', '', '', $value],
            'links' => [['État des stocks', url('/stock', $mode === 'low' ? ['filter' => 'low'] : [])]],
        ]);
    }

    public static function expiring(int $days = 90): array
    {
        self::guard('peremption');
        $rows = ReportService::expiring($days, 500);
        $out = array_map(fn($r) => [$r['name'], $r['lot_number'], $r['expiry_date'], (int) $r['days_left'], (float) $r['quantity'], (string) $r['warehouse']], $rows);
        $past = count(array_filter($rows, fn($r) => (int) $r['days_left'] < 0));
        $txt = $rows ? '<b>' . count($rows) . ' lot(s)</b> périment dans les ' . $days . ' prochains jours' . ($past ? ', dont <b class="down">' . $past . ' déjà périmé(s)</b> à retirer de la vente' : '') . '. Faites vendre en priorité les plus proches.' : 'Aucun lot ne périme dans les ' . $days . ' prochains jours.';
        return self::out('Péremptions à ' . $days . ' jours', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime("+$days days")), 'label' => "dans les $days jours"], ['Article', 'Lot', 'Péremption', 'Jours restants', 'Quantité', 'Entrepôt'], $out, ['text' => $txt, 'file' => 'peremptions', 'links' => [['Péremptions', url('/stock/expiry')]]]);
    }

    public static function debts(): array
    {
        self::guard('creances');
        $cust = DB::all("SELECT c.id, c.name, c.phone, c.balance, (SELECT MIN(t.due_date) FROM customer_transactions t WHERE t.customer_id = c.id AND t.type = 'credit_sale') AS due
                FROM customers c WHERE c.balance > 0.009 ORDER BY c.balance DESC");
        $sup = DB::all('SELECT id, name, phone, balance FROM suppliers WHERE balance > 0.009 ORDER BY balance DESC');
        $rows = [];
        foreach ($cust as $c) {
            $rows[] = ['Client', $c['name'], (string) ($c['phone'] ?? ''), (float) $c['balance'], $c['due'] ? ($c['due'] < date('Y-m-d') ? 'Échu depuis le ' . fdate($c['due'], false) : 'Échéance ' . fdate($c['due'], false)) : ''];
        }
        foreach ($sup as $s) {
            $rows[] = ['Fournisseur', $s['name'], (string) ($s['phone'] ?? ''), (float) $s['balance'], ''];
        }
        $rc = array_sum(array_column($cust, 'balance'));
        $rs = array_sum(array_column($sup, 'balance'));
        $late = count(array_filter($cust, fn($c) => $c['due'] && $c['due'] < date('Y-m-d')));
        $txt = 'Vos clients vous doivent <b>' . money($rc) . '</b> (' . count($cust) . ' client(s)' . ($late ? ', dont <b class="down">' . $late . ' en retard</b>' : '') . '). Vous devez <b>' . money($rs) . '</b> à ' . count($sup) . ' fournisseur(s).';
        if ($cust) {
            $txt .= ' Le plus gros débiteur est <b>' . e($cust[0]['name']) . '</b> (' . money($cust[0]['balance']) . ').';
        }
        $p = ['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'label' => 'à ce jour'];
        return self::out('Créances clients et dettes fournisseurs', $p, ['Type', 'Nom', 'Téléphone', 'Montant dû', 'Échéance'], $rows, [
            'text' => $txt, 'file' => 'creances_dettes', 'kpis' => [['Créances clients', money($rc)], ['Dettes fournisseurs', money($rs)], ['Clients en retard', (string) $late]],
            'links' => [['Créances & dettes', url('/reports/debts')]],
        ]);
    }

    public static function topCustomers(?array $p): array
    {
        self::guard('meilleurs_clients');
        $p = self::range($p, 'year');
        $rows = DB::all("SELECT c.id, c.name, c.phone, COUNT(*) AS n, SUM(s.total) AS total, MAX(s.created_at) AS last FROM sales s JOIN customers c ON c.id = s.customer_id
                WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? GROUP BY c.id, c.name, c.phone ORDER BY total DESC LIMIT 100", self::between($p));
        $out = array_map(fn($r, $i) => [$i + 1, $r['name'], (string) ($r['phone'] ?? ''), (int) $r['n'], (float) $r['total'], substr($r['last'], 0, 10)], $rows, array_keys($rows));
        $txt = $rows ? 'Votre meilleur client ' . e($p['label']) . ' est <b>' . e($rows[0]['name']) . '</b> : ' . (int) $rows[0]['n'] . ' achat(s) pour ' . money($rows[0]['total']) . '. Fidélisez vos meilleurs clients : ce sont eux qui font tourner la boutique.' : 'Aucune vente à un client enregistré ' . e($p['label']) . ' (les ventes au comptoir ne sont pas nominatives).';
        return self::out('Meilleurs clients', $p, ['Rang', 'Client', 'Téléphone', 'Achats', 'Total', 'Dernier achat'], $out, ['text' => $txt, 'file' => 'meilleurs_clients']);
    }

    public static function profit(?array $p): array
    {
        self::guard('benefice');
        $p = self::range($p, 'month');
        $s = ReportService::summary($p['from'], $p['to']);
        $prev = self::previous($p);
        $sp = ReportService::summary($prev['from'], $prev['to']);
        $rows = [['Ventes brutes TTC', $s['gross']], ['− Retours / remboursements', -$s['returns']], ['= Chiffre d\'affaires net', $s['net']], ['− Coût des marchandises vendues', -$s['cogs']],
            ['= Marge brute (' . self::fr($s['margin_rate']) . ' %)', $s['margin']], ['− Dépenses', -$s['expenses']], ['+ Recettes diverses', $s['incomes']], ['= Bénéfice estimé', $s['profit']]];
        $txt = e(ucfirst($p['label'])) . ' : chiffre d\'affaires de <b>' . money($s['net']) . '</b>' . self::trend(self::pct($s['net'], $sp['net'])) . ', marge brute de ' . money($s['margin']) . ' (' . self::fr($s['margin_rate']) . ' %), dépenses de ' . money($s['expenses']) . ', soit un <b>bénéfice estimé de ' . money($s['profit']) . '</b>.';
        if ($s['profit'] < 0) {
            $txt .= ' <b class="down">Attention : les dépenses dépassent la marge.</b> Regardez les dépenses par catégorie et les remises accordées.';
        }
        if ($s['discounts'] > 0 && $s['margin'] > 0) {
            $txt .= ' Les remises ont coûté ' . money($s['discounts']) . ' (' . round($s['discounts'] / max(1, $s['margin']) * 100) . ' % de la marge).';
        }
        return self::out('Compte de résultat simplifié', $p, ['Poste', 'Montant'], $rows, [
            'text' => $txt, 'file' => 'benefice',
            'kpis' => [['CA net', money($s['net'])], ['Marge brute', money($s['margin'])], ['Dépenses', money($s['expenses'])], ['Bénéfice', money($s['profit'])]],
            'links' => [['Bénéfices & marges', url('/reports/profit', ['from' => $p['from'], 'to' => $p['to']])]],
        ]);
    }

    /** Période précédente de même durée. */
    public static function previous(array $p): array
    {
        $len = (strtotime($p['to']) - strtotime($p['from'])) / 86400 + 1;
        $to = date('Y-m-d', strtotime($p['from']) - 86400);
        return ['from' => date('Y-m-d', strtotime($to) - (int) ($len - 1) * 86400), 'to' => $to, 'label' => 'la période précédente'];
    }

    public static function expenses(?array $p): array
    {
        self::guard('depenses');
        $p = self::range($p, 'month');
        $rows = DB::all("SELECT e.number, e.expense_date, e.type, e.amount, e.method, e.description, e.status, c.name AS category FROM expenses e JOIN expense_categories c ON c.id = e.category_id
                WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC, e.id DESC", [$p['from'], $p['to']]);
        $valid = array_filter($rows, fn($r) => $r['status'] === 'valid');
        $exp = array_sum(array_map(fn($r) => $r['type'] === 'expense' ? (float) $r['amount'] : 0, $valid));
        $inc = array_sum(array_map(fn($r) => $r['type'] === 'income' ? (float) $r['amount'] : 0, $valid));
        $byCat = [];
        foreach ($valid as $r) {
            if ($r['type'] === 'expense') {
                $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (float) $r['amount'];
            }
        }
        arsort($byCat);
        $out = array_map(fn($r) => [$r['number'], $r['expense_date'], $r['type'] === 'expense' ? 'Dépense' : 'Recette', $r['category'], $r['description'], self::METHODS[$r['method']] ?? $r['method'], ($r['type'] === 'expense' ? -1 : 1) * (float) $r['amount'], $r['status'] === 'valid' ? '' : 'Contrepassée'], $rows);
        $txt = 'Dépenses ' . e($p['label']) . ' : <b>' . money($exp) . '</b>' . ($inc > 0 ? ', recettes diverses : ' . money($inc) : '') . '.';
        if ($byCat) {
            $k = array_key_first($byCat);
            $txt .= ' Le premier poste est <b>' . e($k) . '</b> (' . money($byCat[$k]) . ', ' . round($byCat[$k] / max(1, $exp) * 100) . ' %).';
        }
        return self::out('Dépenses et recettes', $p, ['N°', 'Date', 'Type', 'Catégorie', 'Description', 'Mode', 'Montant', 'Statut'], $out, [
            'text' => $txt, 'file' => 'depenses', 'landscape' => true, 'kpis' => [['Dépenses', money($exp)], ['Recettes', money($inc)], ['Opérations', (string) count($valid)]],
            'links' => [['Dépenses & recettes', url('/expenses')]],
        ]);
    }

    public static function cash(?array $p): array
    {
        self::guard('caisse');
        $p = self::range($p, 'month');
        $rows = DB::all("SELECT cs.*, u.full_name, r.name AS register FROM cash_sessions cs JOIN users u ON u.id = cs.user_id JOIN cash_registers r ON r.id = cs.register_id
                WHERE cs.opened_at BETWEEN ? AND ? ORDER BY cs.opened_at DESC", self::between($p));
        $out = array_map(fn($r) => [substr($r['opened_at'], 0, 16), $r['closed_at'] ? substr($r['closed_at'], 0, 16) : 'ouverte', $r['register'], $r['full_name'], (float) $r['opening_amount'], (float) ($r['expected_amount'] ?? 0), (float) ($r['counted_amount'] ?? 0), (float) ($r['difference'] ?? 0)], $rows);
        $diff = array_sum(array_map(fn($r) => (float) ($r['difference'] ?? 0), $rows));
        $neg = array_filter($rows, fn($r) => (float) ($r['difference'] ?? 0) < -0.009);
        $byUser = [];
        foreach ($neg as $r) {
            $byUser[$r['full_name']] = ($byUser[$r['full_name']] ?? 0) + (float) $r['difference'];
        }
        asort($byUser);
        $txt = count($rows) . ' session(s) de caisse ' . e($p['label']) . ', écart cumulé : <b class="' . ($diff < 0 ? 'down' : 'up') . '">' . money($diff) . '</b>.';
        if ($byUser) {
            $k = array_key_first($byUser);
            $txt .= ' Plus gros manque : <b>' . e($k) . '</b> (' . money($byUser[$k]) . ' sur ' . count(array_filter($neg, fn($r) => $r['full_name'] === $k)) . ' clôture(s)). Un écart répété doit être expliqué.';
        } elseif ($rows) {
            $txt .= ' Aucun manque constaté : bravo à l\'équipe.';
        }
        return self::out('Sessions et écarts de caisse', $p, ['Ouverture', 'Clôture', 'Caisse', 'Caissier', 'Fond', 'Théorique', 'Compté', 'Écart'], $out, [
            'text' => $txt, 'file' => 'sessions_caisse', 'landscape' => true, 'kpis' => [['Sessions', (string) count($rows)], ['Écart cumulé', money($diff)]],
            'links' => [['Sessions de caisse', url('/cash/sessions')]],
        ]);
    }

    public static function breakdown(string $kind, ?array $p): array
    {
        self::guard($kind);
        $p = self::range($p, 'month');
        if ($kind === 'vendeurs') {
            $rows = ReportService::byUser($p['from'], $p['to']);
            $out = array_map(fn($r) => [$r['label'], (int) $r['n'], (float) $r['total'], (float) $r['discounts']], $rows);
            $txt = $rows ? 'Meilleur vendeur ' . e($p['label']) . ' : <b>' . e($rows[0]['label']) . '</b> (' . money($rows[0]['total']) . ', ' . (int) $rows[0]['n'] . ' ventes).' : 'Aucune vente.';
            return self::out('Ventes par vendeur', $p, ['Vendeur', 'Ventes', 'Total', 'Remises'], $out, ['text' => $txt, 'file' => 'ventes_vendeurs']);
        }
        if ($kind === 'heures') {
            $rows = ReportService::byHour($p['from'], $p['to']);
            $out = array_map(fn($r) => [sprintf('%02d h – %02d h', $r['h'], $r['h'] + 1), (int) $r['n'], (float) $r['total']], $rows);
            usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
            $txt = $rows ? 'L\'heure la plus chargée ' . e($p['label']) . ' est <b>' . sprintf('%02d h – %02d h', $rows[0]['h'], $rows[0]['h'] + 1) . '</b> (' . (int) $rows[0]['n'] . ' ventes). Prévoyez assez de caissiers et de monnaie à ce moment-là.' : 'Aucune vente.';
            return self::out('Affluence par heure', $p, ['Heure', 'Ventes', 'Total'], $out, ['text' => $txt, 'file' => 'affluence']);
        }
        if ($kind === 'categories') {
            $rows = ReportService::byCategory($p['from'], $p['to']);
            $tot = array_sum(array_column($rows, 'revenue'));
            $out = array_map(fn($r) => [$r['label'], (float) $r['revenue'], (float) $r['margin'], $tot > 0 ? round($r['revenue'] / $tot * 100, 1) . ' %' : ''], $rows);
            $txt = $rows ? 'Première catégorie ' . e($p['label']) . ' : <b>' . e($rows[0]['label']) . '</b> (' . money($rows[0]['revenue']) . ').' : 'Aucune vente.';
            return self::out('Ventes par catégorie', $p, ['Catégorie', 'Chiffre d\'affaires', 'Marge', 'Part'], $out, ['text' => $txt, 'file' => 'ventes_categories']);
        }
        $rows = ReportService::byMethod($p['from'], $p['to']);
        $tot = array_sum(array_column($rows, 'total'));
        $out = array_map(fn($r) => [self::METHODS[$r['method']] ?? $r['method'], (float) $r['total'], $tot > 0 ? round($r['total'] / $tot * 100, 1) . ' %' : ''], $rows);
        $txt = $rows ? implode(', ', array_map(fn($r) => (self::METHODS[$r['method']] ?? $r['method']) . ' ' . money($r['total']), $rows)) . ' ' . e($p['label']) . '.' : 'Aucun paiement.';
        return self::out('Ventes par mode de paiement', $p, ['Mode', 'Montant', 'Part'], $out, ['text' => $txt, 'file' => 'modes_paiement']);
    }

    public static function sale(string $number): array
    {
        self::guard('vente');
        $s = DB::one('SELECT s.*, u.full_name, c.name AS customer FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id WHERE s.number = ? OR s.number LIKE ?', [$number, '%' . $number]);
        if (!$s || (!Auth::can('sales.view_all') && (int) $s['user_id'] !== Auth::id())) {
            throw new BusinessException('Vente « ' . $number . ' » introuvable.');
        }
        $items = DB::all('SELECT si.*, pk.name AS pack FROM sale_items si LEFT JOIN product_packagings pk ON pk.id = si.packaging_id WHERE si.sale_id = ?', [$s['id']]);
        $pay = DB::all('SELECT method, amount, reference FROM sale_payments WHERE sale_id = ?', [$s['id']]);
        $out = array_map(fn($i) => [$i['product_name'] . ($i['pack'] ? ' (' . $i['pack'] . ')' : ''), (float) $i['quantity'], (float) $i['unit_price'], (float) $i['discount'], (float) $i['line_total']], $items);
        $txt = 'Vente <b>' . e($s['number']) . '</b> du ' . fdate($s['created_at']) . ' par ' . e($s['full_name']) . ($s['customer'] ? ' pour <b>' . e($s['customer']) . '</b>' : '') . ' : <b>' . money($s['total']) . '</b> payée par ' . e(implode(' + ', array_map(fn($x) => (self::METHODS[$x['method']] ?? $x['method']) . ' ' . money($x['amount']), $pay))) . ($s['status'] === 'cancelled' ? ' — <b class="down">annulée</b>' : '') . '.';
        $p = ['from' => substr($s['created_at'], 0, 10), 'to' => substr($s['created_at'], 0, 10), 'label' => ''];
        return self::out('Vente ' . $s['number'], $p, ['Article', 'Quantité', 'Prix', 'Remise', 'Total'], $out, [
            'text' => $txt, 'file' => 'vente_' . $s['number'], 'footer' => ['TOTAL', '', '', '', (float) $s['total']],
            'links' => [['Ouvrir la vente', url('/sales/' . $s['id'])], ['Ticket', url('/sales/' . $s['id'] . '/ticket')], ['Facture A4', url('/sales/' . $s['id'] . '/invoice')]],
        ]);
    }

    // ================================================================== analyses
    /** Bilan intelligent : chiffres, comparaison, points forts, alertes et conseils. */
    public static function bilan(?array $p): array
    {
        self::guard('bilan');
        $note = '';
        if ($p === null) {
            // Journée sans vente pour l'instant : le bilan porte sur le mois en cours
            $p = self::range(null, 'today');
            if ((int) DB::value("SELECT COUNT(*) FROM sales WHERE status <> 'cancelled' AND created_at >= CURDATE()") === 0) {
                $p = self::range(null, 'month');
                $note = '<p class="tips">Aucune vente enregistrée aujourd\'hui pour l\'instant : voici le bilan du mois en cours.</p>';
            }
        }
        $fin = Auth::can('reports.financial');
        $s = ReportService::summary($p['from'], $p['to']);
        $sp = ReportService::summary(($pv = self::previous($p))['from'], $pv['to']);
        $top = ReportService::topProducts($p['from'], $p['to'], null, 3);
        $low = ReportService::lowStock(50);
        $html = $note . '<p>' . e(ucfirst($p['label'])) . ' : <b>' . $s['tickets'] . ' vente(s)</b> pour <b>' . money($s['net']) . '</b>' . self::trend(self::pct($s['net'], $sp['net'])) . ', panier moyen ' . money($s['avg_basket']) . '.';
        if ($fin) {
            $html .= ' Marge brute ' . money($s['margin']) . ' (' . self::fr($s['margin_rate']) . ' %), bénéfice estimé <b>' . money($s['profit']) . '</b>.';
        }
        $html .= '</p><ul>';
        if ($top) {
            $html .= '<li><b>Article vedette</b> : ' . e($top[0]['name']) . ' (' . self::q((float) $top[0]['qty']) . ' vendus, ' . money($top[0]['revenue']) . ').</li>';
        }
        $hours = ReportService::byHour($p['from'], $p['to']);
        if ($hours) {
            usort($hours, fn($a, $b) => $b['total'] <=> $a['total']);
            $html .= '<li><b>Heure de pointe</b> : ' . sprintf('%02d h – %02d h', $hours[0]['h'], $hours[0]['h'] + 1) . '.</li>';
        }
        $alerts = [];
        $zero = array_filter($low, fn($r) => (float) $r['qty'] <= 0);
        if ($zero) {
            $alerts[] = '<b>' . count($zero) . ' article(s) en rupture</b> (' . e(implode(', ', array_slice(array_column($zero, 'name'), 0, 3))) . (count($zero) > 3 ? '…' : '') . ')';
        }
        if (count($low) > count($zero)) {
            $alerts[] = (count($low) - count($zero)) . ' article(s) sous le seuil d\'alerte';
        }
        if ($fin) {
            $late = (int) DB::value("SELECT COUNT(DISTINCT c.id) FROM customers c JOIN customer_transactions t ON t.customer_id = c.id AND t.type = 'credit_sale' AND t.due_date < CURDATE() WHERE c.balance > 0.009");
            if ($late) {
                $alerts[] = '<b>' . $late . ' client(s) en retard de paiement</b> (créances totales ' . money(ReportService::receivables()) . ')';
            }
            $gap = (float) DB::value("SELECT COALESCE(SUM(difference),0) FROM cash_sessions WHERE status = 'closed' AND closed_at >= NOW() - INTERVAL 7 DAY AND difference < 0");
            if ($gap < -0.009) {
                $alerts[] = 'écarts de caisse négatifs cette semaine : <b>' . money($gap) . '</b>';
            }
            if ($s['profit'] < 0) {
                $alerts[] = 'les dépenses dépassent la marge sur la période';
            }
        }
        if (Auth::can('stock.view') && (int) DB::value("SELECT COUNT(*) FROM stock_lots WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()") > 0) {
            $alerts[] = 'des <b>lots périmés</b> sont encore en stock';
        }
        $html .= $alerts ? '<li><b>À surveiller</b> : ' . implode(' ; ', $alerts) . '.</li>' : '<li><b>À surveiller</b> : rien d\'anormal, tout est en ordre.</li>';
        $html .= '</ul>';
        $tips = [];
        if ($zero || $low) {
            $tips[] = '« Que dois-je commander ? » prépare la liste de réapprovisionnement.';
        }
        if ($fin && ReportService::receivables() > 0) {
            $tips[] = '« Qui me doit de l\'argent ? » liste les créances à relancer.';
        }
        $tips[] = '« Articles qui ne se vendent pas » montre l\'argent immobilisé en stock.';
        $html .= '<p class="tips">Conseil : ' . implode(' ', array_slice($tips, 0, 2)) . '</p>';
        $rows = [['Ventes', $s['tickets']], ['Chiffre d\'affaires net', $s['net']], ['Panier moyen', round($s['avg_basket'])]];
        if ($fin) {
            array_push($rows, ['Marge brute', $s['margin']], ['Dépenses', $s['expenses']], ['Bénéfice estimé', $s['profit']], ['Créances clients', ReportService::receivables()], ['Dettes fournisseurs', ReportService::payables()]);
        }
        return self::out('Bilan ' . $p['label'], $p, ['Indicateur', 'Valeur'], $rows, ['html' => $html, 'file' => 'bilan',
            'kpis' => array_values(array_filter([['Ventes', (string) $s['tickets']], ['CA net', money($s['net'])], $fin ? ['Marge', money($s['margin'])] : null, $fin ? ['Bénéfice', money($s['profit'])] : null]))]);
    }

    /** Réapprovisionnement : vitesse de vente sur 30 jours, jours de stock restants, quantité conseillée (14 jours). */
    public static function reorder(int $cover = 14): array
    {
        self::guard('commande');
        $rows = DB::all("SELECT p.id, p.name, p.min_stock, p.cost_price, COALESCE(st.q,0) AS qty, COALESCE(v.sold,0) AS sold, sp.name AS supplier
                FROM products p LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) st ON st.product_id = p.id
                LEFT JOIN (SELECT si.product_id, SUM(si.base_quantity - si.returned_qty * si.base_quantity / si.quantity) sold FROM sale_items si JOIN sales s ON s.id = si.sale_id
                           WHERE s.status <> 'cancelled' AND s.created_at >= NOW() - INTERVAL 30 DAY GROUP BY si.product_id) v ON v.product_id = p.id
                LEFT JOIN suppliers sp ON sp.id = p.supplier_id
                WHERE p.status = 'active' AND p.track_stock = 1");
        $out = [];
        $budget = 0.0;
        foreach ($rows as $r) {
            $perDay = (float) $r['sold'] / 30;
            $qty = (float) $r['qty'];
            $daysLeft = $perDay > 0 ? $qty / $perDay : INF;
            $need = max($perDay * $cover + (float) $r['min_stock'] - $qty, (float) $r['min_stock'] - $qty);
            if ($need <= 0.0001 || ($perDay <= 0 && $qty > (float) $r['min_stock'])) {
                continue;
            }
            $need = ceil($need);
            $budget += $need * (float) $r['cost_price'];
            $out[] = [$r['name'], (string) ($r['supplier'] ?? ''), $qty, round($perDay, 2), is_infinite($daysLeft) ? '—' : (string) (int) floor($daysLeft), $need, $need * (float) $r['cost_price']];
        }
        usort($out, fn($a, $b) => ($a[4] === '—' ? 9999 : (int) $a[4]) <=> ($b[4] === '—' ? 9999 : (int) $b[4]));
        $urgent = count(array_filter($out, fn($r) => $r[4] !== '—' && (int) $r[4] <= 3));
        $txt = $out ? 'Pour couvrir <b>' . $cover . ' jours</b> de ventes au rythme des 30 derniers jours, il faut commander <b>' . count($out) . ' article(s)</b> pour environ <b>' . money($budget) . '</b> (au coût moyen). ' . ($urgent ? '<b class="down">' . $urgent . ' article(s) seront épuisés dans 3 jours ou moins.</b>' : '') : 'Aucune commande nécessaire : le stock couvre les ventes des ' . $cover . ' prochains jours.';
        $p = ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime("+$cover days")), 'label' => 'pour ' . $cover . ' jours'];
        return self::out('Suggestion de réapprovisionnement', $p, ['Article', 'Fournisseur', 'Stock', 'Vendu / jour', 'Jours restants', 'À commander', 'Coût estimé'], $out, [
            'text' => $txt, 'file' => 'reapprovisionnement', 'landscape' => true, 'kpis' => [['Articles', (string) count($out)], ['Budget', money($budget)], ['Urgents', (string) $urgent]],
            'links' => [['Commandes fournisseurs', url('/purchases')]],
        ]);
    }

    /** Prévision du mois : rythme du mois en cours et comparaison avec le mois dernier. */
    public static function forecast(): array
    {
        self::guard('prevision');
        $p = ['from' => date('Y-m-01'), 'to' => date('Y-m-d'), 'label' => 'ce mois-ci'];
        $s = ReportService::summary($p['from'], $p['to']);
        $day = (int) date('j');
        $dim = (int) date('t');
        $proj = $day > 0 ? $s['net'] / $day * $dim : 0;
        $lm = ReportService::summary(date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month')));
        $txt = 'Après <b>' . $day . ' jour(s)</b>, le chiffre d\'affaires du mois est de ' . money($s['net']) . '. À ce rythme, le mois devrait finir vers <b>' . money($proj) . '</b>' . self::trend(self::pct($proj, $lm['net'])) . '. Mois dernier : ' . money($lm['net']) . '.';
        if ($lm['net'] > 0 && $proj < $lm['net']) {
            $txt .= ' Pour faire au moins autant, il faut vendre environ <b>' . money(max(0, ($lm['net'] - $s['net']) / max(1, $dim - $day))) . ' par jour</b> d\'ici la fin du mois.';
        }
        $rows = [['Chiffre d\'affaires à ce jour', $s['net']], ['Moyenne par jour', $day ? $s['net'] / $day : 0], ['Projection fin de mois', $proj], ['Mois dernier', $lm['net']]];
        return self::out('Prévision du mois', $p, ['Indicateur', 'Montant'], $rows, ['text' => $txt, 'file' => 'prevision', 'kpis' => [['À ce jour', money($s['net'])], ['Projection', money($proj)], ['Mois dernier', money($lm['net'])]]]);
    }
}
