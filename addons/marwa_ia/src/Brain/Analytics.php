<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use App\Core\DB;
use App\Services\CashService;

/**
 * MARWA BUSINESS ANALYTICS : bilans, explication d'une évolution des ventes, anomalies.
 * Une anomalie est un écart statistique ou une règle de gestion non respectée, à VÉRIFIER :
 * elle n'est jamais présentée comme une fraude ni attribuée comme une faute à une personne.
 */
final class Analytics
{
    private static function m(float $v): string
    {
        return Catalog::money($v);
    }

    /** Bilan d'une période (jour ou mois) limité aux droits de l'utilisateur. */
    public static function report(UserContext $u, array $per): array
    {
        $parts = [];
        $cards = [];
        $facts = [];
        $sources = [];
        $sugg = [];
        if ($u->can('sales.view')) {
            $f = Catalog::salesFacts($u, [], $per);
            [$w, $p] = $u->salesWhere('s');
            $b = ['from' => $per['from'], 'to' => $per['to']];
            $ret = (float) DB::value("SELECT COALESCE(SUM(r.total),0) FROM sale_returns r JOIN sales s ON s.id = r.sale_id WHERE r.created_at BETWEEN ? AND ? $w", array_merge([$b['from'] . ' 00:00:00', $b['to'] . ' 23:59:59'], $p));
            $canc = (int) DB::value("SELECT COUNT(*) FROM sales s WHERE s.status = 'cancelled' AND s.created_at BETWEEN ? AND ? $w", array_merge([$b['from'] . ' 00:00:00', $b['to'] . ' 23:59:59'], $p));
            $prev = Catalog::salesFacts($u, [], Catalog::previous($per));
            $trend = $prev['t'] > 0 ? round(($f['t'] - $prev['t']) / $prev['t'] * 100, 1) : null;
            $parts[] = "{$f['n']} vente" . ($f['n'] > 1 ? 's' : '') . ' pour ' . self::m($f['t']) . ($trend !== null ? ' (' . ($trend >= 0 ? '+' : '−') . Catalog::n(abs($trend)) . ' % par rapport à ' . Catalog::previous($per)['label'] . ')' : '') . ($ret > 0 ? ', ' . self::m($ret) . ' de retours' : '') . ($canc ? ", $canc annulation" . ($canc > 1 ? 's' : '') : '');
            $cards[] = ['Ventes', (string) $f['n']];
            $cards[] = ['Montant', self::m($f['t'])];
            array_push($facts, $f['n'], $f['t'], $ret, $canc, $prev['t'], $trend !== null ? abs($trend) : 0);
            $sources[] = 'sales';
        }
        if ($u->can('reports.financial')) {
            $sum = \App\Services\ReportService::summary($per['from'], $per['to'], $u->global ? null : $u->storeId, $u->seesAllSales() ? null : $u->id());
            $parts[] = 'marge ' . self::m($sum['margin']) . ', dépenses ' . self::m($sum['expenses']) . ', bénéfice estimé ' . self::m($sum['profit']);
            $cards[] = ['Bénéfice estimé', self::m($sum['profit'])];
            array_push($facts, $sum['margin'], $sum['expenses'], $sum['profit']);
            $sources[] = 'expenses';
        } elseif ($u->can('expenses.manage')) {
            $e = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'valid' AND type = 'expense' AND expense_date BETWEEN ? AND ?", [$per['from'], $per['to']]);
            $parts[] = 'dépenses ' . self::m($e);
            $facts[] = $e;
        }
        if ($u->can('cash.view_all') && $per['from'] === date('Y-m-d')) {
            $cash = 0.0;
            foreach (DB::all("SELECT id FROM cash_sessions WHERE status = 'open'") as $s) {
                $cash += CashService::expectedCash((int) $s['id']);
            }
            $parts[] = 'espèces théoriques en caisse ' . self::m($cash);
            $cards[] = ['Espèces en caisse', self::m($cash)];
            $facts[] = $cash;
            $sources[] = 'cash_sessions';
        }
        if ($u->can('stock.view')) {
            $out = Catalog::run('stock_out', $u, []);
            $low = Catalog::run('stock_low', $u, []);
            $parts[] = $out['facts'][0] . ' article(s) en rupture et ' . $low['facts'][0] . ' en stock faible';
            array_push($facts, $out['facts'][0], $low['facts'][0]);
            $sources[] = 'stock_levels';
            if ($out['facts'][0] + $low['facts'][0] > 0) {
                $sugg[] = 'Quels produits dois-je recommander ?';
            }
        }
        $top = null;
        if ($u->can('reports.view')) {
            $top = Catalog::run('top_products', $u, ['period' => $per, 'limit' => 3]);
            if (!$top['empty']) {
                $parts[] = 'meilleures ventes : ' . implode(', ', array_column($top['table']['rows'], 0));
                $facts = array_merge($facts, $top['facts']);
            }
            $an = self::anomalies($u, $per);
            if (!empty($an['facts'][0])) {
                $parts[] = $an['facts'][0] . ' point(s) à vérifier';
                $facts[] = $an['facts'][0];
                $sugg[] = 'Identifie les anomalies';
            }
        }
        if (!$parts) {
            return ['facts' => [], 'empty' => true, 'variants' => ['Votre rôle ne donne accès à aucune des données du bilan (ventes, stock, caisse, finances).']];
        }
        $title = $per['label'] === 'aujourd\'hui' ? 'Bilan de la journée' : 'Bilan ' . $per['label'];
        return ['facts' => $facts, 'cards' => $cards, 'sources' => array_values(array_unique(array_merge($sources, ['sale_items']))), 'period' => $per['label'],
            'variants' => ["$title : " . implode(' ; ', $parts) . '.', ucfirst($per['label']) . ', en résumé : ' . implode(' ; ', $parts) . '.'],
            'table' => $top && !$top['empty'] ? $top['table'] : null, 'followups' => array_merge($sugg, ['Compare avec la semaine dernière'])];
    }

    /** Explication factuelle d'une variation des ventes (aucune cause inventée : seulement ce que les données montrent). */
    public static function explainDecline(UserContext $u, array $s): array
    {
        $a = Catalog::period($s, 'week');
        $b = Catalog::previous($a);
        $fa = Catalog::salesFacts($u, $s, $a);
        $fb = Catalog::salesFacts($u, $s, $b);
        if ($fb['n'] === 0 && $fa['n'] === 0) {
            return ['facts' => [], 'empty' => true, 'sources' => ['sales'], 'variants' => ['Pas assez de ventes sur ces deux périodes pour analyser une évolution.']];
        }
        $d = $fa['t'] - $fb['t'];
        $reasons = [];
        $facts = [$fa['t'], $fb['t'], abs($d), $fa['n'], $fb['n']];
        $avgA = $fa['n'] ? $fa['t'] / $fa['n'] : 0;
        $avgB = $fb['n'] ? $fb['t'] / $fb['n'] : 0;
        if ($fa['n'] < $fb['n']) {
            $reasons[] = 'moins de ventes (' . $fa['n'] . ' contre ' . $fb['n'] . ')';
        }
        if ($avgA < $avgB - 0.5) {
            $reasons[] = 'un panier moyen plus faible (' . self::m($avgA) . ' contre ' . self::m($avgB) . ')';
            array_push($facts, $avgA, $avgB);
        }
        [$w, $p] = $u->salesWhere('s');
        $q = fn(array $per) => array_column(DB::all("SELECT si.product_name n, SUM(si.line_total) t FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w GROUP BY si.product_id, si.product_name", array_merge([$per['from'] . ' 00:00:00', $per['to'] . ' 23:59:59'], $p)), 't', 'n');
        $ta = $q($a);
        $tb = $q($b);
        $drops = [];
        foreach ($tb as $name => $t) {
            $diff = (float) $t - (float) ($ta[$name] ?? 0);
            if ($diff > 0) {
                $drops[$name] = $diff;
            }
        }
        arsort($drops);
        $drops = array_slice($drops, 0, 3, true);
        if ($drops) {
            $reasons[] = 'des articles qui se sont moins vendus : ' . implode(', ', array_map(fn($n, $v) => "$n (−" . self::m($v) . ')', array_keys($drops), $drops));
            $facts = array_merge($facts, array_values($drops));
        }
        if ($u->can('stock.view') && $drops) {
            $names = array_keys($drops);
            $out = DB::all('SELECT p.name FROM products p LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) x ON x.product_id = p.id
                WHERE p.name IN (' . implode(',', array_fill(0, count($names), '?')) . ') AND COALESCE(x.q,0) <= 0', $names);
            if ($out) {
                $reasons[] = 'dont certains sont aujourd\'hui en rupture : ' . implode(', ', array_column($out, 'name'));
            }
        }
        $cA = (int) DB::value("SELECT COUNT(*) FROM sales s WHERE s.status = 'cancelled' AND s.created_at BETWEEN ? AND ? $w", array_merge([$a['from'] . ' 00:00:00', $a['to'] . ' 23:59:59'], $p));
        if ($cA > 0) {
            $reasons[] = "$cA vente" . ($cA > 1 ? 's annulées' : ' annulée') . ' sur la période';
            $facts[] = $cA;
        }
        $days = (int) DB::value("SELECT COUNT(DISTINCT DATE(s.created_at)) FROM sales s WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w", array_merge([$a['from'] . ' 00:00:00', $a['to'] . ' 23:59:59'], $p));
        $daysB = (int) DB::value("SELECT COUNT(DISTINCT DATE(s.created_at)) FROM sales s WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $w", array_merge([$b['from'] . ' 00:00:00', $b['to'] . ' 23:59:59'], $p));
        if ($days < $daysB) {
            $reasons[] = "moins de jours avec des ventes ($days contre $daysB)";
            array_push($facts, $days, $daysB);
        }
        $verb = $d < 0 ? 'baissé' : 'augmenté';
        $head = "Les ventes ont $verb de " . self::m(abs($d)) . " {$a['label']} (" . self::m($fa['t']) . ") par rapport à {$b['label']} (" . self::m($fb['t']) . ').';
        if ($d >= 0) {
            $head = 'Les ventes n\'ont pas diminué : ' . self::m($fa['t']) . " {$a['label']} contre " . self::m($fb['t']) . " {$b['label']}.";
        }
        return ['facts' => $facts, 'sources' => ['sales', 'sale_items', 'stock_levels'], 'period' => $a['label'],
            'variants' => [$head . ($d < 0 && $reasons ? ' D\'après les données, cela s\'explique par : ' . implode(' ; ', $reasons) . '.' : ($d < 0 ? ' Les données ne montrent pas de cause particulière (même nombre de ventes et mêmes articles).' : ''))],
            'note' => 'Explication tirée uniquement des ventes, des articles et du stock ; les causes extérieures (météo, concurrence, jours fériés) ne sont pas connues du logiciel.',
            'followups' => ['Quels produits ont augmenté en vente ?', 'Quels sont les produits en rupture ?']];
    }

    /**
     * Anomalies à vérifier sur une période. Chaque contrôle est une règle simple et explicable.
     * @return array résultat d'outil (facts[0] = nombre de points relevés)
     */
    public static function anomalies(UserContext $u, array $per): array
    {
        $from = $per['from'] . ' 00:00:00';
        $to = $per['to'] . ' 23:59:59';
        $items = [];
        [$sw, $sp] = $u->salesWhere('s');
        // 1. Journées anormales (écart > 2,5 écarts-types sur 90 jours)
        if ($u->can('sales.view')) {
            $days = DB::all("SELECT DATE(s.created_at) d, SUM(s.total) t FROM sales s WHERE s.status <> 'cancelled' AND s.created_at >= ? $sw GROUP BY d", array_merge([date('Y-m-d', strtotime('-90 days'))], $sp));
            if (count($days) >= 10) {
                $vals = array_map(fn($r) => (float) $r['t'], $days);
                $mean = array_sum($vals) / count($vals);
                $sd = sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $vals)) / count($vals));
                foreach ($days as $r) {
                    if ($sd > 0 && $r['d'] >= $per['from'] && $r['d'] <= $per['to'] && abs(((float) $r['t'] - $mean) / $sd) > 2.5) {
                        $items[] = ['Ventes inhabituelles', fdate($r['d'], false) . ' : ' . self::m((float) $r['t']) . ' (moyenne ' . self::m($mean) . ')', 'statistique'];
                    }
                }
            }
            // 2. Annulations répétées par un même vendeur
            foreach (DB::all("SELECT u.full_name, COUNT(*) n FROM sales s JOIN users u ON u.id = s.user_id WHERE s.status = 'cancelled' AND s.created_at BETWEEN ? AND ? $sw GROUP BY s.user_id HAVING n >= 3 ORDER BY n DESC", array_merge([$from, $to], $sp)) as $r) {
                $items[] = ['Annulations répétées', $r['n'] . ' ventes annulées enregistrées par ' . $r['full_name'], 'règle'];
            }
            // 3. Ventes identiques répétées en moins de 2 minutes
            foreach (DB::all("SELECT a.number n1, b.number n2, a.total FROM sales a JOIN sales b ON b.id > a.id AND b.user_id = a.user_id AND b.total = a.total AND b.created_at <= a.created_at + INTERVAL 2 MINUTE
                    WHERE a.status <> 'cancelled' AND b.status <> 'cancelled' AND a.created_at BETWEEN ? AND ? " . str_replace('s.', 'a.', $sw) . ' LIMIT 10', array_merge([$from, $to], $sp)) as $r) {
                $items[] = ['Opérations répétées', "ventes {$r['n1']} et {$r['n2']} de même montant (" . self::m((float) $r['total']) . ') à moins de 2 minutes d\'écart', 'règle'];
            }
            // 4. Ventes sous le prix d'achat
            if ($u->seesCosts()) {
                $n = (int) DB::value("SELECT COUNT(*) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? AND si.unit_cost > 0 AND si.line_total < si.unit_cost * si.base_quantity - 0.5 $sw", array_merge([$from, $to], $sp));
                if ($n) {
                    $items[] = ['Prix incohérents', "$n ligne(s) de vente sous le coût d'achat (remise forte ou prix à revoir)", 'règle'];
                }
            }
            // 5. Remboursements inhabituels (> 3 fois le retour moyen)
            $avg = (float) DB::value("SELECT AVG(r.total) FROM sale_returns r JOIN sales s ON s.id = r.sale_id WHERE r.created_at >= ? $sw", array_merge([date('Y-m-d', strtotime('-90 days'))], $sp));
            if ($avg > 0) {
                foreach (DB::all("SELECT r.number, r.total FROM sale_returns r JOIN sales s ON s.id = r.sale_id WHERE r.created_at BETWEEN ? AND ? AND r.total > ? $sw LIMIT 10", array_merge([$from, $to, 3 * $avg], $sp)) as $r) {
                    $items[] = ['Remboursement inhabituel', $r['number'] . ' : ' . self::m((float) $r['total']) . ' (retour moyen ' . self::m($avg) . ')', 'statistique'];
                }
            }
        }
        // 6. Écarts de caisse
        if ($u->can('cash.view_all')) {
            foreach (DB::all("SELECT cs.closed_at, r.name, cs.difference FROM cash_sessions cs JOIN cash_registers r ON r.id = cs.register_id WHERE cs.status = 'closed' AND cs.closed_at BETWEEN ? AND ? AND ABS(cs.difference) >= ?", [$from, $to, max(500, (float) setting('cash_difference_alert', '1000'))]) as $r) {
                $items[] = ['Écart de caisse', $r['name'] . ' le ' . fdate($r['closed_at']) . ' : ' . self::m((float) $r['difference']), 'règle'];
            }
        }
        // 7. Stocks négatifs et sorties importantes
        if ($u->can('stock.view')) {
            [$ww, $wp] = $u->warehouseWhere('l.warehouse_id');
            $neg = (int) DB::value("SELECT COUNT(*) FROM stock_levels l WHERE l.quantity < 0 $ww", $wp);
            if ($neg) {
                $items[] = ['Stock négatif', "$neg article(s) avec une quantité négative (vente sans réception enregistrée ?)", 'règle'];
            }
            [$mw, $mp] = $u->warehouseWhere('m.warehouse_id');
            foreach (DB::all("SELECT p.name, m.quantity, m.created_at FROM stock_movements m JOIN products p ON p.id = m.product_id WHERE m.type IN ('adjustment','adjusted','loss') AND m.quantity < 0 AND m.created_at BETWEEN ? AND ?
                    AND ABS(m.quantity) > GREATEST(10, 3 * p.min_stock) $mw ORDER BY m.quantity LIMIT 10", array_merge([$from, $to], $mp)) as $r) {
                $items[] = ['Mouvement de stock important', 'sortie de ' . Catalog::n(abs((float) $r['quantity'])) . ' « ' . $r['name'] . ' » le ' . fdate($r['created_at']), 'règle'];
            }
        }
        // 8. Paiements électroniques à vérifier
        if ($u->can('pay.view') && \App\Core\Addons::isActive('pay')) {
            $n = (int) DB::value("SELECT COUNT(*) FROM payment_transactions WHERE is_test = 0 AND (status = 'to_verify' OR (status = 'succeeded' AND sale_id IS NULL AND created_at < NOW() - INTERVAL 1 HOUR))");
            if ($n) {
                $items[] = ['Paiements à vérifier', "$n paiement(s) électronique(s) à vérifier ou réussis sans vente", 'règle'];
            }
        }
        $n = count($items);
        return ['facts' => [$n], 'sources' => ['sales', 'sale_returns', 'cash_sessions', 'stock_levels', 'stock_movements'], 'period' => $per['label'], 'empty' => $n === 0,
            'variants' => [$n === 0 ? "Je n'ai relevé aucune anomalie {$per['label']} dans les données que vous pouvez consulter." : "J'ai relevé $n point" . ($n > 1 ? 's' : '') . " à vérifier {$per['label']}. Ce sont des écarts par rapport aux habitudes ou aux règles de gestion, pas des preuves de fraude : vérifiez chaque cas avant toute conclusion."],
            'table' => $n ? ['headers' => ['Type', 'Détail', 'Nature'], 'rows' => array_slice($items, 0, 50)] : null,
            'note' => 'Nature « statistique » : valeur éloignée de la moyenne ; « règle » : situation contraire à une règle de gestion.'];
    }
}
