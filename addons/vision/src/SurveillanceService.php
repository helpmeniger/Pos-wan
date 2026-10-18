<?php
declare(strict_types=1);

namespace Addons\Vision;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Surveillance des articles : passages détectés par les caméras (ligne franchie ou zone),
 * article reconnu, et comparaison avec les ventes et les sorties de stock.
 *
 * Un passage n'est PAS une preuve de vol : c'est une indication à vérifier (détection et
 * reconnaissance automatiques, imparfaites). Le rapport sépare les passages « identifiés »
 * (reconnaissance sûre), « incertains » et « non identifiés ».
 */
final class SurveillanceService
{
    public const DIRECTIONS = ['sortie' => 'Ligne franchie (sortie)', 'entree' => 'Ligne franchie (retour)', 'vu' => 'Vu dans la zone'];
    public const STATUSES = ['identifie' => 'Identifié', 'incertain' => 'Incertain', 'confirme' => 'Confirmé', 'corrige' => 'Corrigé', 'ignore' => 'Ignoré'];

    public static function addEvent(array $in): array
    {
        $cam = DB::one('SELECT * FROM vision_cameras WHERE id = ? AND active = 1 AND use_surveillance = 1', [(int) ($in['camera_id'] ?? 0)])
            ?? throw new BusinessException('Caméra de surveillance introuvable ou désactivée.');
        $dir = (string) ($in['direction'] ?? '');
        if (!isset(self::DIRECTIONS[$dir])) {
            throw new BusinessException('Sens de passage inconnu.');
        }
        $pid = (int) ($in['product_id'] ?? 0) ?: null;
        if ($pid && !DB::value("SELECT 1 FROM products WHERE id = ? AND status = 'active'", [$pid])) {
            $pid = null;
        }
        $num = fn($k) => isset($in[$k]) && is_numeric($in[$k]) ? max(-1, min(1, round((float) $in[$k], 3))) : null;
        $score = $num('score');
        $margin = $num('margin');
        $sure = $pid && $score !== null && $margin !== null && $score >= (float) setting('vision_surv_seuil', '0.45') && $margin >= (float) setting('vision_surv_marge', '0.04');
        $thumb = VisionService::cleanThumb(isset($in['thumb']) ? (string) $in['thumb'] : null);
        $id = DB::insert('vision_events', ['camera_id' => $cam['id'], 'direction' => $dir, 'product_id' => $pid, 'score' => $score, 'margin' => $margin,
            'status' => $sure ? 'identifie' : 'incertain', 'thumb' => $thumb, 'seen_at' => date('Y-m-d H:i:s'), 'user_id' => Auth::id()]);
        return ['id' => (int) $id, 'status' => $sure ? 'identifie' : 'incertain'];
    }

    /** Revue d'un passage : confirmer l'article proposé, le corriger, ou ignorer (pas un article). */
    public static function review(int $id, string $action, ?int $productId): array
    {
        $ev = DB::one('SELECT * FROM vision_events WHERE id = ?', [$id]) ?? throw new BusinessException('Passage introuvable.');
        $data = ['reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s')];
        if ($action === 'confirm') {
            if (!$ev['product_id']) {
                throw new BusinessException('Aucun article proposé : choisissez l\'article (corriger).');
            }
            $data['status'] = 'confirme';
        } elseif ($action === 'correct') {
            if (!$productId || !DB::value("SELECT 1 FROM products WHERE id = ? AND status = 'active'", [$productId])) {
                throw new BusinessException('Article introuvable.');
            }
            $data['status'] = 'corrige';
            $data['product_id'] = $productId;
        } elseif ($action === 'ignore') {
            $data['status'] = 'ignore';
        } else {
            throw new BusinessException('Action inconnue.');
        }
        DB::update('vision_events', $data, 'id = ?', [$id]);
        Audit::log('vision.event_review', 'product', $data['product_id'] ?? $ev['product_id'], ['event' => $id, 'action' => $action]);
        return ['status' => $data['status']];
    }

    /** Effacement des passages (et de leurs vignettes) au-delà de la durée de conservation. */
    public static function purge(): int
    {
        $days = max(1, min(365, (int) setting('vision_surv_conservation', '30')));
        return DB::run('DELETE FROM vision_events WHERE seen_at < NOW() - INTERVAL ' . $days . ' DAY')->rowCount();
    }

    /**
     * Comparaison passages vus ↔ ventes ↔ sorties de stock, article par article.
     * Rapprochement : chaque passage (sortie ou zone) d'un article est associé à une unité vendue
     * du même article dans une fenêtre de ± N minutes ; les passages sans vente sont « non
     * justifiés » (à vérifier), les ventes sans passage mesurent la couverture des caméras.
     */
    public static function compare(string $from, string $to, array $cameraIds, int $windowMin): array
    {
        $windowMin = max(1, min(240, $windowMin));
        $start = $from . ' 00:00:00';
        $end = $to . ' 23:59:59';
        $camSql = $cameraIds ? ' AND e.camera_id IN (' . implode(',', array_map('intval', $cameraIds)) . ')' : '';
        $events = DB::all("SELECT e.id, e.camera_id, e.direction, e.product_id, e.status, e.score, e.seen_at, c.name AS camera
                           FROM vision_events e JOIN vision_cameras c ON c.id = e.camera_id
                           WHERE e.seen_at BETWEEN ? AND ? AND e.status <> 'ignore' $camSql ORDER BY e.seen_at", [$start, $end]);
        // Ventes de la période élargie de la fenêtre (une vente juste avant minuit justifie un passage juste après)
        $sales = DB::all("SELECT si.product_id, si.base_quantity AS qty, s.created_at, s.id AS sale_id, s.number
                          FROM sale_items si JOIN sales s ON s.id = si.sale_id
                          WHERE s.status IN ('completed','partially_returned') AND s.created_at BETWEEN ? - INTERVAL ? MINUTE AND ? + INTERVAL ? MINUTE
                          ORDER BY s.created_at", [$start, $windowMin, $end, $windowMin]);
        $outs = DB::all("SELECT product_id, type, -SUM(quantity) AS qty FROM stock_movements WHERE quantity < 0 AND created_at BETWEEN ? AND ? GROUP BY product_id, type", [$start, $end]);

        $P = [];
        $row = function (int $pid) use (&$P): void {
            $P[$pid] ??= ['product_id' => $pid, 'seen' => 0, 'seen_sure' => 0, 'seen_unsure' => 0, 'returns' => 0, 'sold' => 0.0, 'stock_out' => 0.0, 'out_types' => [], 'matched' => 0, 'unmatched' => 0, 'unmatched_sure' => 0, 'sales_seen' => 0.0];
        };
        // Unités vendues disponibles pour le rapprochement, par article
        $slots = [];
        foreach ($sales as $s) {
            $pid = (int) $s['product_id'];
            $t = strtotime($s['created_at']);
            $slots[$pid][] = ['t' => $t, 'left' => (float) $s['qty'], 'qty' => (float) $s['qty']];
            if ($s['created_at'] >= $start && $s['created_at'] <= $end) {
                $row($pid);
                $P[$pid]['sold'] += (float) $s['qty'];
            }
        }
        foreach ($outs as $o) {
            $pid = (int) $o['product_id'];
            $row($pid);
            $P[$pid]['stock_out'] += (float) $o['qty'];
            $P[$pid]['out_types'][$o['type']] = ($P[$pid]['out_types'][$o['type']] ?? 0) + (float) $o['qty'];
        }
        $unmatchedList = [];
        $unidentified = 0;
        $byHour = array_fill(0, 24, ['seen' => 0, 'sold' => 0.0, 'unmatched' => 0]);
        foreach ($sales as $s) {
            if ($s['created_at'] >= $start && $s['created_at'] <= $end) {
                $byHour[(int) substr($s['created_at'], 11, 2)]['sold'] += (float) $s['qty'];
            }
        }
        $win = $windowMin * 60;
        foreach ($events as $e) {
            $h = (int) substr($e['seen_at'], 11, 2);
            if ($e['direction'] === 'entree') {
                if ($e['product_id']) {
                    $row((int) $e['product_id']);
                    $P[(int) $e['product_id']]['returns']++;
                }
                continue;
            }
            $byHour[$h]['seen']++;
            if (!$e['product_id']) {
                $unidentified++;
                continue;
            }
            $pid = (int) $e['product_id'];
            $row($pid);
            $sure = in_array($e['status'], ['identifie', 'confirme', 'corrige'], true);
            $P[$pid]['seen']++;
            $P[$pid][$sure ? 'seen_sure' : 'seen_unsure']++;
            // Unité vendue la plus proche dans le temps, encore disponible
            $t = strtotime($e['seen_at']);
            $best = null;
            foreach ($slots[$pid] ?? [] as $k => $sl) {
                if ($sl['left'] >= 1 - 1e-9 && abs($sl['t'] - $t) <= $win && ($best === null || abs($sl['t'] - $t) < abs($slots[$pid][$best]['t'] - $t))) {
                    $best = $k;
                }
            }
            if ($best !== null) {
                $slots[$pid][$best]['left'] -= 1;
                $P[$pid]['matched']++;
            } else {
                $P[$pid]['unmatched']++;
                $byHour[$h]['unmatched']++;
                if ($sure) {
                    $P[$pid]['unmatched_sure']++;
                }
                $unmatchedList[] = $e['id'];
            }
        }
        // Ventes « vues » par une caméra (couverture), sur la période seulement
        foreach ($slots as $pid => $list) {
            foreach ($list as $sl) {
                if ($sl['t'] >= strtotime($start) && $sl['t'] <= strtotime($end) && isset($P[$pid])) {
                    $P[$pid]['sales_seen'] += $sl['qty'] - max(0, $sl['left']);
                }
            }
        }
        $names = $P ? array_column(DB::all('SELECT id, name, reference FROM products WHERE id IN (' . implode(',', array_keys($P)) . ')'), null, 'id') : [];
        foreach ($P as $pid => &$r) {
            $r['name'] = $names[$pid]['name'] ?? ('#' . $pid);
            $r['reference'] = $names[$pid]['reference'] ?? '';
            $r['gap'] = $r['seen'] - $r['sold'];
            $r['level'] = $r['unmatched_sure'] > 0 ? 'alerte' : ($r['unmatched'] > 0 ? 'verifier' : 'ok');
        }
        unset($r);
        $rows = array_values($P);
        usort($rows, fn($a, $b) => [$b['unmatched_sure'], $b['unmatched'], $b['seen']] <=> [$a['unmatched_sure'], $a['unmatched'], $a['seen']]);
        $soldTotal = array_sum(array_column($rows, 'sold'));
        $seenSales = array_sum(array_column($rows, 'sales_seen'));
        return [
            'rows' => $rows, 'by_hour' => $byHour, 'unmatched_ids' => array_slice($unmatchedList, 0, 200),
            'totals' => [
                'events' => count(array_filter($events, fn($e) => $e['direction'] !== 'entree')),
                'identified' => array_sum(array_column($rows, 'seen')), 'sure' => array_sum(array_column($rows, 'seen_sure')),
                'unidentified' => $unidentified, 'returns' => array_sum(array_column($rows, 'returns')),
                'sold' => $soldTotal, 'stock_out' => array_sum(array_column($rows, 'stock_out')),
                'matched' => array_sum(array_column($rows, 'matched')), 'unmatched' => array_sum(array_column($rows, 'unmatched')),
                'unmatched_sure' => array_sum(array_column($rows, 'unmatched_sure')),
                'coverage' => $soldTotal > 0 ? round(100 * $seenSales / $soldTotal) : null,
            ],
            'window' => $windowMin,
        ];
    }
}
