<?php
/**
 * Statistiques du tableau de bord, calculées en temps réel depuis MySQL.
 */
declare(strict_types=1);

final class StatsService
{
    public static function compute(array $src): array
    {
        $f = ParcelQuery::filtersFrom($src);
        [$where, $params] = ParcelQuery::where($f);
        $one = static fn (string $extra = '') => (int) db_value("SELECT COUNT(*) FROM parcels p WHERE $where $extra", $params);

        $kpi = [
            'total' => $one(),
            'verified' => $one("AND p.verification_status = 'verifiee'"),
            'pending' => $one("AND p.verification_status IN ('soumise','en_verification')"),
            'anomalies' => $one("AND p.verification_status = 'anomalie'"),
            'no_owner' => $one('AND NOT EXISTS (SELECT 1 FROM parcel_owners po WHERE po.parcel_id = p.id AND po.valid_to IS NULL)'),
            'incomplete' => $one("AND (NOT EXISTS (SELECT 1 FROM parcel_owners po WHERE po.parcel_id = p.id AND po.valid_to IS NULL)
                                  OR NOT EXISTS (SELECT 1 FROM documents d WHERE d.parcel_id = p.id AND d.is_archived = 0)
                                  OR p.administrative_area_id IS NULL)"),
            'overlaps' => (int) db_value("SELECT COUNT(*) FROM parcel_overlaps o JOIN parcels p ON p.id = o.parcel_a_id
                                          WHERE o.status = 'a_verifier' AND $where", $params),
            'total_area_m2' => (float) db_value("SELECT COALESCE(SUM(p.area_m2), 0) FROM parcels p WHERE $where", $params),
            'owners' => (int) db_value('SELECT COUNT(*) FROM owners WHERE is_archived = 0'),
            'demo' => $one('AND p.is_demo = 1'),
            'documents' => (int) db_value('SELECT COUNT(*) FROM documents WHERE is_archived = 0'),
        ];

        $byArea = db_all("SELECT COALESCE(a.name, 'Zone non renseignée') AS label, COUNT(*) AS n, SUM(p.area_m2) AS area
                          FROM parcels p LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id
                          WHERE $where GROUP BY a.id, a.name ORDER BY n DESC LIMIT 15", $params);
        $byCategory = db_all("SELECT p.category AS k, COUNT(*) AS n FROM parcels p WHERE $where GROUP BY p.category ORDER BY n DESC", $params);
        $byStatus = db_all("SELECT p.verification_status AS k, COUNT(*) AS n FROM parcels p WHERE $where GROUP BY p.verification_status", $params);
        $bySurvey = db_all("SELECT p.survey_level AS k, COUNT(*) AS n FROM parcels p WHERE $where GROUP BY p.survey_level", $params);

        // Évolution mensuelle (12 derniers mois ou période filtrée)
        $from = $f['date_from'] ?? date('Y-m-01', strtotime('-11 months'));
        $evo = db_all("SELECT DATE_FORMAT(p.created_at, '%Y-%m') AS m, COUNT(*) AS n FROM parcels p
                       WHERE $where AND p.created_at >= ? GROUP BY m ORDER BY m", array_merge($params, [$from . ' 00:00:00']));
        $months = [];
        $cursor = new DateTime(substr($from, 0, 7) . '-01');
        $end = new DateTime(($f['date_to'] ?? date('Y-m-d')));
        $guard = 0;
        while ($cursor <= $end && $guard++ < 60) {
            $months[$cursor->format('Y-m')] = 0;
            $cursor->modify('+1 month');
        }
        foreach ($evo as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']] = (int) $r['n'];
            }
        }

        $recent = db_all('SELECT h.action_type, h.created_at, p.id AS parcel_id, p.parcel_reference, u.full_name
                          FROM parcel_history h JOIN parcels p ON p.id = h.parcel_id LEFT JOIN users u ON u.id = h.user_id
                          ORDER BY h.id DESC LIMIT 10');

        return [
            'kpi' => $kpi,
            'by_area' => array_map(static fn ($r) => ['label' => $r['label'], 'n' => (int) $r['n'], 'area' => (float) $r['area']], $byArea),
            'by_category' => array_map(static fn ($r) => ['label' => label('category', $r['k']), 'n' => (int) $r['n']], $byCategory),
            'by_status' => array_map(static fn ($r) => ['key' => $r['k'], 'label' => label('verification_status', $r['k']), 'n' => (int) $r['n']], $byStatus),
            'by_survey' => array_map(static fn ($r) => ['label' => label('survey_level', $r['k']), 'n' => (int) $r['n']], $bySurvey),
            'evolution' => ['labels' => array_keys($months), 'values' => array_values($months)],
            'recent' => array_map(static fn ($r) => ['action' => label('history_action', $r['action_type']), 'date' => $r['created_at'],
                'parcel_id' => (int) $r['parcel_id'], 'ref' => $r['parcel_reference'], 'user' => $r['full_name'] ?? 'Système'], $recent),
        ];
    }
}
