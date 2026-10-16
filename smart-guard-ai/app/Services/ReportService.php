<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Rapports. Vocabulaire strict :
 *  - « détections vidéo » = objets distincts suivis par la caméra (observations) ;
 *  - « sorties / entrées observées » = franchissements de la ligne virtuelle ;
 *  - aucun « nombre de vols » n'est calculé (on ne soustrait jamais ventes et sorties).
 */
final class ReportService
{
    public const DISCLAIMER = 'Les détections correspondent à des observations vidéo et les sorties à des franchissements de ligne enregistrés par le système. '
        . 'Une sortie observée ne prouve pas à elle seule qu\'un article a été volé. Le rapprochement avec les ventes peut comporter des erreurs.';

    public static function summary(int $storeId, string $from, string $to, array $filters = []): array
    {
        [$f, $t] = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $p = ['s' => $storeId, 'f' => $f, 't' => $t];
        $camF = '';
        if (!empty($filters['camera_id'])) {
            $camF = ' AND camera_id = :cam';
            $p['cam'] = (int) $filters['camera_id'];
        }
        $prodF = '';
        if (!empty($filters['product_id'])) {
            $prodF = ' AND product_id = :pid';
            $p['pid'] = (int) $filters['product_id'];
        }
        $ex = Database::fetch("SELECT COUNT(*) total, SUM(direction='sortie') sorties, SUM(direction='entree') entrees,
                SUM(direction='ambigu') ambigus, SUM(direction='perte_suivi') pertes,
                SUM(direction='sortie' AND product_id IS NULL) sorties_non_identifiees,
                SUM(reconciliation_status='vente_trouvee') rapprochees, SUM(direction='sortie' AND reconciliation_status IN ('aucune_vente','incertain')) non_rapprochees
             FROM exit_events WHERE store_id = :s AND occurred_at BETWEEN :f AND :t{$camF}{$prodF}", $p) ?? [];
        $detP = $p;
        $det = Database::fetch("SELECT COUNT(*) total, SUM(d.product_id IS NOT NULL) identifies, SUM(d.product_id IS NULL) non_identifies
             FROM detection_results d JOIN cameras c ON c.id = d.camera_id
             WHERE c.store_id = :s AND d.is_test = 0 AND d.detected_at BETWEEN :f AND :t"
            . str_replace(['camera_id', 'product_id'], ['d.camera_id', 'd.product_id'], $camF . $prodF), $detP) ?? [];
        $alP = $p;
        $levelF = '';
        if (!empty($filters['level'])) {
            $levelF = ' AND level = :lvl';
            $alP['lvl'] = $filters['level'];
        }
        $al = Database::fetch("SELECT COUNT(*) total, SUM(level<>'information') a_verifier_total,
                SUM(status IN ('nouvelle','en_examen') AND level<>'information') ouvertes,
                SUM(status IN ('mouvement_normal','vente_retrouvee','erreur_detection','anomalie_confirmee','non_concluante','cloturee') AND level<>'information') verifiees,
                SUM(level='prioritaire') prioritaires, SUM(status='erreur_detection') erreurs_detection, SUM(status='anomalie_confirmee') anomalies_confirmees
             FROM alerts WHERE store_id = :s AND occurred_at BETWEEN :f AND :t{$camF}{$prodF}{$levelF}", $alP) ?? [];
        $tracksLost = (int) Database::value("SELECT COUNT(*) FROM object_tracks o JOIN cameras c ON c.id = o.camera_id
             WHERE c.store_id = :s AND o.end_reason = 'perdu' AND o.first_seen_at BETWEEN :f AND :t", ['s' => $storeId, 'f' => $f, 't' => $t]);
        $sales = Database::fetch("SELECT COUNT(DISTINCT s.id) tickets, COALESCE(SUM(si.quantity - si.returned_quantity),0) articles
             FROM sales s JOIN sale_items si ON si.sale_id = s.id WHERE s.store_id = :s AND s.status <> 'annulee' AND s.sold_at BETWEEN :f AND :t", ['s' => $storeId, 'f' => $f, 't' => $t]) ?? [];
        $cams = self::cameraUptime($storeId, $f, $t);
        $ints = static fn ($a, $k) => (int) ($a[$k] ?? 0);
        return [
            'period' => ['from' => $from, 'to' => $to],
            'events_total' => $ints($ex, 'total') + $ints($det, 'total'),
            'detections_total' => $ints($det, 'total'), 'products_identified' => $ints($det, 'identifies'), 'objects_unidentified' => $ints($det, 'non_identifies'),
            'exits' => $ints($ex, 'sorties'), 'entries' => $ints($ex, 'entrees'), 'ambiguous' => $ints($ex, 'ambigus'), 'track_lost_events' => $ints($ex, 'pertes'),
            'exits_unidentified' => $ints($ex, 'sorties_non_identifiees'), 'exits_matched' => $ints($ex, 'rapprochees'), 'exits_unmatched' => $ints($ex, 'non_rapprochees'),
            'alerts_total' => $ints($al, 'total'), 'to_verify' => $ints($al, 'a_verifier_total'), 'open_alerts' => $ints($al, 'ouvertes'),
            'verified' => $ints($al, 'verifiees'), 'priority' => $ints($al, 'prioritaires'), 'detection_errors' => $ints($al, 'erreurs_detection'),
            'confirmed_anomalies' => $ints($al, 'anomalies_confirmees'), 'tracking_errors' => $tracksLost + $ints($ex, 'pertes'),
            'sales_tickets' => $ints($sales, 'tickets'), 'sales_articles' => (float) ($sales['articles'] ?? 0),
            'monitored_seconds' => array_sum(array_column($cams, 'monitored_seconds')),
            'unusable_seconds' => array_sum(array_column($cams, 'unusable_seconds')),
            'cameras' => $cams,
        ];
    }

    public static function byProduct(int $storeId, string $from, string $to, array $filters = []): array
    {
        $p = ['s' => $storeId, 'f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
        $extra = '';
        if (!empty($filters['product_id'])) {
            $extra = ' AND p.id = :pid';
            $p['pid'] = (int) $filters['product_id'];
        }
        $cam = !empty($filters['camera_id']) ? (int) $filters['camera_id'] : null;
        $camSql = $cam ? ' AND camera_id = ' . $cam : '';
        $rows = Database::fetchAll("SELECT p.id, p.name, c.name category,
              (SELECT COUNT(*) FROM detection_results d WHERE d.product_id = p.id AND d.is_test = 0 AND d.detected_at BETWEEN :f1 AND :t1{$camSql}) detections,
              (SELECT COUNT(*) FROM exit_events e WHERE e.product_id = p.id AND e.direction='sortie' AND e.occurred_at BETWEEN :f2 AND :t2{$camSql}) exits,
              (SELECT COUNT(*) FROM exit_events e WHERE e.product_id = p.id AND e.direction='entree' AND e.occurred_at BETWEEN :f3 AND :t3{$camSql}) entries,
              (SELECT COUNT(*) FROM alerts a WHERE a.product_id = p.id AND a.level <> 'information' AND a.occurred_at BETWEEN :f4 AND :t4{$camSql}) to_verify,
              (SELECT COALESCE(SUM(si.quantity - si.returned_quantity),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                 WHERE si.product_id = p.id AND s.status <> 'annulee' AND s.sold_at BETWEEN :f5 AND :t5) sold,
              (SELECT COUNT(*) FROM exit_events e WHERE e.product_id = p.id AND e.reconciliation_status = 'vente_trouvee' AND e.occurred_at BETWEEN :f6 AND :t6{$camSql}) matched,
              (SELECT COUNT(*) FROM exit_events e WHERE e.product_id = p.id AND e.direction='sortie' AND e.reconciliation_status IN ('aucune_vente','incertain') AND e.occurred_at BETWEEN :f7 AND :t7{$camSql}) unmatched
            FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE p.store_id = :s AND p.deleted_at IS NULL{$extra} ORDER BY p.name",
            array_diff_key($p, ['f' => 1, 't' => 1]) + self::numbered($p['f'], $p['t'], 7));
        return array_values(array_filter($rows, static fn ($r) => $r['detections'] || $r['exits'] || $r['entries'] || $r['sold'] || !empty($filters['product_id'])));
    }

    private static function numbered(string $f, string $t, int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out['f' . $i] = $f;
            $out['t' . $i] = $t;
        }
        return $out;
    }

    /**
     * Durée de surveillance par caméra reconstituée depuis camera_events :
     * analyse active ET flux disponible = surveillé ; analyse active ET flux absent = sans vidéo exploitable.
     */
    public static function cameraUptime(int $storeId, string $from, string $to): array
    {
        $out = [];
        $start = strtotime($from);
        $end = min(strtotime($to), time());
        foreach (Database::fetchAll('SELECT id, name, status, analysis_enabled FROM cameras WHERE store_id = :s AND is_active = 1', ['s' => $storeId]) as $cam) {
            $prev = Database::fetchAll('SELECT event_type FROM camera_events WHERE camera_id = :c AND occurred_at < :f ORDER BY occurred_at DESC, id DESC LIMIT 20',
                ['c' => $cam['id'], 'f' => $from]);
            $analysing = false;
            $connected = false;
            foreach (array_reverse($prev) as $e) {
                [$analysing, $connected] = self::apply($e['event_type'], $analysing, $connected);
            }
            $events = Database::fetchAll('SELECT event_type, occurred_at FROM camera_events WHERE camera_id = :c AND occurred_at BETWEEN :f AND :t ORDER BY occurred_at, id',
                ['c' => $cam['id'], 'f' => $from, 't' => $to]);
            $mon = $unus = 0;
            $cursor = $start;
            $gaps = [];
            foreach ($events as $e) {
                $ts = (int) strtotime((string) $e['occurred_at']);
                if ($analysing && $connected) {
                    $mon += max(0, $ts - $cursor);
                } elseif ($analysing) {
                    $unus += max(0, $ts - $cursor);
                    $gaps[] = [date('H:i', $cursor), date('H:i', $ts)];
                }
                [$analysing, $connected] = self::apply($e['event_type'], $analysing, $connected);
                $cursor = $ts;
            }
            if ($end > $cursor) {
                if ($analysing && $connected) {
                    $mon += $end - $cursor;
                } elseif ($analysing) {
                    $unus += $end - $cursor;
                    $gaps[] = [date('H:i', $cursor), date('H:i', $end)];
                }
            }
            $out[] = ['camera_id' => (int) $cam['id'], 'name' => $cam['name'], 'status' => $cam['status'],
                'monitored_seconds' => $mon, 'unusable_seconds' => $unus, 'gaps' => array_slice($gaps, 0, 20),
                'disconnections' => count(array_filter($events, static fn ($e) => $e['event_type'] === 'deconnexion'))];
        }
        return $out;
    }

    private static function apply(string $type, bool $analysing, bool $connected): array
    {
        return match ($type) {
            'analyse_demarree' => [true, true],
            'analyse_arretee' => [false, $connected],
            'connexion', 'reconnexion' => [$analysing, true],
            'deconnexion', 'flux_inutilisable', 'erreur' => [$analysing, false],
            default => [$analysing, $connected],
        };
    }

    public static function hourly(int $storeId, string $date): array
    {
        $rows = Database::fetchAll("SELECT HOUR(occurred_at) h, SUM(direction='sortie') exits, SUM(direction='entree') entries, COUNT(*) total
            FROM exit_events WHERE store_id = :s AND DATE(occurred_at) = :d GROUP BY HOUR(occurred_at)", ['s' => $storeId, 'd' => $date]);
        $out = array_fill(0, 24, ['exits' => 0, 'entries' => 0, 'total' => 0]);
        foreach ($rows as $r) {
            $out[(int) $r['h']] = ['exits' => (int) $r['exits'], 'entries' => (int) $r['entries'], 'total' => (int) $r['total']];
        }
        return $out;
    }

    public static function daily(int $storeId, string $from, string $to): array
    {
        $rows = Database::fetchAll("SELECT DATE(occurred_at) d, SUM(direction='sortie') exits, SUM(direction='entree') entries,
              SUM(direction='sortie' AND reconciliation_status IN ('aucune_vente','incertain')) unmatched
            FROM exit_events WHERE store_id = :s AND occurred_at BETWEEN :f AND :t GROUP BY DATE(occurred_at) ORDER BY d",
            ['s' => $storeId, 'f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = $r;
        }
        $out = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $k = date('Y-m-d', $d);
            $out[] = ['date' => $k, 'exits' => (int) ($map[$k]['exits'] ?? 0), 'entries' => (int) ($map[$k]['entries'] ?? 0), 'unmatched' => (int) ($map[$k]['unmatched'] ?? 0)];
        }
        return $out;
    }

    /** Génère (ou régénère) et stocke le rapport journalier. */
    public static function generateDaily(int $storeId, string $date, string $by = 'automatique'): int
    {
        $summary = self::summary($storeId, $date, $date);
        $cams = $summary['cameras'];
        unset($summary['cameras']);
        $summary['hourly'] = self::hourly($storeId, $date);
        $summary['disclaimer'] = self::DISCLAIMER;
        $data = ['summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'products_json' => json_encode(self::byProduct($storeId, $date, $date), JSON_UNESCAPED_UNICODE),
            'cameras_json' => json_encode($cams, JSON_UNESCAPED_UNICODE), 'generated_by' => $by, 'generated_at' => date('Y-m-d H:i:s')];
        $existing = Database::value('SELECT id FROM daily_reports WHERE store_id = :s AND report_date = :d', ['s' => $storeId, 'd' => $date]);
        if ($existing) {
            Database::update('daily_reports', $data, 'id = :id', ['id' => $existing]);
            return (int) $existing;
        }
        return Database::insert('daily_reports', $data + ['store_id' => $storeId, 'report_date' => $date]);
    }

    public static function duration(int $seconds): string
    {
        return sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
