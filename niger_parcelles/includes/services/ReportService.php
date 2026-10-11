<?php
/**
 * Rapports de gestion (affichage, CSV, Excel, PDF). Les colonnes contenant
 * des données personnelles ne sont incluses que pour les profils autorisés.
 */
declare(strict_types=1);

final class ReportService
{
    public const REPORTS = [
        'parcelles_commune'    => 'Liste des parcelles par commune / zone',
        'non_verifiees'        => 'Parcelles non vérifiées',
        'documents_manquants'  => 'Dossiers incomplets et documents manquants',
        'chevauchements'       => 'Chevauchements potentiels',
        'mutations'            => 'Mutations enregistrées',
        'statistiques'         => 'Statistiques par période et par localité',
        'imports'              => 'Historique des importations',
    ];

    public const MAX_ROWS = 10000;

    public static function run(string $key, array $src): array
    {
        if (!isset(self::REPORTS[$key])) {
            throw new UserFacingException('Rapport inconnu.', 404);
        }
        $f = ParcelQuery::filtersFrom($src);
        $f['q'] = null;
        [$where, $params] = ParcelQuery::where($f);
        $lim = ' LIMIT ' . self::MAX_ROWS;
        $title = self::REPORTS[$key];
        $desc = '';

        switch ($key) {
            case 'parcelles_commune':
                $cols = ['Zone', 'Référence', 'Réf. cadastrale', 'Catégorie', 'Superficie (m²)', 'Statut', 'Délimitation', 'Nb propriétaires', 'Créée le'];
                $rows = array_map(static fn ($r) => [$r['area_name'] ?? 'Non renseignée', $r['parcel_reference'], $r['cadastral_reference'] ?? '',
                    label('category', $r['category']), (float) $r['area_m2'], label('verification_status', $r['verification_status']),
                    label('survey_level', $r['survey_level']), (int) $r['n_owners'], fmt_date($r['created_at'])],
                    db_all("SELECT p.*, a.name AS area_name, (SELECT COUNT(*) FROM parcel_owners po WHERE po.parcel_id = p.id AND po.valid_to IS NULL) AS n_owners
                            FROM parcels p LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id
                            WHERE $where ORDER BY a.name, p.parcel_reference $lim", $params));
                break;

            case 'non_verifiees':
                $cols = ['Référence', 'Zone', 'Statut', 'Délimitation', 'Superficie (m²)', 'Agent', 'Créée le', 'Dernière modification'];
                $rows = array_map(static fn ($r) => [$r['parcel_reference'], $r['area_name'] ?? '', label('verification_status', $r['verification_status']),
                    label('survey_level', $r['survey_level']), (float) $r['area_m2'], $r['agent'] ?? '', fmt_date($r['created_at']), fmt_date($r['updated_at'])],
                    db_all("SELECT p.*, a.name AS area_name, u.full_name AS agent FROM parcels p
                            LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id LEFT JOIN users u ON u.id = p.created_by
                            WHERE $where AND p.verification_status <> 'verifiee' ORDER BY p.created_at $lim", $params));
                break;

            case 'documents_manquants':
                $desc = 'Parcelles sans propriétaire en cours, sans document justificatif actif ou sans zone administrative.';
                $cols = ['Référence', 'Zone', 'Statut', 'Sans propriétaire', 'Sans document', 'Sans zone', 'Situation déclarée'];
                $rows = array_map(static fn ($r) => [$r['parcel_reference'], $r['area_name'] ?? '', label('verification_status', $r['verification_status']),
                    $r['n_owners'] ? '' : 'OUI', $r['n_docs'] ? '' : 'OUI', $r['administrative_area_id'] ? '' : 'OUI', label('declared_status', $r['declared_status'])],
                    db_all("SELECT * FROM (SELECT p.*, a.name AS area_name,
                                (SELECT COUNT(*) FROM parcel_owners po WHERE po.parcel_id = p.id AND po.valid_to IS NULL) AS n_owners,
                                (SELECT COUNT(*) FROM documents d WHERE d.parcel_id = p.id AND d.is_archived = 0) AS n_docs
                            FROM parcels p LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id WHERE $where) t
                            WHERE n_owners = 0 OR n_docs = 0 OR administrative_area_id IS NULL ORDER BY parcel_reference $lim", $params));
                break;

            case 'chevauchements':
                $status = in_array($src['overlap_status'] ?? '', array_keys(labels('overlap_status')), true) ? $src['overlap_status'] : 'a_verifier';
                $desc = 'Statut : ' . label('overlap_status', $status) . '. Une superposition peut être légitime (copropriété, servitude).';
                $cols = ['Parcelle A', 'Parcelle B', 'Type', 'Statut', 'Détecté le', 'Décision', 'Notes'];
                $rows = array_map(static fn ($r) => [$r['ref_a'], $r['ref_b'], label('overlap_type', $r['overlap_type']), label('overlap_status', $r['status']),
                    fmt_date($r['detected_at'], true), $r['resolver'] ? $r['resolver'] . ' (' . fmt_date($r['resolved_at']) . ')' : '', $r['notes'] ?? ''],
                    db_all("SELECT o.*, pa.parcel_reference AS ref_a, pb.parcel_reference AS ref_b, u.full_name AS resolver
                            FROM parcel_overlaps o JOIN parcels pa ON pa.id = o.parcel_a_id JOIN parcels pb ON pb.id = o.parcel_b_id
                            LEFT JOIN users u ON u.id = o.resolved_by WHERE o.status = ? ORDER BY o.detected_at DESC $lim", [$status]));
                break;

            case 'mutations':
                $w = [];
                $p = [];
                if ($f['date_from']) { $w[] = 'm.mutation_date >= ?'; $p[] = $f['date_from']; }
                if ($f['date_to']) { $w[] = 'm.mutation_date <= ?'; $p[] = $f['date_to']; }
                $sqlW = $w ? 'WHERE ' . implode(' AND ', $w) : '';
                $personal = can('owner.view_personal');
                $cols = array_merge(['Date', 'Parcelle', 'Type', 'Réf. acte'], $personal ? ['Anciens propriétaires', 'Nouveaux propriétaires'] : [], ['Saisie par']);
                $rows = [];
                foreach (db_all("SELECT m.*, p.parcel_reference, u.full_name FROM mutations m JOIN parcels p ON p.id = m.parcel_id
                                 LEFT JOIN users u ON u.id = m.created_by $sqlW ORDER BY m.mutation_date DESC $lim", $p) as $m) {
                    $row = [fmt_date($m['mutation_date']), $m['parcel_reference'], label('mutation_type', $m['mutation_type']), $m['document_reference'] ?? ''];
                    if ($personal) {
                        $names = static fn (string $col) => implode(', ', array_map('owner_display_name', db_all(
                            "SELECT o.* FROM parcel_owners po JOIN owners o ON o.id = po.owner_id WHERE po.$col = ?", [$m['id']])));
                        $row[] = $names('mutation_out_id');
                        $row[] = $names('mutation_in_id');
                    }
                    $row[] = $m['full_name'] ?? '';
                    $rows[] = $row;
                }
                break;

            case 'statistiques':
                $cols = ['Mois', 'Zone', 'Parcelles créées', 'Superficie totale (m²)', 'Vérifiées', 'Avec chevauchement'];
                $rows = array_map(static fn ($r) => [$r['m'], $r['area_name'] ?? 'Non renseignée', (int) $r['n'], (float) $r['area'], (int) $r['verified'], (int) $r['overlaps']],
                    db_all("SELECT DATE_FORMAT(p.created_at, '%Y-%m') AS m, a.name AS area_name, COUNT(*) AS n, ROUND(SUM(p.area_m2), 2) AS area,
                                   SUM(p.verification_status = 'verifiee') AS verified, SUM(p.has_overlap_warning) AS overlaps
                            FROM parcels p LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id
                            WHERE $where GROUP BY m, a.id, a.name ORDER BY m DESC, a.name $lim", $params));
                break;

            case 'imports':
                $cols = ['Date', 'Fichier', 'Format', 'Système source', 'Entités', 'Importées', 'Erreurs', 'Avertissements', 'Par'];
                $rows = array_map(static fn ($r) => [fmt_date($r['created_at'], true), $r['filename'], $r['format'], $r['source_crs'], (int) $r['feature_count'],
                    (int) $r['imported_count'], (int) $r['error_count'], (int) $r['warning_count'], $r['full_name'] ?? ''],
                    db_all("SELECT b.*, u.full_name FROM import_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC $lim"));
                break;
        }
        return ['key' => $key, 'title' => $title, 'description' => $desc, 'columns' => $cols, 'rows' => $rows,
                'truncated' => count($rows) >= self::MAX_ROWS];
    }
}
